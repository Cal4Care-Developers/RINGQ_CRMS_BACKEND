/*
 * mod_ringq - one push trigger for every way an extension rings, without adding a call leg.
 *
 * Endpoint "ringq/<ext>@<domain>". directory.lua (ringq_ring_dial.lua) sets an extension's
 * dial-string to it when the tenant's ringq/ring_push_mode is "wake", and keeps the original
 * dial-string in the user param "ringq-orig-dial-string". Whatever routes a call to the extension
 * (local_extension, mod_callcenter, IVR, ring group, transfer, follow-me) ends in user/<ext>, then
 * here:
 *
 *   1. fire CUSTOM ringq::ring (ext, domain, ring id, caller, queue). node/ringPush.js pushes the
 *      extension's mobile apps and answers in hash ringq_ring/<ring id> with the number pushed.
 *   2. if an iPhone was pushed, wait (ringq_ring_wake_wait_ms, default 15000) until the RingQ iOS
 *      app itself has registered: another device of the extension (web dialer, desk phone) being
 *      registered must not send the INVITE out before the woken iPhone can receive it. Otherwise,
 *      if nothing is registered and something was pushed, wait for any registration. Either way
 *      stop early if the caller goes away. With a desk phone of the extension online, the wait for
 *      the app is at most ringq_ring_wake_wait_desk_ms (default 3000), so the desk phone isn't held
 *      silent by a slow phone.
 *   3. originate the original dial-string exactly like the user/ endpoint does, with the ring id as
 *      the SIP Call-ID so the app matches the INVITE to the pushed call, and hand the real device
 *      leg back. No loopback: call records, journeys, presence and transfers see a normal call.
 *   4. fire CUSTOM ringq::ring_end (ring id, cause, answered, registered) for the stop push.
 *
 * In a forked dial (several destinations in one bridge, e.g. a simultaneous ring group, or Parallel
 * Ringing's "user/<ext>,loopback/<mobile>") it does not wait, so it never holds the other destinations
 * back; the push still goes out. We are always reached through user/, which drops SOF_FORKED_DIAL and
 * passes SOF_NOBLOCK instead when its own dial is forked, so either flag means forked.
 */
#include <switch.h>

SWITCH_MODULE_LOAD_FUNCTION(mod_ringq_load);
SWITCH_MODULE_DEFINITION(mod_ringq, mod_ringq_load, NULL, NULL);

static switch_endpoint_interface_t *ringq_endpoint_interface = NULL;

#define RINGQ_DEFAULT_WAIT_MS 15000
#define RINGQ_POLL_MS 250
/* with some pushed iPhones up, how long (from the start of the ring) the others get to wake:
   a woken iPhone takes 2-5s to register */
#define RINGQ_ALL_IOS_MS 8000
/* with a desk phone (any non-RingQ SIP device) of the extension online, the most it waits for the
   pushed app before ringing everything (ringq_ring_wake_wait_desk_ms): a slow phone must not keep
   a desk phone that could answer now silent for up to RINGQ_DEFAULT_WAIT_MS */
#define RINGQ_DESK_WAIT_MS 3000

static switch_bool_t ringq_registered(const char *dialed_user, const char *domain)
{
	switch_stream_handle_t stream = { 0 };
	char *arg = switch_mprintf("*/%s@%s", dialed_user, domain);
	switch_bool_t yes = SWITCH_FALSE;

	SWITCH_STANDARD_STREAM(stream);
	if (switch_api_execute("sofia_contact", arg, NULL, &stream) == SWITCH_STATUS_SUCCESS && stream.data) {
		const char *c = (const char *) stream.data;
		yes = (!zstr(c) && !strstr(c, "error/")) ? SWITCH_TRUE : SWITCH_FALSE;
	}
	switch_safe_free(stream.data);
	switch_safe_free(arg);
	return yes;
}

/* the user's current contacts (sofia_contact), to notice a new registration cheaply */
static char *ringq_contacts(const char *dialed_user, const char *domain)
{
	switch_stream_handle_t stream = { 0 };
	char *arg = switch_mprintf("*/%s@%s", dialed_user, domain);
	char *out = NULL;

	SWITCH_STANDARD_STREAM(stream);
	if (switch_api_execute("sofia_contact", arg, NULL, &stream) == SWITCH_STATUS_SUCCESS && stream.data) {
		out = strdup((const char *) stream.data);
	}
	switch_safe_free(stream.data);
	switch_safe_free(arg);
	return out ? out : strdup("");
}

/* how many RingQ mobile app registrations (User-Agent "RingQ iOS - <device>" or
   "RingQ android - <device>") this user has; a woken app registers on the push, so these are the
   pushed phones coming back. *others (if given) gets the user's desk phones and other SIP phones
   (not RingQ's own web or desktop dialers). */
