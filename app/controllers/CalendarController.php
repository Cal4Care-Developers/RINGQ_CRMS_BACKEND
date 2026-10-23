<?php
declare(strict_types=1);

use Phalcon\Mvc\View;

/**
 * Calendar providers: two-way sync between a user's own external calendar and
 * the RingQ calendar.
 *
 * Google is the only provider implemented today. The controller is named and
 * shaped for the family on purpose -- `PROVIDERS`, a provider route parameter
 * and provider-scoped Redis keys -- so adding Zoho or Microsoft is a row in one
 * list plus its own `<provider>*` methods, and never a rename of a route, a key
 * or a redirect URI that a tenant has already registered in a developer
 * console. What is deliberately NOT here is an abstraction layer for a provider
 * that does not exist: shared plumbing is shared, and everything specific to
 * Google is in a method whose name says so.
 *
 * ITS OWN CONTROLLER, ITS OWN CONFIGURATION RECORD, ITS OWN CALLBACK AND ITS
 * OWN SWITCH -- sharing nothing with the Google SSO and Google Address Book
 * flows in `TeamsController`. Three reasons, in order of what they cost when
 * ignored:
 *
 *  1. `TeamsController::googlesettingsAction` rebuilds
 *     `google_settings:<subdomain>` from defaults on every save, so a field one
 *     dialog does not send is erased by the next press of Save in the other --
 *     saving the Address Book dialog today switches Google SSO off, because it
 *     posts no `enablegooglesso`. Calendar state kept in that record would be
 *     lost the first time an admin touched either of those dialogs.
 *  2. A callback of its own means the working SSO and contacts flows are not
 *     edited at all, so neither can regress. An earlier draft routed this return
 *     through `googlecallbackAction` on an OAuth `state` discriminator to spare
 *     admins from registering a second redirect URI; separate configuration
 *     removes that reason, and editing the entry point of a working import to
 *     save somebody a paste was always the weakest part of the design.
 *  3. A tenant can run SSO without Calendar and Calendar without SSO.
 *
 * An admin may paste the SAME OAuth client id and secret the SSO dialog holds
 * -- one Google client can carry several redirect URIs and several scopes -- but
 * the records are independent, so rotating one does not silently change another.
 *
 * TOKENS ARE PER USER (`calendar_tokens:<provider>:<domain_uuid>:<user_uuid>`),
 * never tenant-wide, and a user may connect only the account whose address
 * matches their own `v_users.user_email`. The tenant-wide pair in
 * `google_settings` is overwritten by whoever connected last and so cannot
 * express one calendar per person, which is the whole feature.
 *
 * WHAT THIS CONTROLLER DOES NOT DO, and where that work lives instead:
 *
 *  * It does not reconcile inbound events. They go to `schedule/schedule/5`,
 *    which already owns the UID + sequence rules, the caps, the `joinUrl`
 *    validation and the guarantee that an imported event mints no Meet room and
 *    mails nobody.
 *  * It does not decode a schedule row's date. `startDate` is a local-midnight
 *    epoch written by the browser, and reading it here would read it in the
 *    SERVER's timezone -- observed on real boxes as `Asia/Shanghai` and
 *    `America/Sao_Paulo` -- and land the meeting on the wrong day. The client
 *    sends event bodies it built with the same function that draws the row.
 */
class CalendarController extends ControllerBase
{

/** Every calendar provider this controller can talk to, and the name to put in
 * front of a person.
 *
 * One entry today. It is a list rather than an assumption so that adding Zoho
 * or Microsoft means adding a row here plus its own `<provider>*` methods --
 * never renaming a route, a Redis key or a redirect URI that a tenant has
 * already registered in somebody's developer console.
 *
 * A provider not in this list is refused BY NAME. `calendar/connect/zoho`
 * answering "Zoho Calendar is not supported on this server yet" is a different
 * thing from a route that 404s, and only one of them tells the reader what
 * happened.
 */
const PROVIDERS = [
    'google' => 'Google Calendar',
];

/** Read and write of events on calendars the user can already reach.
 *
 * Deliberately NOT `auth/calendar`, which additionally grants creating,
 * deleting and re-ACLing whole calendars -- none of which this does.
 */
/* The scopes the consent request asks for.
 *
 * `calendar.events` is the working scope: it reads and writes events and
 * cannot create, delete or re-ACL a whole calendar.
 *
 * `userinfo.email` is NOT scope creep and NOT optional. The callback asks
 * Google which account just consented and refuses any address that is not the
 * signed-in user's -- that comparison IS the per-user guarantee this whole
 * feature rests on. `oauth2/v2/userinfo` answers 403 to a token carrying no
 * identity scope, so without this EVERY connection fails closed on "Google
 * would not say which account was connected" and the feature cannot work at
 * all. It is non-sensitive, so it adds nothing to Google's verification
 * requirements.
 *
 * Keep both on one line each: the settings dialog shows this string to the
 * admin, who has to paste them into the Google console.
 */
const GOOGLE_WORK_SCOPE     = 'https://www.googleapis.com/auth/calendar.events';
const GOOGLE_IDENTITY_SCOPE = 'https://www.googleapis.com/auth/userinfo.email';

const GOOGLE_SCOPE = self::GOOGLE_WORK_SCOPE . ' ' . self::GOOGLE_IDENTITY_SCOPE;

// Provider-scoped from the start. A key shape that has to change to admit a
// second provider is a migration nobody schedules.
const SETTINGS_PREFIX = 'calendar_settings:';   // <provider>:<subdomain>
const TOKEN_PREFIX    = 'calendar_tokens:';     // <provider>:<domain>:<user>
const LINK_PREFIX     = 'calendar_links:';      // <provider>:<domain>:<user>

/** Where the CSRF nonce lives between the consent redirect and the callback.
 *
 * Single use, and short lived. Neither pre-existing Google flow in this file
 * sends `state` at all, which is a real gap in them -- flagged, not fixed here.
 */
const STATE_SESSION_KEY = 'calendar_oauth_state';
const STATE_TTL_SECONDS = 300;

/** The window inbound events are fetched for.
 *
 * Forward matches the +/-12 month window the calendar's own recurrence
 * expansion is already bounded to. 30 days back is enough to still find a
 * meeting somebody wants the notes from, without paying for a year of history
 * nobody scrolls to.
 */
const WINDOW_BACK_DAYS      = 30;
const WINDOW_FORWARD_MONTHS = 12;

/** Never fetch more than one import can store.
 *
 * Mirrors `ScheduleController::EXTERNAL_IMPORT_MAX_EVENTS`. Fetching more would
 * show the reader a preview the server then refuses whole.
 */
const MAX_EVENTS = 200;

/** And never push more than this in one pass, so one sync cannot spend an
 * unbounded number of Google API calls. */
const MAX_PUSH = 200;

/** Marks an event on Google as one RingQ pushed.
 *
 * THE load-bearing field in this file. Without it every sync re-imports the
 * schedules the previous sync exported, and the calendar doubles on every pass.
 * It is the single mechanism preventing that -- there is deliberately no second
 * check against link-map membership, because with two neither can be proven to
 * be the one doing the work.
 */
const LINK_PROPERTY = 'ringqScheduleId';

/*
|--------------------------------------------------------------------------
| TENANT CONFIGURATION
|--------------------------------------------------------------------------
|
| GET  -> { enabled, client_id, client_secret_set, redirect_uri }
| POST -> saves enabled / client_id / client_secret
*/
public function settingsAction($provider = 'google')
{
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);

