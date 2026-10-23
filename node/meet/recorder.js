/**
 * Durable writes for meet — the audit trail behind the Redis live state.
 *
 * Redis answers "who is in this room right now" and is disposable. THIS answers
 * "what happened", and must survive a Redis flush, a restart, and the meeting
 * itself. Without it, meeting history / recordings / transcripts have no home —
 * which is exactly the gap the legacy meet had (everything lived inside the
 * `rooms` Redis blob, so history made every other operation slower forever).
 *
 * Writes go to the tenant's EXISTING `ringq` database (tables `meet_*`, created
 * by MeetSchemaTask). There is no separate meet database — see
 * docs/MEET-DATA-MODEL.md.
 *
 * DESIGN RULE: a durable-write failure must never break a live meeting. Every
 * method resolves rather than rejects, and reports the failure through the
 * logger. A meeting that works but loses an audit row is far better than a
 * meeting that cannot start because Postgres hiccuped. Callers already treat
 * these as fire-and-forget.
 *
 * Runtime: CommonJS on Node.js 24 LTS. Uses the pinned `pg` dependency and
 * the same PostgreSQL_DB_* env vars as signal.js.
 */

class MeetRecorder {
  /**
   * @param {object} opts
   * @param {object} opts.pool     a pg Pool (injected so tests need no database)
   * @param {object} [opts.logger]
   */
  constructor(opts) {
    const o = opts || {};
    if (!o.pool) throw new TypeError("MeetRecorder requires a pg pool");
    this.pool = o.pool;
    this.log = o.logger || { info: function () {}, warn: function () {}, error: function () {} };
    this.enabled = true;
  }

  /**
   * Run a query, swallowing failures by design (see DESIGN RULE above).
   * Returns rows on success, null on failure.
   */
  async _run(label, sql, params) {
    if (!this.enabled) return null;
    try {
      const result = await this.pool.query(sql, params);
      return result && result.rows ? result.rows : [];
    } catch (err) {
      // A missing table means the schema task has not been run on this tenant.
      // Say so precisely — "relation does not exist" is otherwise a puzzle.
      if (err && err.code === "42P01") {
        this.log.error(
          "meet: durable " + label + " skipped — meet_* tables missing. " +
            "Run: php /var/www/ringq/app/cli.php meetSchema up"
        );
      } else {
        this.log.warn("meet: durable " + label + " failed: " + (err && err.message));
      }
      return null;
    }
  }

  /**
   * A meeting was created. Uses the meetingId the control plane already minted
   * so the Redis key and the durable row share one identity.
   */
  async meetingCreated(tenant, meeting) {
    return this._run(
      "meetingCreated",
      `INSERT INTO public.meet_meetings
         (id, domain_uuid, slug, external_reference, owner_key, title, meet_type, status, settings, created_at)
       VALUES ($1, $2, $3, $4, $5, $6, $7, 'created', $8::jsonb, now())
       ON CONFLICT DO NOTHING`,
      [
        meeting.meetingId,
        tenant.domainUuid,
        meeting.slug || null,
        meeting.externalReference || null,
        meeting.ownerKey || "",
        meeting.title || null,
        meeting.meetType === "scheduled" ? "scheduled" : "instant",
        JSON.stringify(
          Object.assign(
            {},
            meeting.policy ? { mediaPolicy: meeting.policy } : {},
            // The lobby presentation is written here as well as by
            // `meetingLobby` below, so the very first create already carries
            // it: `ON CONFLICT DO NOTHING` means this INSERT is the only
            // chance a brand-new row gets.
            meeting.lobby ? { lobby: meeting.lobby } : {}
          )
        ),
      ]
    );
  }