static int ringq_ios_registered(const char *profile, const char *dialed_user, const char *domain, int *others)
{
	switch_stream_handle_t stream = { 0 };
	/* sofia's "reg <text>" search only matches the Contact, and the app's Contact is a random id,
	   so list the profile and match the User line here */
	char *arg = switch_mprintf("status profile %s reg", profile);
	char *user = switch_mprintf("%s@%s", dialed_user, domain);
	int n = 0;

	if (others) *others = 0;
	SWITCH_STANDARD_STREAM(stream);
	if (switch_api_execute("sofia", arg, NULL, &stream) == SWITCH_STATUS_SUCCESS && stream.data) {
		char *buf = strdup((const char *) stream.data), *line, *save = NULL;
		switch_bool_t mine = SWITCH_FALSE;
		for (line = strtok_r(buf, "\n", &save); line; line = strtok_r(NULL, "\n", &save)) {
			if (!strncmp(line, "User:", 5)) {
				const char *v = line + 5;
				while (*v == ' ' || *v == '\t') v++;
				mine = !strncasecmp(v, user, strlen(user)) && (v[strlen(user)] == '\0' || v[strlen(user)] == '\r'
						|| v[strlen(user)] == ' ') ? SWITCH_TRUE : SWITCH_FALSE;
			} else if (mine && !strncmp(line, "Agent:", 6)) {
				if (strstr(line, "RingQ iOS") || strstr(line, "RingQ android")) n++;
				/* desk phones and other SIP phones; RingQ's own web and desktop dialers keep the
				   full wait, as before, so an open browser tab doesn't cut the mobile app out */
				else if (others && !strstr(line, "RingQ")) (*others)++;
				mine = SWITCH_FALSE;
			}
		}
		free(buf);
	}
	switch_safe_free(stream.data);
	switch_safe_free(arg);
	switch_safe_free(user);
	return n;
}

/* hash ringq_ring/<id>: node's answer "<devices pushed>/<iPhones pushed>"; -1 = no answer yet */
static int ringq_pushed(const char *ring_id, int *ios)
{
	switch_stream_handle_t stream = { 0 };
	char *arg = switch_mprintf("select/ringq_ring/%s", ring_id);
	int n = -1;

	SWITCH_STANDARD_STREAM(stream);
	if (switch_api_execute("hash", arg, NULL, &stream) == SWITCH_STATUS_SUCCESS && stream.data) {
		const char *v = (const char *) stream.data;
		if (!zstr(v) && strncmp(v, "-ERR", 4)) {
			const char *slash = strchr(v, '/');
			n = atoi(v);
			if (ios) *ios = slash ? atoi(slash + 1) : 0;
		}
	}
	switch_safe_free(stream.data);
	switch_safe_free(arg);
	return n;
}

static void ringq_hash_delete(const char *ring_id)
{
	switch_stream_handle_t stream = { 0 };
	char *arg = switch_mprintf("delete/ringq_ring/%s", ring_id);
	SWITCH_STANDARD_STREAM(stream);
	switch_api_execute("hash", arg, NULL, &stream);
	switch_safe_free(stream.data);
	switch_safe_free(arg);
}

static switch_bool_t ringq_should_stop(switch_core_session_t *session, switch_call_cause_t *cancel_cause)
{
	if (cancel_cause && *cancel_cause > 0) return SWITCH_TRUE;
	if (session && !switch_channel_up(switch_core_session_get_channel(session))) return SWITCH_TRUE;
	return SWITCH_FALSE;
}

static const char *ringq_var(switch_core_session_t *session, switch_event_t *var_event, const char *name)
{
	const char *v = NULL;
	if (var_event) v = switch_event_get_header(var_event, name);
	if (zstr(v) && session) v = switch_channel_get_variable(switch_core_session_get_channel(session), name);
	return v;
}