    try {
        $resolved = $this->requireProvider($provider);

        if (!is_string($resolved)) {
            return $resolved;
        }

        $provider  = $resolved;
        $session   = $this->session->get("auth") ?? [];
        $subdomain = trim((string)($session['subdomain'] ?? ''));

        if ($subdomain === '') {
            throw new \Exception("Invalid session");
        }

        $existing = $this->tenantSettings($provider, $subdomain);

        if ($this->request->isGet()) {
            return $this->response->setJsonContent([
                'result' => 'success',
                'status' => true,
                'data'   => $this->settingsView($provider, $subdomain, $existing),
            ]);
        }

        $data = $this->request->getJsonRawBody(true);

        if (!is_array($data)) {
            throw new \Exception("Invalid JSON request");
        }

        $clientId = trim((string)($data['client_id'] ?? ''));
        $secret   = trim((string)($data['client_secret'] ?? ''));

        // An empty secret on an update keeps the stored one. An admin toggling
        // Enable must not have to re-paste it, and must not be able to blank it
        // by accident -- which is exactly how the neighbouring record loses
        // fields. Clearing it is done by replacing the client id.
        if ($secret === '') {
            $secret = (string)($existing['client_secret'] ?? '');
        }

        $enabled = (int)($data['enabled'] ?? 0) === 1 ? 1 : 0;

        // Refused rather than saved: a switch that reads "on" while the sync
        // can never authenticate is a control that lies about its own state.
        if ($enabled === 1 && ($clientId === '' || $secret === '')) {
            return $this->response->setJsonContent([
                'result'  => 'error',
                'status'  => false,
                'message' => $clientId === ''
                    ? 'Add the Client ID before enabling ' . self::PROVIDERS[$provider] . '.'
                    : 'Add the Client Secret before enabling ' . self::PROVIDERS[$provider] . '.',
            ]);
        }

        $save = [
            'enabled'       => $enabled,
            'client_id'     => $clientId,
            'client_secret' => $secret,
        ];

        if (!$this->redis->set($this->settingsKey($provider, $subdomain), json_encode($save))) {
            throw new \Exception('Unable to save ' . self::PROVIDERS[$provider] . ' settings');
        }

        return $this->response->setJsonContent([
            'result'  => 'success',
            'status'  => true,
            'message' => self::PROVIDERS[$provider] . ' settings saved.',
            'data'    => $this->settingsView($provider, $subdomain, $save),
        ]);

    } catch (\Throwable $e) {
        return $this->response->setJsonContent([
            'result'  => 'error',
            'status'  => false,
            'message' => ControllerBase::safeErrorMessage($e),
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| THE SIGNED-IN USER'S OWN CONNECTION
|--------------------------------------------------------------------------
|
| `calendar/status/google`, `calendar/connect/google`, and so on. The provider
| is a route PARAMETER, not part of the action name: the ACL registers actions,
| so a second provider costs no ACL edit, and `calendar/connect/zoho` is a route
| that exists and answers "not supported yet" rather than a 404 that reads like
| a failed deploy.
|
| Named actions rather than one `$type` switch, for the same reason:
| `calendar/status/google` says what it is where `calendar/1/1` does not.
*/

/** What this user's connection to [$provider] looks like right now. */
public function statusAction($provider = 'google')
{
    return $this->guarded($provider, function (array $session, string $provider) {
        return $this->response->setJsonContent([
            'result' => 'success',
            'status' => true,
            'data'   => $this->status($provider, $session),
        ]);
    });
}

/** The consent URL to send this user to. */
public function connectAction($provider = 'google')
{
    return $this->guarded($provider, function (array $session, string $provider) {
        return $this->googleConsentUrl($provider, $session);
    });
}

/** This user's provider events for the sync window (the inbound half). */
public function eventsAction($provider = 'google')
{
    return $this->guarded($provider, function (array $session, string $provider) {
        return $this->googleFetchEvents($provider, $session);
    });
}

/** Reconcile this user's RingQ schedules to the provider (the outbound half). */
public function pushAction($provider = 'google')
{
    return $this->guarded($provider, function (array $session, string $provider) {
        return $this->googlePush($provider, $session);
    });
}

/** Remove what RingQ put there, then revoke and forget the token. */
public function disconnectAction($provider = 'google')
{
    return $this->guarded($provider, function (array $session, string $provider) {
        return $this->googleDisconnect($provider, $session);
    });
}

/** Runs [$body] with a validated session and a supported provider, turning
 * anything thrown into the error shape every action here answers with. */
private function guarded($provider, callable $body)
{
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);

    try {
        $resolved = $this->requireProvider($provider);

        if (!is_string($resolved)) {
            return $resolved;
        }

        return $body($this->requireSession(), $resolved);
    } catch (\Throwable $e) {
        return $this->response->setJsonContent([
            'result'  => 'error',
            'status'  => false,
            'message' => ControllerBase::safeErrorMessage($e),
        ]);
    }
}

/** [$provider] if this build can talk to it, else a Response saying so.
 *
 * Named rather than 404'd: "Zoho Calendar is not supported on this server yet"
 * and "that route does not exist" look identical to a user and completely
 * different to whoever is debugging it.
 */
private function requireProvider($provider)
{
    $name = strtolower(trim((string)$provider));

    if ($name !== '' && isset(self::PROVIDERS[$name])) {
        return $name;
    }

    return $this->response->setJsonContent([
        'result'  => 'error',
        'status'  => false,
        'message' => $name === ''
            ? 'No calendar provider was named.'
            : 'That calendar provider is not supported on this server yet.',
    ]);
}

/*
|--------------------------------------------------------------------------
| GOOGLE'S REDIRECT TARGET
|--------------------------------------------------------------------------
|
| Its own action, so `googlecallbackAction` -- which serves the working SSO and
| contacts flows -- is not touched. Returns HTML rather than JSON because a
| human is looking at this tab.
*/
public function callbackAction($provider = 'google')
{
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);

    try {
        $resolved = $this->requireProvider($provider);

        if (!is_string($resolved)) {
            return $this->googleCallbackPage(false, 'That calendar provider is not supported on this server yet.');
        }

        $provider = $resolved;
        $session  = $this->requireSession();

        $state = (string)($this->request->getQuery('state') ?? '');

        if (!$this->consumeState($state)) {
            return $this->googleCallbackPage(
                false,
                'That connection request expired. Open Google Calendar in the '
                . 'calendar and press Connect again.'
            );
        }

        $error = trim((string)($this->request->getQuery('error') ?? ''));

        if ($error !== '') {
            // The commonest value here is `access_denied`, i.e. the reader
            // pressed Cancel. Said plainly rather than dressed as a failure.
            return $this->googleCallbackPage(
                false,
                $error === 'access_denied'
                    ? 'Google Calendar was not connected, because access was not granted.'
                    : 'Google refused the connection: ' . $error
            );
        }

        $code = trim((string)($this->request->getQuery('code') ?? ''));

        if ($code === '') {
            return $this->googleCallbackPage(false, 'Google did not send an authorization code.');
        }

        $settings = $this->tenantSettings($provider, (string)$session['subdomain']);

        if (trim((string)($settings['client_id'] ?? '')) === ''
            || trim((string)($settings['client_secret'] ?? '')) === '') {
            return $this->googleCallbackPage(false, 'Google Calendar is not configured on this server.');
        }

        [$status, $token] = $this->httpJson(
            'POST',
            'https://oauth2.googleapis.com/token',
            ['form' => [
                'code'          => $code,
                'client_id'     => (string)$settings['client_id'],
                'client_secret' => (string)$settings['client_secret'],
                'redirect_uri'  => $this->redirectUri($provider, (string)$session['subdomain']),
                'grant_type'    => 'authorization_code',
            ]]
        );

        if ($status !== 200 || empty($token['access_token'])) {
            return $this->googleCallbackPage(
                false,
                'Google would not issue a token: '
                . (string)($token['error_description'] ?? $token['error'] ?? 'unknown error')
            );
        }

        // Without a refresh token the connection dies in an hour and the only
        // symptom is a sync that worked this morning. `prompt=consent` is what
        // makes Google issue one even on a re-consent, so its absence here is
        // a real failure and not something to store and hope about.
        if (empty($token['refresh_token'])) {
            return $this->googleCallbackPage(
                false,
                'Google did not return a refresh token, so the connection could '
                . 'not be kept. Remove RingQ from your Google account permissions '
                . 'and press Connect again.'
            );
        }

        // Refused for exactly the reason a missing refresh token is refused
        // ten lines above: the grant cannot do the job. Revoked immediately --
        // RingQ asked for this grant and is declining it, so leaving it live
        // would be an authorisation nobody can see or remove.
        if (!$this->grantCoversWorkScope($token['scope'] ?? '')) {
            $this->googleRevoke((string)$token['refresh_token']);

            return $this->googleCallbackPage(false, $this->missingScopeMessage());
        }

        [$infoStatus, $info] = $this->httpJson(
            'GET',
            'https://www.googleapis.com/oauth2/v2/userinfo',
            ['bearer' => (string)$token['access_token']]
        );

        if ($infoStatus !== 200 || empty($info['email'])) {
            return $this->googleCallbackPage(false, 'Google would not say which account was connected.');
        }

        $googleEmail = strtolower(trim((string)$info['email']));
        $ringqEmail  = strtolower(trim((string)$session['email']));

        // THE EMAIL GATE.
        //
        // Refused, never stored: a token for another mailbox filed under this
        // user's key produces a per-user store that is not per-user, which is
        // the exact defect this feature exists to avoid. Both addresses are
        // named because "access denied" gives the reader nothing to act on.
        if ($googleEmail !== $ringqEmail) {
            // Revoked immediately. RingQ asked for this grant and is refusing
            // it, so leaving it live would be a token nobody can see or remove.
            $this->googleRevoke((string)$token['refresh_token']);

            return $this->googleCallbackPage(
                false,
                'That Google account is ' . htmlspecialchars($googleEmail, ENT_QUOTES)
                . ', but you are signed in to RingQ as '
                . htmlspecialchars($ringqEmail, ENT_QUOTES)
                . '. Connect the Google account that uses the same address.'
            );
        }

        $existing = $this->readTokens($provider, $session) ?? [];

        $this->writeTokens($provider, $session, [
            'connected_email' => $googleEmail,
            'connected_name'  => (string)($info['name'] ?? ''),
            'access_token'    => (string)$token['access_token'],
            'refresh_token'   => (string)$token['refresh_token'],
            'expires_at'      => time() + (int)($token['expires_in'] ?? 3600),
            'scope'           => (string)($token['scope'] ?? self::GOOGLE_SCOPE),
            'calendar_id'     => (string)($existing['calendar_id'] ?? 'primary'),
            'connected_since' => (string)($existing['connected_since'] ?? date('Y-m-d H:i:s')),
            'last_sync'       => (string)($existing['last_sync'] ?? ''),
            'last_error'      => '',
        ]);

        return $this->googleCallbackPage(
            true,
            'Google Calendar connected as ' . htmlspecialchars($googleEmail, ENT_QUOTES) . '.'
        );

    } catch (\Throwable $e) {
        return $this->googleCallbackPage(false, $e->getMessage());
    }
}

/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/
private function status(string $provider, array $session): array
{
    $settings = $this->tenantSettings($provider, (string)$session['subdomain']);
    $tokens   = $this->readTokens($provider, $session);

    $configured = trim((string)($settings['client_id'] ?? '')) !== ''
        && trim((string)($settings['client_secret'] ?? '')) !== '';

    // Connected requires a REFRESH token, not merely an access token: a record
    // holding only the latter cannot sync in an hour's time, so reporting it as
    // connected turns a missing grant into a mysterious failure later.
    $connected = is_array($tokens)
        && trim((string)($tokens['refresh_token'] ?? '')) !== '';

    return [
        'configured'          => $configured,
        'enabled'             => (int)($settings['enabled'] ?? 0) === 1,
        'connected'           => $connected,
        'email'               => $connected ? (string)($tokens['connected_email'] ?? '') : '',
        'connectedSince'      => $connected ? (string)($tokens['connected_since'] ?? '') : '',
        'lastSync'            => $connected ? (string)($tokens['last_sync'] ?? '') : '',
        'lastError'           => $connected ? (string)($tokens['last_error'] ?? '') : '',
        'linkedCount'         => $connected ? count($this->links($provider, $session)) : 0,
        'redirectUri'         => $this->redirectUri($provider, (string)$session['subdomain']),
        'windowBackDays'      => self::WINDOW_BACK_DAYS,
        'windowForwardMonths' => self::WINDOW_FORWARD_MONTHS,
    ];
}

/*
|--------------------------------------------------------------------------
| CONSENT URL
|--------------------------------------------------------------------------
*/
private function googleConsentUrl(string $provider, array $session)
{
    $settings = $this->tenantSettings($provider, (string)$session['subdomain']);

    if (trim((string)($settings['client_id'] ?? '')) === ''
        || trim((string)($settings['client_secret'] ?? '')) === '') {
        return $this->response->setJsonContent([
            'result'  => 'error',
            'status'  => false,
            'message' => self::PROVIDERS[$provider] . ' is not configured on this server.',
        ]);
    }

    if ((int)($settings['enabled'] ?? 0) !== 1) {
        return $this->response->setJsonContent([
            'result'  => 'error',
            'status'  => false,
            'message' => self::PROVIDERS[$provider] . ' is switched off for this server.',
        ]);
    }

    $params = [
        'client_id'     => (string)$settings['client_id'],
        'redirect_uri'  => $this->redirectUri($provider, (string)$session['subdomain']),
        'response_type' => 'code',
        'scope'         => self::GOOGLE_SCOPE,

        // offline + consent together are what guarantee a refresh token.
        // Google returns one only on a first consent otherwise, so a user who
        // already granted this client for contacts would get an access token
        // that expires in an hour and nothing to renew it with.
        'access_type'   => 'offline',
        'prompt'        => 'consent',

        // Keeps any grant this client already holds -- contacts, SSO -- rather
        // than replacing it with the calendar scope alone.
        'include_granted_scopes' => 'true',

        // Pre-selects the right account, so the email gate is a backstop rather
        // than the commonest outcome.
        'login_hint'    => (string)$session['email'],

        'state'         => $this->issueState(),
    ];

    return $this->response->setJsonContent([
        'result' => 'success',
        'status' => true,
        'url'    => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params),
    ]);
}

/*
|--------------------------------------------------------------------------
| INBOUND -- FETCH
|--------------------------------------------------------------------------
|
| Returns Google's events trimmed to the fields the client's mapper reads. The
| trim is a whitelist at the boundary, matching how `normaliseExternalEvent`
| treats an `.ics`: this data was written by somebody outside the tenant.
|
| Reconciliation is NOT done here. The events go to `schedule/schedule/5`,
| which already owns the UID + sequence rules, the caps, the joinUrl checks and
| the guarantee that an imported event mints no Meet room and mails nobody.
*/
private function googleFetchEvents(string $provider, array $session)
{
    $ready = $this->googleReadyTokens($provider, $session);

    if (!is_array($ready)) {
        return $ready;
    }

    $tokens      = $ready;
    $accessToken = (string)$tokens['access_token'];
    $calendarId  = (string)($tokens['calendar_id'] ?? 'primary');

    $timeMin = (new \DateTimeImmutable('@' . (time() - self::WINDOW_BACK_DAYS * 86400)))
        ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    $timeMax = (new \DateTimeImmutable('@' . time()))
        ->setTimezone(new \DateTimeZone('UTC'))
        ->add(new \DateInterval('P' . self::WINDOW_FORWARD_MONTHS . 'M'))
        ->format('Y-m-d\TH:i:s\Z');

    $events    = [];
    $skipped   = 0;
    $truncated = false;
    $pageToken = '';
    $guard     = 0;

    do {
        $query = [
            'timeMin'      => $timeMin,
            'timeMax'      => $timeMax,

            // Cancellations must arrive, or an event deleted in Google stays in
            // the RingQ calendar forever with nothing to remove it.
            'showDeleted'  => 'true',

            // One row per SERIES, not per occurrence. Expanding would put a
            // daily meeting's 365 instances against a 500-row store, and every
            // instance shares one iCalUID, so a UID-keyed store would collapse
            // them anyway. The RRULE goes to the client's own expander.
            'singleEvents' => 'false',

            'maxResults'   => 250,
        ];

        if ($pageToken !== '') {
            $query['pageToken'] = $pageToken;
        }

        [$status, $body] = $this->httpJson(
            'GET',
            'https://www.googleapis.com/calendar/v3/calendars/'
                . rawurlencode($calendarId) . '/events?' . http_build_query($query),
            ['bearer' => $accessToken]
        );

        if ($status !== 200) {
            return $this->googleApiFailure($provider, $session, $status, $body);
        }

        foreach ((array)($body['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            // THE LOOP GUARD. An event RingQ pushed must never come back as an
            // imported copy of itself, or every sync doubles the calendar.
            if ($this->googleIsOwnEvent($item)) {
                $skipped++;
                continue;
            }

            if (count($events) >= self::MAX_EVENTS) {
                $truncated = true;
                break;
            }

            $events[] = $this->googleTrimEvent($item);
        }

        $pageToken = (string)($body['nextPageToken'] ?? '');

    } while ($pageToken !== '' && !$truncated && $guard++ < 20);

    $tokens['last_sync']  = date('Y-m-d H:i:s');
    $tokens['last_error'] = '';
    $this->writeTokens($provider, $session, $tokens);

    return $this->response->setJsonContent([
        'result'  => 'success',
        'status'  => true,
        'content' => [
            'events'    => $events,
            'skipped'   => $skipped,
            'truncated' => $truncated,
            'max'       => self::MAX_EVENTS,
        ],
    ]);
}

/** True when this Google event is one RingQ pushed. */
private function googleIsOwnEvent(array $item): bool
{
    $private = $item['extendedProperties']['private'] ?? null;

    if (!is_array($private)) {
        return false;
    }

    return trim((string)($private[self::LINK_PROPERTY] ?? '')) !== '';
}

/** One Google event, reduced to the fields the client's mapper reads. */
private function googleTrimEvent(array $item): array
{
    $join = trim((string)($item['hangoutLink'] ?? ''));

    if ($join === '') {
        foreach ((array)($item['conferenceData']['entryPoints'] ?? []) as $entry) {
            if (is_array($entry)
                && (string)($entry['entryPointType'] ?? '') === 'video'
                && trim((string)($entry['uri'] ?? '')) !== '') {
                $join = trim((string)$entry['uri']);
                break;
            }
        }
    }

    $attendees = [];

    foreach (array_slice((array)($item['attendees'] ?? []), 0, 100) as $attendee) {
        if (!is_array($attendee) || trim((string)($attendee['email'] ?? '')) === '') {
            continue;
        }
        $attendees[] = [
            'email' => (string)$attendee['email'],
            'name'  => (string)($attendee['displayName'] ?? ''),
        ];
    }

    $organizer = null;

    if (is_array($item['organizer'] ?? null) && trim((string)($item['organizer']['email'] ?? '')) !== '') {
        $organizer = [
            'email' => (string)$item['organizer']['email'],
            'name'  => (string)($item['organizer']['displayName'] ?? ''),
        ];
    }

    return [
        'id'          => (string)($item['id'] ?? ''),
        'iCalUID'     => (string)($item['iCalUID'] ?? ''),
        'status'      => (string)($item['status'] ?? 'confirmed'),
        'summary'     => (string)($item['summary'] ?? ''),
        'description' => (string)($item['description'] ?? ''),
        'location'    => (string)($item['location'] ?? ''),
        'joinUrl'     => $join,
        'organizer'   => $organizer,
        'attendees'   => $attendees,
        'start'       => $this->googleTrimMoment($item['start'] ?? null),
        'end'         => $this->googleTrimMoment($item['end'] ?? null),
        'recurrence'  => array_values(array_filter(
            array_map('strval', (array)($item['recurrence'] ?? [])),
            static fn (string $rule): bool => $rule !== ''
        )),
        'sequence'    => (int)($item['sequence'] ?? 0),

        // What the client stores as `sequence`, and the reason is not cosmetic:
        // Google bumps its own `sequence` only for iCalendar-significant
        // changes, so a RETITLED event keeps its number and
        // `schedule/schedule/5` would report it `unchanged` forever. `updated`
        // is monotonic per event, so the comparison that endpoint already makes
        // becomes correct.
        'updated'     => (string)($item['updated'] ?? ''),
    ];
}

private function googleTrimMoment($moment): array
{
    if (!is_array($moment)) {
        return [];
    }

    return [
        'dateTime' => (string)($moment['dateTime'] ?? ''),
        'date'     => (string)($moment['date'] ?? ''),
        'timeZone' => (string)($moment['timeZone'] ?? ''),
    ];
}

/*
|--------------------------------------------------------------------------
| OUTBOUND -- RECONCILE TO GOOGLE
|--------------------------------------------------------------------------
|
| The client sends the event BODIES it built from its own schedule rows, and
| this decides what to do with each. The split is deliberate: `startDate` in a
| schedule row is a local-midnight epoch written by the browser, so decoding it
| here would read it in the SERVER's timezone -- observed on real boxes as
| Asia/Shanghai and America/Sao_Paulo -- and land the meeting on the wrong day.
| The client already owns the tz database and the function that renders the row.
*/
private function googlePush(string $provider, array $session)
{
    $ready = $this->googleReadyTokens($provider, $session);

    if (!is_array($ready)) {
        return $ready;
    }

    $tokens      = $ready;
    $accessToken = (string)$tokens['access_token'];
    $calendarId  = (string)($tokens['calendar_id'] ?? 'primary');

    $data = $this->request->getJsonRawBody(true);

    if (!is_array($data)) {
        throw new \Exception("Invalid JSON request");
    }

    $apply = !empty($data['apply']);

    // Deletions happen ONLY when the client says the list is every schedule it
    // holds. A partial list would otherwise delete the Google copies of every
    // schedule it happened to omit, and that reaches outside RingQ.
    $complete = !empty($data['complete']);

    $incoming = $data['schedules'] ?? null;

    if (!is_array($incoming)) {
        throw new \Exception("No schedules were supplied.");
    }

    if (count($incoming) > self::MAX_PUSH) {
        return $this->response->setJsonContent([
            'result'  => 'error',
            'status'  => false,
            'message' => 'That is more than ' . self::MAX_PUSH . ' schedules to sync at once.',
        ]);
    }

    $links   = $this->links($provider, $session);
    $plan    = [];
    $seen    = [];

    foreach ($incoming as $candidate) {
        $body = $this->googleNormaliseOutbound($candidate);

        if (is_string($body)) {
            $plan[] = [
                'scheduleId' => is_array($candidate) ? (string)($candidate['scheduleId'] ?? '') : '',
                'summary'    => is_array($candidate) ? (string)($candidate['summary'] ?? '') : '',
                'action'     => 'rejected',
                'reason'     => $body,
            ];
            continue;
        }

        $scheduleId = (string)$body['scheduleId'];
        $seen[$scheduleId] = true;

        unset($body['scheduleId']);

        // Hashed HERE and not on the client, so there is one definition of
        // "changed" and a client bug cannot silently suppress every update.
        $hash = md5((string)json_encode($body));
        $link = $links[$scheduleId] ?? null;

        if (!is_array($link) || trim((string)($link['googleEventId'] ?? '')) === '') {
            $plan[] = [
                'scheduleId' => $scheduleId,
                'summary'    => (string)($body['summary'] ?? ''),
                'action'     => 'create',
                'body'       => $body,
                'hash'       => $hash,
            ];
            continue;
        }

        if ((string)($link['hash'] ?? '') === $hash) {
            $plan[] = [
                'scheduleId' => $scheduleId,
                'summary'    => (string)($body['summary'] ?? ''),
                'action'     => 'unchanged',
            ];
            continue;
        }

        $plan[] = [
            'scheduleId' => $scheduleId,
            'summary'    => (string)($body['summary'] ?? ''),
            'action'     => 'update',
            'eventId'    => (string)$link['googleEventId'],
            'body'       => $body,
            'hash'       => $hash,
        ];
    }

    if ($complete) {
        foreach ($links as $scheduleId => $link) {
            if (isset($seen[(string)$scheduleId]) || !is_array($link)) {
                continue;
            }
            $plan[] = [
                'scheduleId' => (string)$scheduleId,
                'summary'    => (string)($link['summary'] ?? ''),
                'action'     => 'delete',
                'eventId'    => (string)($link['googleEventId'] ?? ''),
            ];
        }
    }

    if (!$apply) {
        // A plan carries no bodies back to the client: it asked what would
        // happen, not for its own payload returned to it.
        return $this->response->setJsonContent([
            'result'  => 'success',
            'status'  => true,
            'content' => [
                'applied' => false,
                'plan'    => array_map(static function (array $row): array {
                    unset($row['body'], $row['hash']);
                    return $row;
                }, $plan),
            ],
        ]);
    }

    $results = [];

    foreach ($plan as $step) {
        $action = (string)$step['action'];

        if ($action === 'unchanged' || $action === 'rejected') {
            $results[] = [
                'scheduleId' => $step['scheduleId'],
                'summary'    => $step['summary'],
                'action'     => $action,
                'reason'     => (string)($step['reason'] ?? ''),
            ];
            continue;
        }

        $base = 'https://www.googleapis.com/calendar/v3/calendars/'
            . rawurlencode($calendarId) . '/events';

        // sendUpdates=none on every call. There are no attendees to notify --
        // see normaliseOutbound() -- and this makes that true even if a
        // future body ever carries one.
        if ($action === 'create') {
            [$status, $body] = $this->httpJson(
                'POST',
                $base . '?sendUpdates=none',
                ['bearer' => $accessToken, 'json' => $this->googleOutboundBody($step)]
            );

            if ($status === 200 || $status === 201) {
                $this->setLink($provider, $session, (string)$step['scheduleId'], [
                    'googleEventId' => (string)($body['id'] ?? ''),
                    'hash'          => (string)$step['hash'],
                    'summary'       => (string)$step['summary'],
                    'pushedAt'      => time(),
                ]);
                $results[] = ['scheduleId' => $step['scheduleId'], 'summary' => $step['summary'], 'action' => 'created'];
            } else {
                $results[] = $this->googleStepFailure($step, $status, $body);
            }
            continue;
        }

        if ($action === 'update') {
            [$status, $body] = $this->httpJson(
                'PATCH',
                $base . '/' . rawurlencode((string)$step['eventId']) . '?sendUpdates=none',
                ['bearer' => $accessToken, 'json' => $this->googleOutboundBody($step)]
            );

            if ($status === 200) {
                $this->setLink($provider, $session, (string)$step['scheduleId'], [
                    'googleEventId' => (string)$step['eventId'],
                    'hash'          => (string)$step['hash'],
                    'summary'       => (string)$step['summary'],
                    'pushedAt'      => time(),
                ]);
                $results[] = ['scheduleId' => $step['scheduleId'], 'summary' => $step['summary'], 'action' => 'updated'];
                continue;
            }

            // The event is gone from Google -- somebody deleted it there. The
            // RingQ row is the source of truth for a meeting RingQ created, so
            // it is re-created rather than left as a dangling link that fails
            // on every future sync.
            if ($status === 404 || $status === 410) {
                [$createStatus, $created] = $this->httpJson(
                    'POST',
                    $base . '?sendUpdates=none',
                    ['bearer' => $accessToken, 'json' => $this->googleOutboundBody($step)]
                );

                if ($createStatus === 200 || $createStatus === 201) {
                    $this->setLink($provider, $session, (string)$step['scheduleId'], [
                        'googleEventId' => (string)($created['id'] ?? ''),
                        'hash'          => (string)$step['hash'],
                        'summary'       => (string)$step['summary'],
                        'pushedAt'      => time(),
                    ]);
                    $results[] = ['scheduleId' => $step['scheduleId'], 'summary' => $step['summary'], 'action' => 'recreated'];
                } else {
                    $results[] = $this->googleStepFailure($step, $createStatus, $created);
                }
                continue;
            }

            $results[] = $this->googleStepFailure($step, $status, $body);
            continue;
        }

        if ($action === 'delete') {
            [$status, $body] = $this->httpJson(
                'DELETE',
                $base . '/' . rawurlencode((string)$step['eventId']) . '?sendUpdates=none',
                ['bearer' => $accessToken]
            );

            // 404/410 means it is already gone, which is the state being asked
            // for. Dropping the link either way is what stops a dead id being
            // retried on every sync from now on.
            if ($status === 204 || $status === 200 || $status === 404 || $status === 410) {
                $this->dropLink($provider, $session, (string)$step['scheduleId']);
                $results[] = ['scheduleId' => $step['scheduleId'], 'summary' => $step['summary'], 'action' => 'removed'];
            } else {
                $results[] = $this->googleStepFailure($step, $status, $body);
            }
        }
    }

    $tokens['last_sync']  = date('Y-m-d H:i:s');
    $tokens['last_error'] = '';
    $this->writeTokens($provider, $session, $tokens);

    return $this->response->setJsonContent([
        'result'  => 'success',
        'status'  => true,
        'content' => [
            'applied' => true,
            'plan'    => $results,
        ],
    ]);
}

/** The Google event body for a planned step.
 *
 * `attendees` is DELIBERATELY ABSENT. Adding one makes Google email that person,
 * on top of the invitation RingQ already queues through `email_queue` type
 * `schedule` -- so every invitee would receive two, one of them from a Google
 * account they do not recognise, and only the RingQ one carries the Meet link.
 * The same hazard `ScheduleController` type 5 guards against from the other
 * direction. Revisit as a product decision, not as a bug.
 */
private function googleOutboundBody(array $step): array
{
    $body = (array)$step['body'];

    $body['extendedProperties'] = [
        'private' => [
            self::LINK_PROPERTY => (string)$step['scheduleId'],
        ],
    ];

    return $body;
}

/** One outbound schedule, whitelisted. Returns the body, or a string saying
 * why it was refused so the preview can name the row it dropped. */
private function googleNormaliseOutbound($candidate)
{
    if (!is_array($candidate)) {
        return 'it was not readable';
    }

    $scheduleId = trim((string)($candidate['scheduleId'] ?? ''));

    if ($scheduleId === '' || strlen($scheduleId) > 255) {
        return 'it carries no usable id';
    }

    $start = $this->googleNormaliseMoment($candidate['start'] ?? null);
    $end   = $this->googleNormaliseMoment($candidate['end'] ?? null);

    if ($start === null || $end === null) {
        return 'its date could not be read';
    }

    $summary = trim((string)($candidate['summary'] ?? ''));

    if ($summary === '') {
        $summary = 'RingQ meeting';
    }

    $description = (string)($candidate['description'] ?? '');
    $joinUrl     = $this->safeUrl($candidate['joinUrl'] ?? null);

    // The join link goes in the description because no attendees are sent, so
    // the body is the only place the reader of their own calendar will find it.
    if ($joinUrl !== null && strpos($description, $joinUrl) === false) {
        $description = trim($description . "\n\nJoin: " . $joinUrl);
    }

    return [
        'scheduleId'  => $scheduleId,
        'summary'     => mb_substr($summary, 0, 300),
        'description' => mb_substr($description, 0, 4000),
        'location'    => mb_substr((string)($candidate['location'] ?? ''), 0, 300),
        'start'       => $start,
        'end'         => $end,
        'reminders'   => ['useDefault' => true],
    ];
}

/** A Google `start`/`end`, either an all-day date or an offset instant paired
 * with the zone it should be read in. Both come from the client, which is the
 * only side that knows the reader's zone database. */
private function googleNormaliseMoment($moment): ?array
{
    if (!is_array($moment)) {
        return null;
    }

    $date = trim((string)($moment['date'] ?? ''));

    if ($date !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        return ['date' => $date];
    }

    $dateTime = trim((string)($moment['dateTime'] ?? ''));

    // RFC3339 with an explicit offset. An offsetless value would be read by
    // Google in the calendar's own zone, which is not necessarily the zone the
    // schedule was written in.
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})$/', $dateTime)) {
        return null;
    }