  /**
   * Mirror the lobby presentation into the durable meeting row.
   *
   * Merged rather than replaced, twice over: `||` at the top level keeps
   * `mediaPolicy` and anything else already in `settings`, and the inner merge
   * keeps a banner that was uploaded before the metadata was last sent. Without
   * the inner one, pressing *Copy link* a second time would silently drop the
   * artwork while appearing to do nothing at all.
   *
   * This is the copy that matters. Redis holds the room for twelve hours; a
   * webinar link is handed out weeks ahead of that.
   */
  async meetingLobby(tenant, meeting) {
    return this._run(
      "meetingLobby",
      `UPDATE public.meet_meetings
          SET settings = COALESCE(settings, '{}'::jsonb)
                         || jsonb_build_object(
                              'lobby',
                              COALESCE(settings->'lobby', '{}'::jsonb) || $3::jsonb
                            )
        WHERE id = $1 AND domain_uuid = $2`,
      [meeting.meetingId, tenant.domainUuid, JSON.stringify(meeting.lobby || {})]
    );
  }

  /**
   * Store the banner bytes on the durable row.
   *
   * Separate from `meetingLobby` because the two arrive on different requests
   * and at different sizes: metadata rides the create, the image is its own
   * upload. Both merge into the same `lobby` object.
   */
  async meetingLobbyBanner(tenant, meeting) {
    return this._lobbyAsset(tenant, "meetingLobbyBanner", meeting.meetingId, "banner", meeting.banner);
  }

  /**
   * Store the presenter portrait on the durable row.
   *
   * Merges into the same `lobby` object under its own key, so a photo uploaded
   * today does not disturb a banner uploaded last week — `jsonb_build_object`
   * with a single key is what keeps the two independent.
   */
  async meetingLobbyPhoto(tenant, meeting) {
    return this._lobbyAsset(tenant, "meetingLobbyPhoto", meeting.meetingId, "photo", meeting.photo);
  }

  /** One statement for both lobby assets; the key is the only thing that varies. */
  async _lobbyAsset(tenant, label, meetingId, key, asset) {
    return this._run(
      label,
      `UPDATE public.meet_meetings
          SET settings = COALESCE(settings, '{}'::jsonb)
                         || jsonb_build_object(
                              'lobby',
                              COALESCE(settings->'lobby', '{}'::jsonb)
                              || jsonb_build_object($3::text, $4::jsonb)
                            )
        WHERE id = $1 AND domain_uuid = $2`,
      [meetingId, tenant.domainUuid, key, JSON.stringify(asset || {})]
    );
  }

  /**
   * The lobby presentation as stored, asset bytes included.
   *
   * Read only by the public artwork routes, and only when the Redis cache has
   * missed — which is the ordinary case for a meeting nobody has started yet.
   */
  async readLobby(tenant, meetingId) {
    const rows = await this._run(
      "readLobby",
      `SELECT settings->'lobby' AS lobby
         FROM public.meet_meetings
        WHERE id = $1 AND domain_uuid = $2
        LIMIT 1`,
      [meetingId, tenant.domainUuid]
    );
    if (!rows || !rows.length || !rows[0].lobby) return null;
    const lobby = rows[0].lobby;
    return typeof lobby === "string" ? JSON.parse(lobby) : lobby;
  }

  /**
   * Everything the public lookup needs about a meeting Redis has forgotten.
   *
   * `findMeetingByExternalReference` cannot serve this: it selects only
   * `id, slug` and matches only the reference, while a share link carries the
   * slug. Both columns are UNIQUE per tenant, so the `OR` cannot be ambiguous.
   *
   * `domain_uuid` is *filtered on*, not merely bound — a query that binds a
   * tenant and then matches a code across every one of them is a cross-tenant
   * read wearing a scoped query's clothes.
   */
  async findMeetingForLookup(tenant, code) {
    if (!code) return null;
    const rows = await this._run(
      "findMeetingForLookup",
      `SELECT id, slug, title, status, meet_type, settings
         FROM public.meet_meetings
        WHERE domain_uuid = $1 AND (slug = $2 OR external_reference = $2)
        LIMIT 1`,
      [tenant.domainUuid, String(code)]
    );
    if (!rows || !rows.length) return null;
    const row = rows[0];
    const settings = typeof row.settings === "string" ? JSON.parse(row.settings || "{}") : (row.settings || {});
    return {
      meetingId: row.id,
      slug: row.slug || "",
      title: row.title || "",
      status: row.status || "created",
      meetType: row.meet_type || "instant",
      lobby: settings && settings.lobby ? settings.lobby : null,
    };
  }