static void ringq_fire(const char *subclass, const char *ext, const char *domain, const char *ring_id,
					   const char *k1, const char *v1, const char *k2, const char *v2, const char *k3, const char *v3,
					   const char *k4, const char *v4, const char *k5, const char *v5, const char *k6, const char *v6)
{
	switch_event_t *event = NULL;
	if (switch_event_create_subclass(&event, SWITCH_EVENT_CUSTOM, subclass) != SWITCH_STATUS_SUCCESS) return;
	switch_event_add_header_string(event, SWITCH_STACK_BOTTOM, "ringq-ext", ext);
	switch_event_add_header_string(event, SWITCH_STACK_BOTTOM, "ringq-domain", domain);
	switch_event_add_header_string(event, SWITCH_STACK_BOTTOM, "ringq-ring-id", ring_id);
	if (k1) switch_event_add_header_string(event, SWITCH_STACK_BOTTOM, k1, v1 ? v1 : "");
	if (k2) switch_event_add_header_string(event, SWITCH_STACK_BOTTOM, k2, v2 ? v2 : "");
	if (k3) switch_event_add_header_string(event, SWITCH_STACK_BOTTOM, k3, v3 ? v3 : "");
	if (k4) switch_event_add_header_string(event, SWITCH_STACK_BOTTOM, k4, v4 ? v4 : "");
	if (k5) switch_event_add_header_string(event, SWITCH_STACK_BOTTOM, k5, v5 ? v5 : "");
	if (k6) switch_event_add_header_string(event, SWITCH_STACK_BOTTOM, k6, v6 ? v6 : "");
	switch_event_fire(&event);
}

/* One ringback for every caller, the whole time an extension rings: a double "ring-ring" (two
   0.4 s rings 0.2 s apart, then 2 s quiet, 400+450 Hz). Played here while the phone wakes and set
   as the call's ringback for once it rings, so the caller hears one ring the whole time (the web
   app plays the same until the PBX's early media starts). A channel variable ringq_ringback
   (tone_stream form) overrides it. */
#define RINGQ_RINGBACK "%(400,200,400,450);%(400,2000,400,450)"

static const char *ringq_ringback(switch_core_session_t *session, switch_event_t *var_event)
{
	const char *v = NULL;
	if (var_event) v = switch_event_get_header(var_event, "ringq_ringback");
	if (zstr(v) && session) v = switch_channel_get_variable(switch_core_session_get_channel(session), "ringq_ringback");
	return zstr(v) ? RINGQ_RINGBACK : v;
}

/* The wake wait. The bridge that dialed us is blocked in this call, so nothing else plays
   ringback to the caller: give the caller early media and play the ringback ourselves as ONE
   continuous playback (no restarts, so no clicks), checking every RINGQ_POLL_MS from the
   playback's per-frame callback whether to stop. Without a caller session or media
   (mod_callcenter's agent originate: the caller hears the queue's music) just poll. */
typedef switch_bool_t (*ringq_done_fn)(void *ctx, int waited);

typedef struct {
	ringq_done_fn done;
	void *ctx;
	switch_time_t start, next_check;
	int *waited;
	switch_bool_t finished;
} ringq_wait_t;

static switch_status_t ringq_wait_frame(switch_core_session_t *session, switch_frame_t *frame, void *user_data)
{
	ringq_wait_t *w = (ringq_wait_t *) user_data;
	switch_time_t now = switch_micro_time_now();
	if (now < w->next_check) return SWITCH_STATUS_SUCCESS;
	w->next_check = now + RINGQ_POLL_MS * 1000;
	*w->waited = (int) ((now - w->start) / 1000);
	if (w->done(w->ctx, *w->waited)) {
		w->finished = SWITCH_TRUE;
		return SWITCH_STATUS_BREAK;
	}
	return SWITCH_STATUS_SUCCESS;
}

/* one cycle of a tone_stream ringback, in ms (sum of every segment's on + off) */
static int ringq_cycle_ms(const char *ringback)
{
	const char *p = ringback;
	int total = 0, on, off;
	while (p && (p = strchr(p, '('))) {
		if (sscanf(p, "(%d,%d", &on, &off) == 2 && on >= 0 && off >= 0) total += on + off;
		p++;
	}
	return total > 0 ? total : 3000;
}

static void ringq_wait(switch_core_session_t *session, const char *ringback, int *waited, int wait_ms, ringq_done_fn done, void *ctx)
{
	switch_channel_t *channel = session ? switch_core_session_get_channel(session) : NULL;
	ringq_wait_t w = { 0 };

	w.done = done;
	w.ctx = ctx;
	w.start = switch_micro_time_now() - (switch_time_t) (*waited) * 1000;
	w.waited = waited;
	if (done(ctx, *waited)) return;

	if (channel && switch_channel_ready(channel)) {
		if (!switch_channel_test_flag(channel, CF_EARLY_MEDIA) && !switch_channel_test_flag(channel, CF_ANSWERED)) {
			switch_channel_pre_answer(channel);
		}
		if (switch_channel_media_ready(channel)) {
			switch_input_args_t args = { 0 };
			/* enough loops to cover the rest of the wait, never endless: the wait still ends on
			   time if the per-frame check didn't run */
			int cycle = ringq_cycle_ms(ringback), left = wait_ms - *waited;
			char *tone = switch_mprintf("tone_stream://%s;loops=%d", ringback, left > 0 ? left / cycle + 1 : 1);
			args.read_frame_callback = ringq_wait_frame;
			args.user_data = &w;
			w.next_check = switch_micro_time_now() + RINGQ_POLL_MS * 1000;
			/* returns when ringq_wait_frame says done, or the caller goes away */
			switch_ivr_play_file(session, NULL, tone, &args);
			switch_safe_free(tone);
		}
	}
	while (!w.finished && !done(ctx, *waited)) {
		switch_yield(RINGQ_POLL_MS * 1000);
		*waited = (int) ((switch_micro_time_now() - w.start) / 1000);
	}
}