    $out  = ['dateTime' => $dateTime];
    $zone = trim((string)($moment['timeZone'] ?? ''));

    if ($zone !== '') {
        try {
            new \DateTimeZone($zone);
            $out['timeZone'] = $zone;
        } catch (\Throwable $e) {
            // An unresolvable zone is dropped, never guessed at: the instant is
            // already unambiguous from the offset, and inventing a zone is how
            // a meeting moves.
        }
    }

    return $out;
}

/*
|--------------------------------------------------------------------------
| DISCONNECT
|--------------------------------------------------------------------------
*/
private function googleDisconnect(string $provider, array $session)
{
    $tokens = $this->readTokens($provider, $session);

    if (!is_array($tokens)) {
        return $this->response->setJsonContent([
            'result'  => 'success',
            'status'  => true,
            'message' => self::PROVIDERS[$provider] . ' was not connected.',
        ]);
    }

    $removed = 0;

    // Remove what RingQ put there, while the token is still valid, and nothing
    // else. Leaving them behind means RingQ meetings appear in somebody's Google
    // calendar forever with no control anywhere that can remove them. Every
    // step is best-effort: a failure here must not trap the user in a connected
    // state they are trying to leave.
    $accessToken = $this->googleRefreshIfNeeded($provider, $session, $tokens);

    if ($accessToken !== null) {
        $calendarId = (string)($tokens['calendar_id'] ?? 'primary');

        foreach ($this->links($provider, $session) as $scheduleId => $link) {
            $eventId = is_array($link) ? trim((string)($link['googleEventId'] ?? '')) : '';

            if ($eventId === '') {
                continue;
            }

            [$status] = $this->httpJson(
                'DELETE',
                'https://www.googleapis.com/calendar/v3/calendars/'
                    . rawurlencode($calendarId) . '/events/'
                    . rawurlencode($eventId) . '?sendUpdates=none',
                ['bearer' => $accessToken]
            );

            if ($status === 204 || $status === 200 || $status === 404 || $status === 410) {
                $removed++;
            }
        }
    }

    $this->googleRevoke((string)($tokens['refresh_token'] ?? ''));
    $this->clearTokens($provider, $session);

    return $this->response->setJsonContent([
        'result'  => 'success',
        'status'  => true,
        'message' => $removed === 0
            ? self::PROVIDERS[$provider] . ' disconnected.'
            : self::PROVIDERS[$provider] . ' disconnected, and ' . $removed
                . ($removed === 1 ? ' RingQ meeting was' : ' RingQ meetings were')
                . ' removed from it.',
        'content' => ['removed' => $removed],
    ]);
}