  /**
   * The durable identity of a meeting, looked up by the reference the booking,
   * schedule or webinar stored on its own record.
   *
   * This table is what a meeting *is*; Redis is a twelve-hour cache of it.
   * `meet_meetings` carries a UNIQUE index on (domain_uuid, external_reference),
   * so there is at most one row per booking — and its `id` and `slug` are
   * therefore permanent. Reading it before minting anything is what keeps a
   * link handed out weeks in advance working, and what keeps a reopened
   * meeting attached to the rows that reference it.
   *
   * Read-only and fail-soft by construction: `_run` answers null on any error,
   * and a null here means "mint fresh", which is exactly the behaviour that
   * existed before this lookup did.
   */
  async findMeetingByExternalReference(tenant, externalReference) {
    if (!externalReference) return null;
    const rows = await this._run(
      "findMeetingByExternalReference",
      `SELECT id, slug FROM public.meet_meetings
        WHERE domain_uuid = $1 AND external_reference = $2
        LIMIT 1`,
      [tenant.domainUuid, String(externalReference)]
    );
    if (!rows || !rows.length) return null;
    return { meetingId: rows[0].id, slug: rows[0].slug || "" };
  }

  /**
   * A meeting that had ended is starting again on the same row.
   *
   * An INSERT cannot do this: the row already exists, so `ON CONFLICT DO
   * NOTHING` would leave `status = 'ended'` and the previous `ended_at` on a
   * meeting that is happening right now. The end markers are cleared rather
   * than left, because the row describes the session in progress — which is
   * what the unique index on (domain_uuid, external_reference) already forces
   * it to be.
   */
  async meetingReopened(tenant, meeting) {
    return this._run(
      "meetingReopened",
      `UPDATE public.meet_meetings
          SET status = 'created',
              ended_at = NULL,
              end_reason = NULL,
              ended_by_ref = NULL,
              owner_key = COALESCE($3, owner_key),
              title = COALESCE($4, title)
        WHERE id = $1 AND domain_uuid = $2`,
      [meeting.meetingId, tenant.domainUuid, meeting.ownerKey || null, meeting.title || null]
    );
  }

  /**
   * Mirror the live media policy into the durable meeting row.
   *
   * Redis owns the policy for the length of the meeting; this exists so the
   * choice survives the room's TTL and shows up in history. Merged rather than
   * replaced so nothing else already in `settings` is lost.
   */
  async meetingPolicy(tenant, meeting) {
    return this._run(
      "meetingPolicy",
      `UPDATE public.meet_meetings
          SET settings = COALESCE(settings, '{}'::jsonb) || $3::jsonb
        WHERE id = $1 AND domain_uuid = $2`,
      [
        meeting.meetingId,
        tenant.domainUuid,
        JSON.stringify({ mediaPolicy: meeting.policy || {} }),
      ]
    );
  }

  /** First participant joined / host opened the room. */
  async meetingStarted(tenant, meeting) {
    return this._run(
      "meetingStarted",
      `UPDATE public.meet_meetings
          SET status = 'live', started_at = COALESCE(started_at, now())
        WHERE id = $1 AND domain_uuid = $2 AND status <> 'ended'`,
      [meeting.meetingId, tenant.domainUuid]
    );
  }

  /**
   * The meeting ended. Also closes any participant row still open, so a crashed
   * client cannot leave a row with `left_at` null forever.
   */
  async meetingEnded(tenant, meeting) {
    await this._run(
      "meetingEnded",
      `UPDATE public.meet_meetings
          SET status = 'ended', ended_at = COALESCE(ended_at, now()),
              end_reason = COALESCE($3, end_reason), ended_by_ref = COALESCE($4, ended_by_ref)
        WHERE id = $1 AND domain_uuid = $2`,
      [meeting.meetingId, tenant.domainUuid, meeting.endReason || null, meeting.endedByRef || null]
    );
    return this._run(
      "meetingEnded.closeParticipants",
      `UPDATE public.meet_participants
          SET left_at = now()
        WHERE meeting_id = $1 AND domain_uuid = $2 AND left_at IS NULL`,
      [meeting.meetingId, tenant.domainUuid]
    );
  }