/* [queue] mod_callcenter dials each agent separately and has no caller session on these dials.
   Its member (the waiting caller) bridged to another agent, or gone, means this agent lost the
   call: stop waiting for this agent's phone, don't ring it, and hang up its ringing legs. Only
   used on queue agent dials (no caller session + cc_member_session_uuid); every other call is
   untouched. */
static switch_bool_t ringq_member_taken(const char *member_uuid)
{
	switch_core_session_t *member;
	switch_bool_t taken = SWITCH_TRUE;

	if (zstr(member_uuid)) return SWITCH_FALSE;
	if ((member = switch_core_session_locate(member_uuid))) {
		switch_channel_t *channel = switch_core_session_get_channel(member);
		taken = (!switch_channel_up(channel) || switch_channel_test_flag(channel, CF_BRIDGED)) ? SWITCH_TRUE : SWITCH_FALSE;
		switch_core_session_rwunlock(member);
	}
	return taken;
}

/* While this agent's device legs ring: when the member is taken, hang up exactly these legs (they
   carry ringq_ring_id) with LOSE_RACE. The originate then ends by itself; nothing in it is
   changed (an earlier version swapped the originate's cancel cause and broke audio). */
typedef struct {
	char member_uuid[SWITCH_UUID_FORMATTED_LENGTH + 1];
	char ring_id[SWITCH_UUID_FORMATTED_LENGTH + 1];
	volatile int done;
} ringq_member_watch_t;

static void *SWITCH_THREAD_FUNC ringq_member_watch_run(switch_thread_t *thread, void *obj)
{
	ringq_member_watch_t *w = (ringq_member_watch_t *) obj;
	while (!w->done) {
		if (ringq_member_taken(w->member_uuid)) {
			switch_stream_handle_t stream = { 0 };
			char *arg = switch_mprintf("LOSE_RACE ringq_ring_id %s", w->ring_id);
			SWITCH_STANDARD_STREAM(stream);
			switch_api_execute("hupall", arg, NULL, &stream);
			switch_safe_free(stream.data);
			switch_safe_free(arg);
			switch_log_printf(SWITCH_CHANNEL_LOG, SWITCH_LOG_INFO, "[ringq] queue call taken by another agent: ring %s stopped\n", w->ring_id);
			break;
		}
		switch_yield(RINGQ_POLL_MS * 1000);
	}
	return NULL;
}

/* "done waiting?" for the two waits in ringq_outgoing_channel */
typedef struct {
	switch_core_session_t *session;
	switch_call_cause_t *cancel_cause;
	int wait_ms, ios_pushed, ios_up;
	char *seen;
	const char *profile, *dialed_user, *domain;
	const char *member_uuid;
} ringq_ios_wait_t;

static switch_bool_t ringq_ios_done(void *ctx, int waited)
{
	ringq_ios_wait_t *c = (ringq_ios_wait_t *) ctx;
	char *now;
	if (waited >= c->wait_ms || ringq_should_stop(c->session, c->cancel_cause) || ringq_member_taken(c->member_uuid)) return SWITCH_TRUE;
	/* once one pushed iPhone is up, the rest get until RINGQ_ALL_IOS_MS */
	if (c->ios_up >= c->ios_pushed || (c->ios_up > 0 && waited >= RINGQ_ALL_IOS_MS)) return SWITCH_TRUE;
	/* the full registration listing is read again only when the user's contacts change */
	now = ringq_contacts(c->dialed_user, c->domain);
	if (strcmp(now, c->seen)) {
		free(c->seen);
		c->seen = now;
		c->ios_up = ringq_ios_registered(c->profile, c->dialed_user, c->domain, NULL);
	} else {
		free(now);
	}
	return c->ios_up >= c->ios_pushed ? SWITCH_TRUE : SWITCH_FALSE;
}

typedef struct {
	switch_core_session_t *session;
	switch_call_cause_t *cancel_cause;
	int wait_ms;
	switch_bool_t registered;
	const char *dialed_user, *domain;
	const char *member_uuid;
} ringq_reg_wait_t;