/*
|--------------------------------------------------------------------------
| TOKENS
|--------------------------------------------------------------------------
*/

/** A valid access token plus the record it belongs to, or a Response saying
 * why not. Callers test with `is_array()`. */
private function googleReadyTokens(string $provider, array $session)
{
    $settings = $this->tenantSettings($provider, (string)$session['subdomain']);

    if (trim((string)($settings['client_id'] ?? '')) === ''
        || trim((string)($settings['client_secret'] ?? '')) === '') {
        return $this->response->setJsonContent([
            'result'  => 'error',
            'status'  => false,
            'message' => self::PROVIDERS[$provider] . ' is not configured on this server.',
        ]);
    }

    $tokens = $this->readTokens($provider, $session);

    if (!is_array($tokens) || trim((string)($tokens['refresh_token'] ?? '')) === '') {
        return $this->response->setJsonContent([
            'result'    => 'error',
            'status'    => false,
            'reconnect' => true,
            'message'   => self::PROVIDERS[$provider] . ' is not connected.',
        ]);
    }

    // Re-checked from storage on every sync, at no cost. This catches the case
    // that matters -- an admin changing this user's RingQ email after the token
    // was minted -- without a `userinfo` round trip. Google's own primary
    // address changing is not a safety question: the token still belongs to the
    // person who granted it.
    $stored = strtolower(trim((string)($tokens['connected_email'] ?? '')));
    $ringq  = strtolower(trim((string)$session['email']));

    if ($stored === '' || $stored !== $ringq) {
        return $this->response->setJsonContent([
            'result'    => 'error',
            'status'    => false,
            'reconnect' => true,
            'message'   => 'This Google Calendar connection is for ' . $stored
                . ', but you are signed in to RingQ as ' . $ringq
                . '. Reconnect with the matching Google account.',
        ]);
    }

    // A grant stored before this check existed, or one whose console scopes
    // were narrowed after it was minted. Refused here, naming what to change,
    // rather than letting Google's own "Request had insufficient authentication
    // scopes" reach a reader who cannot act on it. Recorded as `last_error` so
    // the calendar strip shows the same sentence without a press.
    if (!$this->grantCoversWorkScope($tokens['scope'] ?? '')) {
        $tokens['last_error'] = $this->missingScopeMessage();
        $this->writeTokens($provider, $session, $tokens);

        return $this->response->setJsonContent([
            'result'    => 'error',
            'status'    => false,
            'reconnect' => true,
            'message'   => $this->missingScopeMessage(),
        ]);
    }

    $accessToken = $this->googleRefreshIfNeeded($provider, $session, $tokens);

    if ($accessToken === null) {
        return $this->response->setJsonContent([
            'result'    => 'error',
            'status'    => false,
            'reconnect' => true,
            'message'   => 'Google would not renew the connection. Press Connect to grant access again.',
        ]);
    }

    $tokens['access_token'] = $accessToken;

    return $tokens;
}