  /** Someone joined. `participantRef` is the LiveKit identity. */
  async participantJoined(tenant, p) {
    return this._run(
      "participantJoined",
      `INSERT INTO public.meet_participants
         (meeting_id, domain_uuid, participant_ref, display_name, email, role, joined_at)
       VALUES ($1, $2, $3, $4, $5, $6, now())`,
      [
        p.meetingId,
        tenant.domainUuid,
        p.participantId || null,
        p.displayName || "Guest",
        p.email || null,
        p.role === "host" || p.role === "cohost" ? p.role : "participant",
      ]
    );
  }

  /**
   * Someone left — driven by the LiveKit webhook, not the client, which is what
   * makes attendance trustworthy even when a client is killed.
   *
   * Closes only the most recent open row: a participant who rejoins produces
   * several rows and closing all of them would corrupt earlier durations.
   */
  async participantLeft(tenant, p) {
    return this._run(
      "participantLeft",
      `UPDATE public.meet_participants
          SET left_at = now()
        WHERE id = (
          SELECT id FROM public.meet_participants
           WHERE meeting_id = $1 AND domain_uuid = $2
             AND participant_ref = $3 AND left_at IS NULL
           ORDER BY joined_at DESC NULLS LAST
           LIMIT 1
        )`,
      [p.meetingId, tenant.domainUuid, p.participantId || null]
    );
  }

  async recordingStarted(tenant, r) {
    return this._run(
      "recordingStarted",
      `INSERT INTO public.meet_recordings
         (meeting_id, domain_uuid, egress_id, kind, status, started_at)
       VALUES ($1, $2, $3, $4, 'active', now())`,
      [r.meetingId, tenant.domainUuid, r.egressId || null, r.kind === "video" ? "video" : "audio"]
    );
  }

  /** Egress finished — LiveKit reports success or failure. */
  async recordingFinished(tenant, r) {
    const status = r.status === "failed" ? "failed" : "complete";
    return this._run(
      "recordingFinished",
      `UPDATE public.meet_recordings
          SET status = $4, ended_at = now(), file_path = COALESCE($5, file_path)
        WHERE meeting_id = $1 AND domain_uuid = $2
          AND (egress_id = $3 OR $3 IS NULL) AND status IN ('starting','active')`,
      [r.meetingId, tenant.domainUuid, r.egressId || null, status, r.filePath || null]
    );
  }

  async transcriptLine(tenant, t) {
    return this._run(
      "transcriptLine",
      `INSERT INTO public.meet_transcripts (meeting_id, domain_uuid, participant, text, at)
       VALUES ($1, $2, $3, $4, now())`,
      [t.meetingId, tenant.domainUuid, t.participant || null, t.text || ""]
    );
  }

  async chatMessage(tenant, m) {
    return this._run(
      "chatMessage",
      `INSERT INTO public.meet_chat_messages
         (meeting_id, domain_uuid, participant_ref, sender_name, body, files, sent_at)
       VALUES ($1, $2, $3, $4, $5, $6::jsonb, now())`,
      [
        m.meetingId,
        tenant.domainUuid,
        m.participantId || null,
        m.senderName || "Guest",
        m.body || "",
        JSON.stringify(m.files || []),
      ]
    );
  }

  async summarySaved(tenant, s) {
    return this._run(
      "summarySaved",
      `INSERT INTO public.meet_summaries (meeting_id, domain_uuid, html, emailed_to, created_at)
       VALUES ($1, $2, $3, $4::jsonb, now())`,
      [s.meetingId, tenant.domainUuid, s.html || "", JSON.stringify(s.emailedTo || [])]
    );
  }

  async feedbackSaved(tenant, f) {
    return this._run(
      "feedbackSaved",
      `INSERT INTO public.meet_feedback (meeting_id, domain_uuid, rating, comment, created_at)
       VALUES ($1, $2, $3, $4, now())`,
      [f.meetingId, tenant.domainUuid, f.rating === undefined ? null : f.rating, f.comment || null]
    );
  }

  async invitationSent(tenant, i) {
    return this._run(
      "invitationSent",
      `INSERT INTO public.meet_invitations (meeting_id, domain_uuid, email, sent_at)
       VALUES ($1, $2, $3, now())`,
      [i.meetingId, tenant.domainUuid, i.email]
    );
  }