static switch_bool_t ringq_reg_done(void *ctx, int waited)
{
	ringq_reg_wait_t *c = (ringq_reg_wait_t *) ctx;
	if (waited >= c->wait_ms || ringq_should_stop(c->session, c->cancel_cause) || ringq_member_taken(c->member_uuid)) return SWITCH_TRUE;
	c->registered = ringq_registered(c->dialed_user, c->domain);
	return c->registered;
}

static switch_call_cause_t ringq_outgoing_channel(switch_core_session_t *session,
												  switch_event_t *var_event,
												  switch_caller_profile_t *outbound_profile,
												  switch_core_session_t **new_session, switch_memory_pool_t **pool,
												  switch_originate_flag_t flags, switch_call_cause_t *cancel_cause)
{
	switch_xml_t x_user = NULL, x_params, x_param;
	switch_event_t *params = NULL;
	char *ext = NULL, *domain = NULL, *d_dest = NULL, *full_dest = NULL;
	const char *dest = NULL, *dialed_user = NULL, *varval;
	switch_call_cause_t cause = SWITCH_CAUSE_NONE;
	unsigned int timelimit = SWITCH_DEFAULT_TIMEOUT;
	switch_originate_flag_t myflags = SOF_NONE;
	char ring_id[SWITCH_UUID_FORMATTED_LENGTH + 1] = "";
	char wait_s[32] = "", pushed_s[16] = "";
	const char *caller_num = "", *caller_name = "", *cc_queue = "", *a_uuid = "";
	char *queue_ext = NULL;
	switch_bool_t registered = SWITCH_FALSE, answered = SWITCH_FALSE;
	int wait_ms = RINGQ_DEFAULT_WAIT_MS, desk_wait_ms = RINGQ_DESK_WAIT_MS, pushed = -1, ios_pushed = 0, waited = 0;
	/* the legs of a forked dial are created one after another: blocking here (wake wait, or an
	   originate that waits for the answer) kept e.g. the Parallel Ringing mobile leg from starting
	   until the extension stopped ringing */
	const switch_bool_t forked = (flags & (SOF_FORKED_DIAL | SOF_NOBLOCK)) ? SWITCH_TRUE : SWITCH_FALSE;
	const char *profile = "internal";
	const char *member_uuid = NULL;
	ringq_member_watch_t *watch = NULL;
	switch_thread_t *watch_thread = NULL;
	switch_memory_pool_t *watch_pool = NULL;
	switch_status_t originated;
	const char *ringback = RINGQ_RINGBACK;
	char *cid_name_override = NULL, *cid_num_override = NULL;

	if (zstr(outbound_profile->destination_number)) goto done;
	ext = strdup(outbound_profile->destination_number);
	if (!(domain = strchr(ext, '@'))) {
		cause = SWITCH_CAUSE_INVALID_NUMBER_FORMAT;
		goto done;
	}
	*domain++ = '\0';

	/* find the user and its original dial-string, as the user/ endpoint does */
	switch_event_create(&params, SWITCH_EVENT_REQUEST_PARAMS);
	switch_event_add_header_string(params, SWITCH_STACK_BOTTOM, "as_channel", "true");
	switch_event_add_header_string(params, SWITCH_STACK_BOTTOM, "action", "user_call");
	if (var_event) switch_event_merge(params, var_event);
	if (switch_xml_locate_user_merged("id", ext, domain, NULL, &x_user, params) != SWITCH_STATUS_SUCCESS) {
		switch_log_printf(SWITCH_CHANNEL_SESSION_LOG(session), SWITCH_LOG_WARNING, "[ringq] can't find user %s@%s\n", ext, domain);
		cause = SWITCH_CAUSE_SUBSCRIBER_ABSENT;
		goto done;
	}
	if ((x_params = switch_xml_child(x_user, "params"))) {
		for (x_param = switch_xml_child(x_params, "param"); x_param; x_param = x_param->next) {
			if (!strcasecmp(switch_xml_attr_soft(x_param, "name"), "ringq-orig-dial-string")) {
				dest = switch_xml_attr(x_param, "value");
			}
		}
	}
	dialed_user = switch_xml_attr(x_user, "id"); /* RingQ: the number alias the apps register as */
	if (zstr(dest) || zstr(dialed_user)) {
		switch_log_printf(SWITCH_CHANNEL_SESSION_LOG(session), SWITCH_LOG_ERROR, "[ringq] no ringq-orig-dial-string for %s@%s\n", ext, domain);
		cause = SWITCH_CAUSE_MANDATORY_IE_MISSING;
		goto done;
	}

	switch_uuid_str(ring_id, sizeof(ring_id));
	caller_num = switch_str_nil(outbound_profile->caller_id_number);
	caller_name = switch_str_nil(outbound_profile->caller_id_name);
	cc_queue = switch_str_nil(ringq_var(session, var_event, "cc_queue"));
	if (!zstr(cc_queue)) {
		char *at;
		queue_ext = strdup(cc_queue);
		if ((at = strchr(queue_ext, '@'))) *at = '\0';
	}
	if (session) a_uuid = switch_core_session_get_uuid(session);
	if ((varval = ringq_var(session, var_event, "ringq_ring_wake_wait_ms")) && atoi(varval) > 0) wait_ms = atoi(varval);
	if ((varval = ringq_var(session, var_event, "ringq_ring_wake_wait_desk_ms")) && atoi(varval) >= 0) desk_wait_ms = atoi(varval);
	ringback = ringq_ringback(session, var_event);
	/* [queue] only mod_callcenter's agent dials: no caller session, a member to watch */
	if (!session) member_uuid = ringq_var(session, var_event, "cc_member_session_uuid");
	if (!zstr(varval = ringq_var(session, var_event, "ringq_sofia_profile"))) profile = varval;

	registered = ringq_registered(dialed_user, domain);

	/* 1. tell node */
	ringq_fire("ringq::ring", ext, domain, ring_id,
			   "ringq-mode", "wake", "ringq-a-uuid", a_uuid, "ringq-registered", registered ? "true" : "false",
			   "ringq-caller-number", caller_num, "ringq-caller-name", caller_name,
			   "ringq-queue", queue_ext ? queue_ext : "");

	/* 2. wait for the woken app (not in a forked dial: that would hold the other legs back) */
	if (!forked) {
		int t;

		for (t = 0; t < 1000 && pushed < 0 && !ringq_should_stop(session, cancel_cause); t += 100) {
			pushed = ringq_pushed(ring_id, &ios_pushed);
			if (pushed < 0) switch_yield(100000);
		}
		if (ios_pushed > 0) {
			/* iPhones were pushed: wait for the apps themselves, even if another device is registered.
			   Wait for every pushed iPhone; once one is up, the rest get until RINGQ_ALL_IOS_MS, so a
			   slow or switched-off phone doesn't hold the call back. */
			ringq_ios_wait_t c = { 0 };
			c.session = session;
			c.cancel_cause = cancel_cause;
			c.wait_ms = wait_ms;
			c.ios_pushed = ios_pushed;
			c.profile = profile;
			c.dialed_user = dialed_user;
			c.domain = domain;
			c.member_uuid = member_uuid;
			{
				int others = 0;
				c.ios_up = ringq_ios_registered(profile, dialed_user, domain, &others);
				/* a desk phone of the extension is online: ring it after at most
				   desk_wait_ms rather than the full wait for a slow phone (US6 giga, 2026-10-17:
				   Android apps took 6-15 s to wake and the Yealink on the same extension rang only
				   after that). A phone that wakes later gets the stop push, like any missed one. */
				if (others > 0 && c.wait_ms > desk_wait_ms) {
					c.wait_ms = desk_wait_ms;
					switch_log_printf(SWITCH_CHANNEL_SESSION_LOG(session), SWITCH_LOG_INFO,
									  "[ringq] %s@%s has %d desk phone(s) online, waiting at most %dms for the app\n", ext, domain, others, desk_wait_ms);
				}
			}
			if (c.ios_up < ios_pushed && c.wait_ms > 0) {
				switch_log_printf(SWITCH_CHANNEL_SESSION_LOG(session), SWITCH_LOG_INFO,
								  "[ringq] %s@%s waiting up to %dms for the iOS app to register (%d pushed)\n", ext, domain, c.wait_ms, pushed);
				c.seen = ringq_contacts(dialed_user, domain);
				ringq_wait(session, ringback, &waited, c.wait_ms, ringq_ios_done, &c);
				free(c.seen);
				switch_log_printf(SWITCH_CHANNEL_SESSION_LOG(session), SWITCH_LOG_INFO, "[ringq] %s@%s %d of %d iOS apps registered after %dms\n",
								  ext, domain, c.ios_up, ios_pushed, waited);
			}
			registered = ringq_registered(dialed_user, domain);
		} else if (!registered && pushed > 0) {
			switch_log_printf(SWITCH_CHANNEL_SESSION_LOG(session), SWITCH_LOG_INFO,
							  "[ringq] %s@%s waiting up to %dms for app to register (%d pushed)\n", ext, domain, wait_ms, pushed);
			{
				ringq_reg_wait_t c = { 0 };
				c.session = session;
				c.cancel_cause = cancel_cause;
				c.wait_ms = wait_ms;
				c.dialed_user = dialed_user;
				c.domain = domain;
				c.member_uuid = member_uuid;
				ringq_wait(session, ringback, &waited, wait_ms, ringq_reg_done, &c);
				registered = c.registered;
			}
			switch_log_printf(SWITCH_CHANNEL_SESSION_LOG(session), SWITCH_LOG_INFO, "[ringq] %s@%s %s after %dms\n", ext, domain,
							  registered ? "registered" : "no app registered", waited);
		}
	}
	ringq_hash_delete(ring_id);
	if (ringq_should_stop(session, cancel_cause)) {
		cause = (cancel_cause && *cancel_cause > 0) ? *cancel_cause : SWITCH_CAUSE_ORIGINATOR_CANCEL;
		goto done_event;
	}
	if (ringq_member_taken(member_uuid)) {
		switch_log_printf(SWITCH_CHANNEL_LOG, SWITCH_LOG_INFO, "[ringq] %s@%s queue call taken by another agent, not ringing\n", ext, domain);
		cause = SWITCH_CAUSE_LOSE_RACE;
		goto done_event;
	}

	/* 3. originate the original dial-string the way user/ does */
	if (var_event) {
		cid_name_override = switch_event_get_header(var_event, "origination_caller_id_name");
		cid_num_override = switch_event_get_header(var_event, "origination_caller_id_number");
		switch_event_add_header_string(var_event, SWITCH_STACK_BOTTOM, "dialed_user", dialed_user);
		switch_event_add_header_string(var_event, SWITCH_STACK_BOTTOM, "dialed_domain", domain);
	}
	if (session) {
		switch_channel_t *channel = switch_core_session_get_channel(session);
		/* the caller hears ringback straight away (the old push path played falsering.wav to app
		   callers from node; without it a WebRTC caller heard nothing until the device rang) */
		if (zstr(switch_channel_get_variable(channel, "instant_ringback"))) {
			switch_channel_set_variable(channel, "instant_ringback", "true");
		}
		/* the same tone as during the wake wait (not the dialplan's ${us-ring}) */
		switch_channel_set_variable(channel, "ringback", ringback);
		/* an answered caller (a transferred call) hears transfer_ringback instead: the same ring */
		switch_channel_set_variable(channel, "transfer_ringback", ringback);
		if ((varval = switch_channel_get_variable(channel, SWITCH_CALL_TIMEOUT_VARIABLE))
			|| (var_event && (varval = switch_event_get_header(var_event, "leg_timeout")))) {
			timelimit = atoi(varval);
		}
		switch_channel_set_variable(channel, "dialed_user", dialed_user);
		switch_channel_set_variable(channel, "dialed_domain", domain);
		d_dest = switch_channel_expand_variables(channel, dest);
	} else {
		switch_event_t *event = NULL;
		if (var_event) {
			switch_event_dup(&event, var_event);
			if ((varval = switch_event_get_header(var_event, SWITCH_CALL_TIMEOUT_VARIABLE)) || (varval = switch_event_get_header(var_event, "leg_timeout"))) {
				timelimit = atoi(varval);
			}
		} else {
			switch_event_create(&event, SWITCH_EVENT_REQUEST_PARAMS);
		}
		switch_event_del_header(event, "dialed_user");
		switch_event_del_header(event, "dialed_domain");
		switch_event_add_header_string(event, SWITCH_STACK_BOTTOM, "dialed_user", dialed_user);
		switch_event_add_header_string(event, SWITCH_STACK_BOTTOM, "dialed_domain", domain);
		d_dest = switch_event_expand_headers(event, dest);
		switch_event_destroy(&event);
	}
	if (strstr(d_dest, "ringq/")) {
		switch_log_printf(SWITCH_CHANNEL_SESSION_LOG(session), SWITCH_LOG_ERROR, "[ringq] dial-string for %s@%s loops back to ringq/\n", ext, domain);
		cause = SWITCH_CAUSE_INVALID_IE_CONTENTS;
		goto done_event;
	}
	/* the ring id becomes the SIP Call-ID (the app matches it to the push); FreeSWITCH reads only
	   one leading {...}, so merge ours into the one the dial-string may already have.
	   A decline or busy on any of the extension's devices ends the ringing on all of them
	   (queue, DID and extension calls alike); other causes, e.g. one device timing out, don't. */
	if (*d_dest == '{') {
		full_dest = switch_mprintf("{sip_invite_call_id=%s,ringq_ring_id=%s,ringq_ring_ext=%s,fail_on_single_reject=CALL_REJECTED:USER_BUSY,%s",
								   ring_id, ring_id, ext, d_dest + 1);
	} else {
		full_dest = switch_mprintf("{sip_invite_call_id=%s,ringq_ring_id=%s,ringq_ring_ext=%s,fail_on_single_reject=CALL_REJECTED:USER_BUSY}%s",
								   ring_id, ring_id, ext, d_dest);
	}
	if ((flags & SOF_NO_LIMITS)) myflags |= SOF_NO_LIMITS;
	if (forked) myflags |= SOF_NOBLOCK;

	if (!zstr(member_uuid) && !forked && switch_core_new_memory_pool(&watch_pool) == SWITCH_STATUS_SUCCESS) {
		switch_threadattr_t *thd_attr = NULL;
		watch = switch_core_alloc(watch_pool, sizeof(*watch));
		switch_copy_string(watch->member_uuid, member_uuid, sizeof(watch->member_uuid));
		switch_copy_string(watch->ring_id, ring_id, sizeof(watch->ring_id));
		switch_threadattr_create(&thd_attr, watch_pool);
		switch_threadattr_stacksize_set(thd_attr, SWITCH_THREAD_STACKSIZE);
		if (switch_thread_create(&watch_thread, thd_attr, ringq_member_watch_run, watch, watch_pool) != SWITCH_STATUS_SUCCESS) {
			watch_thread = NULL;
		}
	}

	originated = switch_ivr_originate(session, new_session, &cause, full_dest, timelimit, NULL, cid_name_override, cid_num_override,
									  outbound_profile, var_event, myflags, cancel_cause, NULL);
	if (watch) {
		watch->done = 1;
		if (watch_thread) {
			switch_status_t st;
			switch_thread_join(&st, watch_thread);
		}
	}
	if (watch_pool) switch_core_destroy_memory_pool(&watch_pool);
	if (originated != SWITCH_STATUS_SUCCESS && ringq_member_taken(member_uuid)) cause = SWITCH_CAUSE_LOSE_RACE;

	if (originated == SWITCH_STATUS_SUCCESS) {
		switch_channel_t *new_channel = switch_core_session_get_channel(*new_session);
		const char *context;
		switch_caller_profile_t *cp;
		answered = SWITCH_TRUE;
		if (var_event) switch_event_del_header(var_event, "origination_uuid");
		if ((context = switch_channel_get_variable(new_channel, "user_context")) && (cp = switch_channel_get_caller_profile(new_channel))) {
			cp->context = switch_core_strdup(cp->pool, context);
		}
		/* the user's directory variables, like user/ */
		if ((x_params = switch_xml_child(x_user, "variables"))) {
			for (x_param = switch_xml_child(x_params, "variable"); x_param; x_param = x_param->next) {
				switch_channel_set_variable(new_channel, switch_xml_attr(x_param, "name"), switch_xml_attr(x_param, "value"));
			}
		}
		switch_core_session_rwunlock(*new_session);
	}

  done_event:
	/* 4. tell node how the ring ended (forked dials end through their own legs' events) */
	if (!forked) {
		switch_snprintf(wait_s, sizeof(wait_s), "%d", waited);
		switch_snprintf(pushed_s, sizeof(pushed_s), "%d", pushed);
		ringq_fire("ringq::ring_end", ext, domain, ring_id,
				   "ringq-cause", switch_channel_cause2str(cause), "ringq-answered", answered ? "true" : "false",
				   "ringq-registered", registered ? "true" : "false", "ringq-waited-ms", wait_s,
				   "ringq-pushed", pushed_s, NULL, NULL);
	}

  done:
	if (full_dest) free(full_dest);
	if (d_dest && d_dest != dest) free(d_dest);
	if (x_user) switch_xml_free(x_user);
	if (params) switch_event_destroy(&params);
	switch_safe_free(queue_ext);
	switch_safe_free(ext);
	return cause;
}

static switch_io_routines_t ringq_io_routines = {
	/*.outgoing_channel */ ringq_outgoing_channel
};

SWITCH_MODULE_LOAD_FUNCTION(mod_ringq_load)
{
	*module_interface = switch_loadable_module_create_module_interface(pool, modname);
	if (switch_event_reserve_subclass("ringq::ring") != SWITCH_STATUS_SUCCESS ||
		switch_event_reserve_subclass("ringq::ring_end") != SWITCH_STATUS_SUCCESS) {
		switch_log_printf(SWITCH_CHANNEL_LOG, SWITCH_LOG_WARNING, "[ringq] event subclasses already reserved\n");
	}
	ringq_endpoint_interface = switch_loadable_module_create_interface(*module_interface, SWITCH_ENDPOINT_INTERFACE);
	ringq_endpoint_interface->interface_name = "ringq";
	ringq_endpoint_interface->io_routines = &ringq_io_routines;
	return SWITCH_STATUS_SUCCESS;
}