/** Refreshes [$tokens] in place when it is about to expire. Returns the usable
 * access token, or null when the grant is gone for good. */
private function googleRefreshIfNeeded(string $provider, array $session, array &$tokens): ?string
{
    $accessToken = trim((string)($tokens['access_token'] ?? ''));
    $expiresAt   = (int)($tokens['expires_at'] ?? 0);

    // 60 seconds of slack: a token that expires mid-request is a failure that
    // looks random.
    if ($accessToken !== '' && $expiresAt > time() + 60) {
        return $accessToken;
    }

    $settings = $this->tenantSettings($provider, (string)$session['subdomain']);

    [$status, $body] = $this->httpJson(
        'POST',
        'https://oauth2.googleapis.com/token',
        ['form' => [
            'client_id'     => (string)($settings['client_id'] ?? ''),
            'client_secret' => (string)($settings['client_secret'] ?? ''),
            'refresh_token' => (string)($tokens['refresh_token'] ?? ''),
            'grant_type'    => 'refresh_token',
        ]]
    );

    if ($status === 200 && !empty($body['access_token'])) {
        $tokens['access_token'] = (string)$body['access_token'];
        $tokens['expires_at']   = time() + (int)($body['expires_in'] ?? 3600);
        $tokens['last_error']   = '';
        $this->writeTokens($provider, $session, $tokens);
        return (string)$body['access_token'];
    }

    $error = (string)($body['error'] ?? '');

    // `invalid_grant` is the end of the road -- consent revoked, password
    // changed, or roughly six months idle. The record is cleared so the UI shows
    // Connect rather than an opaque failure on every future sync. Anything else
    // may be transient, so the tokens are kept and only the reason recorded.
    if ($error === 'invalid_grant') {
        $this->clearTokens($provider, $session);
        return null;
    }

    $tokens['last_error'] = $error !== '' ? $error : 'Google did not renew the connection.';
    $this->writeTokens($provider, $session, $tokens);

    return null;
}