  /** Durable host/cohost moderation audit, without storing request payloads. */
  async moderationEvent(tenant, event) {
    const allowed = [
      "admit", "decline", "kick", "promote", "demote", "end", "lower_hand",
      // An admission the policy made and no person did. Its own action so the
      // trail never claims a moderator was present; see `recordAutoAdmit`.
      "auto_admit",
      "chat_delete", "whiteboard_clear", "whiteboard_lock", "whiteboard_present",
      "participant_media_mute", "participant_media_request", "participant_media_response",
      "media_policy_update", "media_grant", "media_access_request", "media_access_response",
      "file_presentation_stop",
      "recording_start", "recording_stop", "transcription_start", "transcription_stop",
      "capture_acknowledge", "capture_failure",
    ];
    if (allowed.indexOf(event.action) === -1) return null;
    return this._run(
      "moderationEvent",
      `INSERT INTO public.meet_audit_events
         (meeting_id, domain_uuid, actor_key, action, target_ref, details, created_at)
       VALUES ($1, $2, $3, $4, $5, $6::jsonb, now())`,
      [
        event.meetingId,
        tenant.domainUuid,
        event.actorKey || "",
        event.action,
        event.targetRef || null,
        JSON.stringify(event.details || {}),
      ]
    );
  }

  /**
   * Meeting history — the query that replaces lrange('rooms', 0, -1).
   * Uses the (domain_uuid, created_at DESC) index, so cost does not grow with
   * the number of meetings the tenant has ever held.
   */
  async listHistory(tenant, opts) {
    const o = opts || {};
    const limit = Math.max(1, Math.min(200, o.limit || 50));
    const offset = Math.max(0, o.offset || 0);
    const rows = await this._run(
      "listHistory",
      `SELECT m.id, m.slug, m.title, m.owner_key, m.meet_type, m.status,
              m.started_at, m.ended_at, m.created_at,
              (SELECT count(*) FROM public.meet_participants p
                WHERE p.meeting_id = m.id) AS participant_count
         FROM public.meet_meetings m
        WHERE m.domain_uuid = $1
          ${o.ownerKey ? "AND m.owner_key = $4" : ""}
        ORDER BY m.created_at DESC
        LIMIT $2 OFFSET $3`,
      o.ownerKey
        ? [tenant.domainUuid, limit, offset, o.ownerKey]
        : [tenant.domainUuid, limit, offset]
    );
    return rows || [];
  }

  async close() {
    try {
      if (this.pool && typeof this.pool.end === "function") await this.pool.end();
    } catch (err) {
      this.log.warn("meet: closing pg pool failed: " + err.message);
    }
  }
}

/**
 * Build a recorder from the same PostgreSQL_DB_* env vars the other ringq node
 * services use (see signal.js). Returns null when unconfigured, so a tenant
 * without database credentials degrades to "no audit trail" rather than
 * crashing the service.
 */
function fromEnv(env, logger) {
  const e = env || process.env;
  if (!e.PostgreSQL_DB_HOST || !e.PostgreSQL_DB_NAME) {
    if (logger) logger.warn("meet: PostgreSQL_DB_* not configured — durable writes disabled");
    return null;
  }
  const { Pool } = require("pg");
  const pool = new Pool({
    user: e.PostgreSQL_DB_USER,
    host: e.PostgreSQL_DB_HOST,
    database: e.PostgreSQL_DB_NAME,
    password: e.PostgreSQL_DB_PASSWORD,
    port: Number(e.PostgreSQL_DB_PORT || 5432),
    max: 4,
    idleTimeoutMillis: 30000,
    connectionTimeoutMillis: 5000,
  });
  // A pool-level error must not become an unhandled exception and kill the
  // service — the legacy relay shares this process.
  pool.on("error", function (err) {
    if (logger) logger.warn("meet: pg pool error: " + err.message);
  });
  return new MeetRecorder({ pool: pool, logger: logger });
}

module.exports = { MeetRecorder: MeetRecorder, fromEnv: fromEnv };