private function googleRevoke(string $refreshToken): void
{
    if (trim($refreshToken) === '') {
        return;
    }

    $this->httpJson(
        'POST',
        'https://oauth2.googleapis.com/revoke',
        ['form' => ['token' => $refreshToken]]
    );
}

/*
|--------------------------------------------------------------------------
| STORAGE
|--------------------------------------------------------------------------
*/
/** The tenant configuration key.
 *
 * THE ONLY PLACE `SETTINGS_PREFIX` IS COMPOSED, and the guard counts it.
 * The write site used to build this by hand and omitted the provider, so a save
 * landed on `calendar_settings:<subdomain>` while every read looked for
 * `calendar_settings:<provider>:<subdomain>`: configuration reported saved and
 * could never be read back, and nothing static caught it because that line
 * never mentioned a provider to get wrong. Same rule as `redirectUri`.
 */
private function settingsKey(string $provider, string $subdomain): string
{
    return self::SETTINGS_PREFIX . $provider . ':' . $subdomain;
}

private function tenantSettings(string $provider, string $subdomain): array
{
    $raw = $this->redis->get($this->settingsKey($provider, $subdomain));

    if (empty($raw)) {
        return [];
    }

    $decoded = json_decode((string)$raw, true);

    return is_array($decoded) ? $decoded : [];
}

/** What the settings dialog is allowed to see.
 *
 * The secret is never returned. `googlesettingsAction` does return its own;
 * that is pre-existing and not a pattern to copy into a new endpoint.
 */
private function settingsView(string $provider, string $subdomain, array $settings): array
{
    return [
        'enabled'            => (int)($settings['enabled'] ?? 0) === 1 ? 1 : 0,
        'client_id'          => (string)($settings['client_id'] ?? ''),
        'client_secret_set'  => trim((string)($settings['client_secret'] ?? '')) !== '',
        'redirect_uri'       => $this->redirectUri($provider, $subdomain),
        'scope'              => $this->providerScope($provider),
    ];
}

/** Keyed on user_uuid, not extension: an extension can be reassigned and a
 * token is a person's credential. */
/** The OAuth scope [$provider] is asked for, so the settings dialog can tell an
 * admin which API to enable. */
private function providerScope(string $provider): string
{
    if ($provider === 'google') {
        return self::GOOGLE_SCOPE;
    }

    return '';
}

private function tokenKey(string $provider, array $session): string
{
    return self::TOKEN_PREFIX . $provider . ':' . $session['domain_uuid'] . ':' . $session['user_uuid'];
}

private function linkKey(string $provider, array $session): string
{
    return self::LINK_PREFIX . $provider . ':' . $session['domain_uuid'] . ':' . $session['user_uuid'];
}

private function readTokens(string $provider, array $session): ?array
{
    $raw = $this->redis->get($this->tokenKey($provider, $session));

    if (empty($raw)) {
        return null;
    }

    $decoded = json_decode((string)$raw, true);

    return is_array($decoded) ? $decoded : null;
}

private function writeTokens(string $provider, array $session, array $tokens): void
{
    $this->redis->set($this->tokenKey($provider, $session), json_encode($tokens));
}

private function clearTokens(string $provider, array $session): void
{
    $this->redis->del($this->tokenKey($provider, $session));
    $this->redis->del($this->linkKey($provider, $session));
}

/** `ringqScheduleId => {googleEventId, hash, summary, pushedAt}`.
 *
 * A hash of its own, deliberately NOT a field on the schedule row:
 * `schedule/schedule/2` replaces the row wholesale, so a client that omitted
 * the id on one edit would orphan the Google event and every later sync would
 * insert a duplicate.
 */
private function links(string $provider, array $session): array
{
    $raw = $this->redis->hgetall($this->linkKey($provider, $session));

    if (!is_array($raw)) {
        return [];
    }

    $out = [];

    foreach ($raw as $scheduleId => $encoded) {
        $decoded = json_decode((string)$encoded, true);

        if (is_array($decoded)) {
            $out[(string)$scheduleId] = $decoded;
        }
    }

    return $out;
}

private function setLink(string $provider, array $session, string $scheduleId, array $link): void
{
    $this->redis->hset($this->linkKey($provider, $session), $scheduleId, json_encode($link));
}

private function dropLink(string $provider, array $session, string $scheduleId): void
{
    $this->redis->hdel($this->linkKey($provider, $session), [$scheduleId]);
}

/*
|--------------------------------------------------------------------------
| SESSION, STATE, HTTP
|--------------------------------------------------------------------------
*/

/** Every field this feature needs, checked once.
 *
 * `email` is `v_users.user_email` (LoginController writes it), which is the same
 * column `googleSSO()` matches a Google address against -- so the email gate is
 * a comparison against the live session and never a lookup. Deliberately unlike
 * that query, which has no `domain_uuid` filter and is a cross-tenant resolver.
 */
private function requireSession(): array
{
    $session = $this->session->get("auth") ?? [];

    foreach (['subdomain', 'domain_uuid', 'user_uuid', 'email'] as $field) {
        if (empty($session[$field])) {
            throw new \Exception("Invalid session");
        }
    }

    return $session;
}

/** True when the grant Google actually issued can read and write events.
 *
 * THE SCOPE ASKED FOR IS NOT THE SCOPE GRANTED. Google silently drops any
 * scope that is not registered on the consent screen: it issues a token
 * without it, `token_response.scope` names only what was granted, and nothing
 * fails until the first Calendar call answers 403 `Request had insufficient
 * authentication scopes`. Observed live on 2026-09-08 — a consent screen
 * listing the broad `auth/calendar` instead of `calendar.events` produced a
 * grant of `openid userinfo.email` and nothing else.
 *
 * The connection still LOOKED successful, because the email gate needs only
 * the identity scope and that one was granted. Same shape as the refresh-token
 * check above it: a grant that cannot do the job must not be stored as though
 * it can, or a missing permission becomes a mysterious failure later.
 *
 * Compared token by token, never with `strpos`: `calendar.events` is a prefix
 * of `calendar.events.readonly` and `calendar.events.owned`, both of which are
 * offered in Google's own scope picker and neither of which can write.
 *
 * An EMPTY scope is treated as satisfied. Every write path stores a non-empty
 * value, so empty means an unexpected record rather than an observed refusal,
 * and disconnecting a working user over a shape nobody has seen is the worse
 * failure.
 */
private function grantCoversWorkScope($scope): bool
{
    $granted = preg_split('/\s+/', trim((string)$scope), -1, PREG_SPLIT_NO_EMPTY);

    if (!$granted) {
        return true;
    }

    return in_array(self::GOOGLE_WORK_SCOPE, $granted, true);
}

/** What to tell an admin whose consent screen is missing the working scope. */
private function missingScopeMessage(): string
{
    return 'Google did not grant calendar access, so this connection cannot '
        . 'read or write events. Add ' . self::GOOGLE_WORK_SCOPE . ' to this '
        . "OAuth client's scopes in the Google Cloud Console (APIs & Services "
        . '-> Data Access), save, then press Connect again — an existing grant '
        . 'never picks up a scope added afterwards.';
}

/** The one place this URI is built.
 *
 * The settings dialog displays this, the consent URL sends it and the token
 * exchange repeats it. Google compares them byte for byte, so they must come
 * from one function or a mismatch is a `redirect_uri_mismatch` page that never
 * mentions RingQ.
 */
private function redirectUri(string $provider, string $subdomain): string
{
    return 'https://' . $subdomain . ':8443/calendar/callback/' . $provider;
}

private function issueState(): string
{
    $nonce = bin2hex(random_bytes(16));

    $this->session->set(self::STATE_SESSION_KEY, [
        'nonce' => $nonce,
        'at'    => time(),
    ]);

    return $nonce;
}

/** True when [$state] is the nonce this session issued, within its lifetime.
 *
 * Single use: consumed whether or not it matched, so a replay cannot succeed
 * after a failure, and `hash_equals` because a timing-safe compare costs
 * nothing here.
 */
private function consumeState(string $state): bool
{
    $issued = $this->session->get(self::STATE_SESSION_KEY);
    $this->session->remove(self::STATE_SESSION_KEY);

    if (!is_array($issued)) {
        return false;
    }

    $nonce = (string)($issued['nonce'] ?? '');
    $at    = (int)($issued['at'] ?? 0);

    if ($nonce === '' || $state === '') {
        return false;
    }

    if ($at <= 0 || time() - $at > self::STATE_TTL_SECONDS) {
        return false;
    }

    return hash_equals($nonce, $state);
}

/** One HTTP call to Google. Returns `[status, decoded body]`.
 *
 * Bounded on both timeouts, always: an unbounded curl on a request path is
 * exactly what took hosted activation down from outside Singapore.
 */
private function httpJson(string $method, string $url, array $options = []): array
{
    $headers = ['Accept: application/json'];

    if (!empty($options['bearer'])) {
        $headers[] = 'Authorization: Bearer ' . (string)$options['bearer'];
    }

    $curl = curl_init();

    $config = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 20,
    ];

    if (isset($options['form'])) {
        $config[CURLOPT_POSTFIELDS] = http_build_query((array)$options['form']);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    } elseif (isset($options['json'])) {
        $config[CURLOPT_POSTFIELDS] = (string)json_encode((array)$options['json']);
        $headers[] = 'Content-Type: application/json';
    }

    $config[CURLOPT_HTTPHEADER] = $headers;

    curl_setopt_array($curl, $config);

    $raw    = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error  = curl_errno($curl) !== 0 ? curl_error($curl) : '';

    curl_close($curl);

    if ($error !== '') {
        return [0, ['error' => 'transport', 'error_description' => $error]];
    }

    $decoded = json_decode((string)$raw, true);

    return [$status, is_array($decoded) ? $decoded : []];
}

/** A Google API failure the reader can act on, and a reason kept on the record
 * so the status endpoint can report it later. */
private function googleApiFailure(string $provider, array $session, int $status, array $body)
{
    $reason = (string)($body['error']['message'] ?? $body['error_description'] ?? $body['error'] ?? '');

    if ($reason === '') {
        $reason = 'Google answered ' . $status . '.';
    }

    $tokens = $this->readTokens($provider, $session);

    if (is_array($tokens)) {
        $tokens['last_error'] = $reason;
        $this->writeTokens($provider, $session, $tokens);
    }

    return $this->response->setJsonContent([
        'result'    => 'error',
        'status'    => false,
        // 401/403 after a successful refresh means the grant no longer covers
        // what is being asked, which only a new consent fixes.
        'reconnect' => $status === 401 || $status === 403,
        'message'   => $reason,
    ]);
}

private function googleStepFailure(array $step, int $status, array $body): array
{
    $reason = (string)($body['error']['message'] ?? $body['error_description'] ?? $body['error'] ?? '');

    return [
        'scheduleId' => (string)$step['scheduleId'],
        'summary'    => (string)$step['summary'],
        'action'     => 'failed',
        'reason'     => $reason !== '' ? $reason : ('Google answered ' . $status . '.'),
    ];
}

/** A URL worth putting in an event body, or null.
 *
 * Scheme and host are both required, exactly one test each -- the same rule
 * `ScheduleController::externalSafeUrl` documents: a scheme test alone passes
 * `https:`, and a host test alone passes `ftp://host/x`.
 */
private function safeUrl($value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $url = trim($value);

    if ($url === '' || strlen($url) > 2000) {
        return null;
    }

    $parts = parse_url($url);

    if ($parts === false) {
        return null;
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));

    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }

    if (trim((string)($parts['host'] ?? '')) === '') {
        return null;
    }

    return $url;
}

/** The page Google's redirect lands on. A human is reading this tab, so it says
 * what happened and then returns them to the app. */
private function googleCallbackPage(bool $ok, string $message)
{
    $title = $ok ? 'Google Calendar connected' : 'Google Calendar not connected';
    $tint  = $ok ? '#0A7D5A' : '#B42318';

    return $this->response->setContent('
        <html>
        <head>
            <meta charset="UTF-8">
            <title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>
            <script>
                setTimeout(function() {
                    // Opened from the calendar with window.open, so closing is
                    // the right ending. A tab this script did not open cannot
                    // be closed, hence the redirect behind it.
                    window.close();
                    window.location.href = window.location.protocol + "//"
                        + window.location.hostname + "/cloudapp";
                }, ' . ($ok ? '1500' : '6000') . ');
            </script>
        </head>
        <body>
            <div style="text-align:center; margin-top:80px; font-family:Arial, sans-serif;">
                <h3 style="color:' . $tint . ';">' . htmlspecialchars($title, ENT_QUOTES) . '</h3>
                <p style="max-width:520px; margin:12px auto; color:#344054;">'
                    . htmlspecialchars($message, ENT_QUOTES) . '</p>
                <p style="color:#667085; font-size:12px;">You can close this tab.</p>
            </div>
        </body>
        </html>
    ');
}
}
