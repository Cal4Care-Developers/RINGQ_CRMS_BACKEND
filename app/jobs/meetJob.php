<?php
/**
 * meetJob.php — RingQ Meet: room links, invitations and the SMTP relay.
 *
 * Consolidated out of worker.php: handleMeetingNotification and everything it
 * reaches — the room/link resolvers, the whole mail builder, the ICS generator,
 * the timezone helpers, and the six MEET_SMTP_* constants.
 *
 * MainTask::scheduledMeetsTokenAction and ::webinarRemindersAction stay where they
 * are; cron calls both by name.
 *
 * processIpChangeOtp() DELIBERATELY STAYED IN worker.php. It is the network-change
 * verification code, not Meet — it only borrows this file's mail builder, which is
 * why it sat in the middle of this block. handleMeetingNotification() still
 * dispatches to it and that call still resolves, because both files are loaded
 * before anything runs.
 *
 * THE SMTP CONSTANTS MUST BE REACHABLE BEFORE THE LOOP RUNS. They were once
 * declared below `while (true)` in worker.php: a top-level define() there is a
 * runtime statement execution never reaches, PHP evaluated each bare name as its
 * own string, Swift rejected "MEET_SMTP_FROM_EMAIL" as an invalid mailbox, and
 * EVERY Meet email silently fell back to Library\Email — which hardcodes
 * `text/calendar; method=REQUEST` and allows one attachment, i.e. the exact reason
 * the .ics was never visible. The relay built to fix that had never once run.
 * Requiring this file after the loop would reproduce it precisely.
 *
 * Declares functions and constants only. No top-level statements: this is loaded
 * while the worker is still wiring itself up, before $redis exists.
 */

// `use` is FILE-SCOPED: the imports in worker.php and MainTask.php do NOT reach a
// required file. Without these lines the code below resolves e.g. `Column` to the
// global `\Column`, which does not exist, and every tick dies with
// "Class 'Column' not found" -- php -l cannot see it and loading the file cannot
// either, because it only fails when the line actually runs.
use Library\Email;

define('MEET_SMTP_HOST', 'mail.smtp2go.com');
define('MEET_SMTP_PORT', 2525);
define('MEET_SMTP_USERNAME', 'ringqconslole');
define('MEET_SMTP_PASSWORD', 'mTmzd46Pg8KI3l2m');
define('MEET_SMTP_FROM_EMAIL', 'noreply@ringq.com');
define('MEET_SMTP_FROM_NAME', 'RingQ');

// apiringq answers with the SMTP providers in the order set on its SMTP Provider
// Priority page. The MEET_SMTP_* relay above is only the last resort. Reached
// through licensekey.ringq.com, like /process/email: servers cannot reach
// apiringq directly.
define('MEET_SMTP_PROVIDERS_URL', 'https://licensekey.ringq.com/process/providers');

// Each send is reported here so it appears in apiringq's Smtp Logs.
define('MEET_SMTP_REPORT_URL', 'https://licensekey.ringq.com/process/smtplog');

// The one string that says "this link belongs to the host". Mirrored by
// MeetLaunchRequest.fromUri in the Flutter client; change it in both or a host
// link silently becomes a guest link.
define('MEET_HOST_LINK_MARKER', 'as=host');

function handleMeetingNotification($redis, $itemJson) {
    $job = json_decode($itemJson, true);
    if (!$job) return;

    $type = $job['type'] ?? '';
    $data = $job['data'] ?? [];

    $email = new Email();
    $getLicenseConfig = $redis->get('ringq_config');
    $licenseConfig = json_decode($getLicenseConfig, true);

    switch ($type) {
        case 'schedule':
            meet_processSchedule($redis, $email, $data, $licenseConfig);
            break;
        case 'webinar':
            $webinarId = $job['webinarId'] ?? '';
            meet_processWebinar($redis, $email, $data, $webinarId, $licenseConfig);
            break;
        case 'webinar_created':
            $webinarId = $job['webinarId'] ?? '';
            // The tenant hostname travels with the job: at creation there is no
            // stored participant link to take it out of, which is where every
            // other flow gets it.
            meet_processWebinarCreated($redis, $email, $webinarId, $job['host'] ?? '', $licenseConfig);
            break;
        case 'booking':
            $bookingId = $job['bookingId'] ?? '';
            $ownerExtension = $job['ownerExtension'] ?? '';
            $ownerDomainUuid = $job['ownerDomainUuid'] ?? '';
            $ownerDomain = $job['ownerDomain'] ?? '';
            $userType = $job['userType'] ?? '';
            meet_processBooking($redis, $email, $data, $bookingId, $ownerExtension, $ownerDomain, $ownerDomainUuid, $licenseConfig, $userType);
            break;
        case 'booking_cancellation':
            $bookingId = $job['bookingId'] ?? '';
            $ownerExtension = $job['ownerExtension'] ?? '';
            $ownerDomainUuid = $job['ownerDomainUuid'] ?? '';
            $ownerDomain = $job['ownerDomain'] ?? '';
            meet_processBookingCancellation($redis, $email, $data, $bookingId, $ownerExtension, $ownerDomain, $ownerDomainUuid, $licenseConfig);
            break;
        case 'webinar_reminder':
            $webinarId = $job['webinarId'] ?? '';
            meet_processWebinarReminder($redis, $email, $data, $webinarId, $licenseConfig);
            break;
        // Not a meeting notification. `email_queue` is the generic mail queue
        // -- eight producers in AccountsController alone -- and this switch is
        // where it is dispatched, so a new mail type belongs here. The function
        // is NOT renamed to match: renaming it would mean editing the call site
        // inside the main loop for no behavioural gain.
        case 'ip_change_otp':
            processIpChangeOtp($redis, $email, $data, $licenseConfig);
            break;
        case 'public_ip_changed':
            processPublicIpChanged($redis, $email, $data, $licenseConfig);
            break;
        case 'acl_change_otp':
            processAclChangeOtp($redis, $email, $data, $licenseConfig);
            break;
        case 'update_otp':
            processUpdateOtp($redis, $email, $data, $licenseConfig);
            break;
        case 'sipprofile_otp':
            processSipProfileOtp($redis, $email, $data, $licenseConfig);
            break;
        case 'mcp_grant_otp':
            processMcpGrantOtp($redis, $email, $data, $licenseConfig);
            break;
        case 'mail_test':
            meet_processMailTest($redis, $email, $data, $licenseConfig);
            break;
    }
}



function meet_processMailTest($redis, $email, $data, $licenseConfig) {
    $to = trim((string)($data['email'] ?? ''));
    $fqdn = (string)($data['fqdn'] ?? '');
    $id = (string)($data['id'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || $fqdn === '') {
        echo "[Mail test] dropping job with no recipient or host\n";
        return;
    }
    $html = meet_mailShell([
        'title' => 'RingQ test email',
        'intro' => meet_mailIntro(strstr($to, '@', true) ?: 'there', 'This is a test email from <strong>' . htmlspecialchars($fqdn) . '</strong>, sent to check that the server can deliver mail. You do not need to do anything.'),
        'rows' => meet_mailRow('@', 'Server', htmlspecialchars($fqdn)) . meet_mailRow('#', 'Requested', gmdate('Y-m-d H:i', (int)($data['requestedAt'] ?? time())) . ' UTC'),
        'recipient' => $to,
        'preheader' => 'RingQ mail delivery test for ' . $fqdn . '.',
    ]);
    $result = ['via' => 'none'];
    $error = '';
    try {
        $result = meet_deliver($email, $to, 'RingQ test email from ' . $fqdn, 'custom', $html, null, $licenseConfig);
    } catch (\Throwable $e) {
        $error = substr($e->getMessage(), 0, 300);
    }
    try {
        $redis->setex('mailTest:' . $fqdn, 604800, json_encode([
            'id' => $id,
            'requestedAt' => (int)($data['requestedAt'] ?? 0),
            'at' => time(),
            'to' => $to,
            'via' => (string)($result['via'] ?? 'none'),
            'provider' => (string)($result['provider'] ?? ''),
            'error' => $error,
        ]));
    } catch (\Throwable $e) {
    }
    echo "[Mail test] sent to {$to} for {$fqdn} via " . ($result['via'] ?? 'none') . ($error !== '' ? " failed: {$error}" : '') . "\n";
}

/**
 * The link an invitation should carry, opening the room if it is not open yet.
 *
 * Two steps in one place, because doing them separately is exactly how three of
 * the five mail flows came to skip the first. A booking invitation carried the
 * opaque reference for a meeting that had never been created, so the lobby
 * answered *"No meeting matches that link or code"* -- and to the GUEST as much
 * as to the host, since the two links differ only by the host marker. Measured
 * on the test host: `GET /rooms/32c8c55e-...` returned `{"exists":false}` and
 * `meet_meetings` held no row for it.
 *
 * Opening first is what puts the short code in the mail. The stored/reference
 * form is the fallback for an unknown host or a control plane that does not
 * answer, because an invitation carrying a longer link beats one that never
 * goes out -- and `POST /rooms` is idempotent on the external reference, so a
 * flow that runs per recipient pays for the room once.
 */
function meetInvitationLink(
    $redis,
    $ringqmeetId,
    $host,
    $title,
    $hostEmail,
    $hostName,
    $domainUuid = '',
    $storedLink = ''
) {
    $opened = meetCreateRoomLink($redis, $ringqmeetId, $host, $title, $hostEmail, $hostName);
    if ($opened !== '') return $opened;
    return meetShareableLink($ringqmeetId, $host, $domainUuid, $storedLink);
}


/**
 * A meeting link addressed to one recipient.
 *
 * Appends `name` and `email` so the lobby can fill its own fields in and the
 * guest only has to press the button. Both are a PREFILL: nothing authenticates
 * them, the control plane never reads them, and the fields they fill stay
 * editable.
 *
 * Two consequences, stated because a URL is not a private channel and both are
 * the operator's call rather than an oversight:
 *
 *  - nginx's default log format writes the full request line, so these land in
 *    access.log -- and in the `Referer` of every asset the page then fetches;
 *  - a forwarded invitation arrives pre-filled as the person it was sent to.
 *
 * Composed here rather than at each call site, and after meetHostLink, so the
 * separator is decided once: a host link already carries `?as=host`.
 */
function meetAddressedLink($link, $name, $email)
{
    $link = trim((string)$link);
    if ($link === '') return '';

    $parameters = [];
    $name = trim((string)$name);
    $email = trim((string)$email);
    if ($name !== '') $parameters['name'] = $name;
    if ($email !== '') $parameters['email'] = $email;
    if (!$parameters) return $link;

    return $link
        . (strpos($link, '?') === false ? '?' : '&')
        . http_build_query($parameters);
}

function meetWebinarInviteToken($redis, $webinarId, $participantId)
{
    $webinarId = trim((string)$webinarId);
    $participantId = trim((string)$participantId);
    if ($webinarId === '' || $participantId === '') return '';
    try {
        $token = $redis->hget('webinar_invite_tokens:' . $webinarId, $participantId);
    } catch (\Throwable $e) {
        return '';
    }
    return is_string($token) ? trim($token) : '';
}

function meetInviteLink($link, $token)
{
    $link = trim((string)$link);
    $token = trim((string)$token);
    if ($link === '' || $token === '') return $link;
    return $link
        . (strpos($link, '?') === false ? '?' : '&')
        . http_build_query(['invite' => $token]);
}

/**
 * The meeting link written into a HOST's email, as opposed to a participant's.
 *
 * The two differ by one query parameter and nothing else. `?as=host` tells the
 * client to *ask* for a host token; it grants nothing. The control plane is the
 * authority and already enforces ownership -- `POST /rooms/:id/token` answers
 * 403 "Not the host of this meeting" when the caller does not own the room, and
 * 401 when there is no session at all -- so a forwarded host link, or an .ics
 * whose LOCATION carries the marker, lands the recipient in exactly the guest
 * lobby they get today. Every failure degrades to current behaviour; none of it
 * reaches an error screen.
 *
 * One construction site on purpose: three flows compose a host link and none of
 * them should be building a query string of its own.
 */
function meetHostLink($link)
{
    $link = trim((string)$link);
    if ($link === '') return '';
    // A legacy stored link still carries `?room=...`, so the separator has to be
    // chosen rather than assumed.
    if (strpos($link, MEET_HOST_LINK_MARKER) !== false) return $link;
    return $link . (strpos($link, '?') === false ? '?' : '&') . MEET_HOST_LINK_MARKER;
}

/**
 * The owner key this host is already known by, or null when it cannot be
 * established with certainty.
 *
 * `MeetApiController::accessTokenAction` builds the key as
 * `extension_uuid ?? user_uuid ?? extension`, from whatever the browser
 * session happened to hold — so it cannot be reproduced from the user record
 * alone. On this deployment the live rows carry the **user_uuid**, because
 * those sessions had no extension_uuid; another tenant's may differ.
 *
 * Guessing is not an option worth taking. `hostToken` answers
 * **403 "Not the host of this meeting"** when `room.ownerKey` differs from the
 * caller's, so a room created under the wrong key locks the real host out of
 * their own scheduled meeting until the room's TTL expires.
 *
 * Hence: take the candidates the directory offers, and accept one only if the
 * meetings table shows this host has actually used it. A host with no history
 * returns null, and the caller falls back to a link that needs no room.
 */
function meetResolveOwnerKey($hostEmail)
{
    $hostEmail = trim((string)$hostEmail);
    if ($hostEmail === '') return null;

    try {
        $db = getDb();
        if (!$db) return null;

        $people = $db->fetchAll(
            "SELECT u.user_uuid, u.domain_uuid, eu.extension_uuid
               FROM v_users u
               LEFT JOIN v_extension_users eu ON eu.user_uuid = u.user_uuid
              WHERE lower(u.user_email) = lower(?)",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            [$hostEmail]
        );
        // One address, one person. Two rows means two tenants share it and
        // there is no safe answer.
        if (!is_array($people) || count($people) !== 1) return null;

        $domainUuid = (string)$people[0]['domain_uuid'];
        $candidates = array_values(array_unique(array_filter([
            (string)($people[0]['extension_uuid'] ?? ''),
            (string)($people[0]['user_uuid'] ?? ''),
        ])));
        if ($domainUuid === '' || !$candidates) return null;

        $placeholders = implode(',', array_fill(0, count($candidates), '?'));
        $known = $db->fetchAll(
            "SELECT DISTINCT owner_key FROM public.meet_meetings
              WHERE domain_uuid = ? AND owner_key IN (" . $placeholders . ")",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            array_merge([$domainUuid], $candidates)
        );
        if (!is_array($known) || count($known) !== 1) return null;

        return ['ownerKey' => (string)$known[0]['owner_key'], 'domainUuid' => $domainUuid];
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Ask the control plane for this meeting's room, and hand back its real link.
 *
 * The short code lives on a room, and a scheduled meeting has no room until a
 * host first starts one — so an invitation sent today could only ever carry
 * the opaque reference. Creating the room here is what puts
 * `/meet/m/w3mu-7fvx-xjxx` in the very first email.
 *
 * Safe to do early, and safe to repeat: `POST /rooms` is idempotent on the
 * external reference, a `created` room is not counted as active
 * (`listActiveMeetingsForOwner` requires `status === "live"`), and since
 * tenant.34 the slug is recovered from `meet_meetings` for good — so the link
 * minted now still resolves when the meeting finally happens, however long
 * after the room's twelve-hour cache has gone.
 *
 * The bearer is minted exactly as `MeetApiController::accessTokenAction` does
 * — a random opaque token whose SHA-256 is the Redis key — given a two-minute
 * life and revoked immediately, because this one is for a single call.
 *
 * Returns '' on anything at all going wrong. An invitation must still go out.
 */
function meetCreateRoomLink($redis, $ringqmeetId, $host, $title, $hostEmail, $hostName)
{
    $ringqmeetId = trim((string)$ringqmeetId);
    $host = trim((string)$host);
    if ($ringqmeetId === '' || $host === '' || !$redis) return '';

    $owner = meetResolveOwnerKey($hostEmail);
    if (!$owner) return '';

    $token = null;
    $key = null;
    try {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $ttl = 120;
        $record = [
            "ownerKey"    => $owner['ownerKey'],
            "displayName" => ($hostName !== '' ? $hostName : $owner['ownerKey']),
            "email"       => (string)$hostEmail,
            "domainUuid"  => strtolower($owner['domainUuid']),
            "canHost"     => true,
            "permissions" => ["host" => true, "join" => true],
            "issuedAt"    => time(),
            "expiresAt"   => time() + $ttl,
        ];
        $key = "meet:access:" . hash('sha256', $token);
        if ($redis->setex($key, $ttl, json_encode($record)) === false) return '';

        $ch = curl_init('https://' . $host . '/meet/api/v1/rooms');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            // The email must not wait on a slow control plane.
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'meetType' => 'scheduled',
                'title' => (string)$title,
                'externalReference' => $ringqmeetId,
            ]),
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200 || !$body) return '';
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || empty($decoded['joinUrl'])) return '';
        return (string)$decoded['joinUrl'];
    } catch (\Throwable $e) {
        return '';
    } finally {
        // One call, one token.
        if ($key) { try { $redis->del($key); } catch (\Throwable $e) {} }
    }
}

/**
 * The shareable RingQ Meet link for a meeting, preferring the short code.
 *
 * Two problems, one helper.
 *
 * **The stored link is not trustworthy.** Rows written by older builds hold
 * the retired forms — `/meet/host?hostname=…&role=host&room=<uuid>&type=schedule&…`
 * and `/meet/participant?room=<uuid>&type=schedule` — and the email job used
 * to fall back to them verbatim, which is how an invitation arrived carrying a
 * URL the current guest client does not serve. So a legacy link is never
 * relayed: the `room=` parameter is dug out of it and the link is rebuilt.
 * That repairs records already in Redis, with no data migration.
 *
 * **A UUID is not the link people should see.** The short code the rest of
 * RingQ Meet uses — `/meet/m/w3mu-7fvx-xjxx` — is minted by the control plane
 * when the room is first created and lives in `meet_meetings.slug`. That table
 * is its only durable home: Redis keeps a room for twelve hours, this keeps
 * the mapping for good, one row per meeting under a unique index on
 * (domain_uuid, external_reference).
 *
 * Degrades, never fails, in this order: short code -> `/meet/m/<reference>` ->
 * the stored link. A meeting nobody has started yet has no row at all, because
 * the control plane maps the reference lazily on first host use, and the
 * reference form still resolves — `resolveMeetingId` accepts a meeting id, a
 * slug, an external reference or an owner key. An invitation has to go out
 * even when the lookup cannot.
 *
 * @param string $ringqmeetId external reference stored on the schedule/booking/webinar
 * @param string $host        tenant host the link should point at
 * @param string $domainUuid  tenant scope; '' where the job has none
 * @param string $storedLink  the possibly-legacy link already on the record
 */
function meetShareableLink($ringqmeetId, $host, $domainUuid = '', $storedLink = '')
{
    $ringqmeetId = trim((string)$ringqmeetId);
    $host = trim((string)$host);
    $storedLink = trim((string)$storedLink);

    // Recover whatever the record failed to state outright. A legacy URL still
    // carries both facts; they just have to be taken out of it.
    if ($storedLink !== '') {
        if ($host === '') {
            $host = (string)parse_url($storedLink, PHP_URL_HOST);
        }
        if ($ringqmeetId === '') {
            $query = (string)parse_url($storedLink, PHP_URL_QUERY);
            if ($query !== '') {
                parse_str($query, $parts);
                if (!empty($parts['room'])) {
                    $ringqmeetId = trim((string)$parts['room']);
                }
            }
        }
    }

    if ($host === '' || $ringqmeetId === '') {
        return $storedLink;
    }

    $referenceLink = 'https://' . $host . '/meet/m/' . rawurlencode($ringqmeetId);

    try {
        $db = getDb();
        if (!$db) {
            return $referenceLink;
        }

        $domainUuid = trim((string)$domainUuid);
        if ($domainUuid !== '') {
            $rows = $db->fetchAll(
                "SELECT slug FROM public.meet_meetings
                  WHERE domain_uuid = ? AND external_reference = ? AND slug IS NOT NULL
                  ORDER BY created_at DESC LIMIT 1",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                [$domainUuid, $ringqmeetId]
            );
        } else {
            // Schedule and webinar jobs carry no tenant scope, and v_domains
            // cannot supply one here — it holds IP addresses with duplicate
            // domain_name values. LIMIT 2 with a uniqueness check rather than
            // LIMIT 1: the reference is a v4 UUID minted per meeting, so two
            // tenants sharing one is not a practical case, but if it ever
            // happened, guessing between them would hand a recipient another
            // tenant's code. Two rows means fall back, not pick.
            $rows = $db->fetchAll(
                "SELECT slug FROM public.meet_meetings
                  WHERE external_reference = ? AND slug IS NOT NULL LIMIT 2",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                [$ringqmeetId]
            );
            if (is_array($rows) && count($rows) > 1) {
                return $referenceLink;
            }
        }

        if (is_array($rows) && !empty($rows[0]['slug'])) {
            return 'https://' . $host . '/meet/m/' . rawurlencode((string)$rows[0]['slug']);
        }
    } catch (\Throwable $e) {
        // A lookup failure must never stop an invitation going out.
    }

    return $referenceLink;
}

// ─────────────────────────────────────────────────────────────────────
// Email chrome
//
// Every transactional email below is built from these helpers. Before this
// existed the same header, footer and detail-row markup was copy-pasted into
// seven templates, and they had already drifted: six declared a dark-mode
// strategy and the seventh — the schedule invitation — had none at all. A fix
// applied by hand reaches one template; a fix applied here reaches all seven.
//
// Three rules hold this together and each fails badly the other way:
//
//   1. NO `!important` ON AN INLINE STYLE. A class rule carrying !important
//      beats a plain inline style, but not an inline style that also carries
//      it. The previous templates had 153 inline `color: … !important`
//      declarations, which is why dark mode could never have worked there no
//      matter what CSS was added.
//
//   2. NO REMOTE IMAGES EXCEPT THE LOGO. Icons are drawn with background
//      colour and a text glyph, so they cannot 404, cost no network fetch and
//      follow the colour scheme. The previous templates hotlinked 89 images
//      from two third-party hosts and most of them arrived broken.
//
//   3. THE HEADER AND FOOTER BARS STAY DARK IN BOTH SCHEMES. The wordmark is
//      a light-on-transparent PNG; on a card that flips to dark it would
//      vanish, and mail clients do not invert images along with backgrounds.
//      Pinning those bars means one logo asset is correct everywhere.
// ─────────────────────────────────────────────────────────────────────

// ─────────────────────────────────────────────────────────────────────
// Meet mail delivery
//
// Meet's emails are sent straight through the account's own SMTP relay rather
// than through Library\Email, for one reason: Library\Email is ionCube-encoded
// on the server, and it is what decides the MIME structure. It builds an
// HTML-only body, allows exactly one attachment, and hardcodes the calendar
// part as `text/calendar; method=REQUEST`.
//
// That last one is why no invitation this system has sent ever showed its
// .ics: Gmail reads a `method=REQUEST` part as a meeting INVITATION rather
// than a file, and renders neither the invitation nor a download when it
// cannot parse it. Owning the MIME here fixes it at the cause and also buys
// two things that were previously unreachable — a text/plain alternative part,
// and a genuinely downloadable copy of the calendar file.
//
// Credentials are stated here rather than read from app/config/config.php, by
// operator decision on 2026-08-16: the `mail` block is not present on every
// install, so reading it would leave Meet falling back to the old relay
// everywhere except the one server that happens to define it.
//
// CONSEQUENCE, recorded deliberately: worker.php IS tracked in version control,
// so this password lives in the repository and its history. Rotating it means
// editing this file. If that is ever unwanted, the fix is to move these four
// values into an environment file outside source control and read them here —
// the rest of the delivery path does not care where they come from.
//
// Delivery FALLS BACK to Library\Email whenever anything is missing or throws,
// so a misconfigured relay degrades to the previous behaviour instead of
// silently dropping somebody's invitation.
// ─────────────────────────────────────────────────────────────────────

// The relay's credentials are NOT defined here.
//
// They sit above the main loop, near the top of this file, because everything
// below `while (true)` is unreachable in a daemon that never leaves it — and
// an undefined constant in PHP evaluates to its own NAME, so the From address
// silently became the literal string "MEET_SMTP_FROM_EMAIL". Swift rejected it
// as an invalid mailbox, meet_smtpSend threw, and EVERY Meet email fell back
// to Library\Email. See meet_mailConfig() below.

/**
 * The built-in relay, used only when apiringq has never answered.
 *
 * Still returns null rather than throwing on empty values: someone clearing a
 * credential must degrade to the fallback, not take down the whole job loop.
 */
function meet_mailConfig() {
    static $cached = false;
    if ($cached !== false) return $cached;
    $cached = null;
    $host = (string)MEET_SMTP_HOST;
    $user = (string)MEET_SMTP_USERNAME;
    $pass = (string)MEET_SMTP_PASSWORD;
    if ($host === '' || $user === '' || $pass === '') return $cached;
    $cached = [
        'host' => $host,
        'port' => (int)MEET_SMTP_PORT,
        'username' => $user,
        'password' => $pass,
        'fromEmail' => (string)MEET_SMTP_FROM_EMAIL,
        'fromName' => (string)MEET_SMTP_FROM_NAME,
    ];
    return $cached;
}

/**
 * The worker's shared Redis connection, or null when none can be had.
 *
 * The deliverer has no $redis argument and many callers, so the connection
 * is taken from the DI container worker.php registers rather than threaded in.
 */
function meet_mailRedis() {
    static $redis = null;
    if ($redis !== null) return $redis ?: null;
    $redis = false;
    try {
        $di = \Phalcon\Di::getDefault();
        if ($di && $di->has('redis')) {
            $redis = $di->getShared('redis');
        }
        if (!$redis) {
            $redis = new \Predis\Client();
        }
    } catch (\Throwable $e) {
        $redis = false;
    }
    return $redis ?: null;
}

/**
 * Keep only providers a transport can actually be built from. A half-filled entry
 * would fail every send it is tried for, so it is dropped rather than attempted.
 */
function meet_mailValidProviders($providers) {
    $valid = [];
    if (!is_array($providers)) return $valid;
    foreach ($providers as $p) {
        if (!is_array($p)) continue;
        $host = trim((string)($p['host'] ?? ''));
        $port = (int)($p['port'] ?? 0);
        $user = (string)($p['username'] ?? '');
        $pass = (string)($p['password'] ?? '');
        if ($host === '' || $port <= 0 || $user === '' || $pass === '') continue;
        $headers = [];
        foreach ((array)($p['headers'] ?? []) as $name => $value) {
            if (is_string($name) && preg_match('/^[A-Za-z0-9-]+$/', $name) && is_scalar($value)) {
                $headers[$name] = (string)$value;
            }
        }
        $valid[] = [
            'name' => preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($p['name'] ?? $host))),
            'host' => $host,
            'port' => $port,
            'encryption' => in_array($p['encryption'] ?? '', ['tls', 'ssl'], true) ? $p['encryption'] : '',
            'username' => $user,
            'password' => $pass,
            'headers' => $headers,
        ];
    }
    return $valid;
}

/**
 * The list a decoded apiringq answer describes, or null when it describes none.
 */
function meet_mailParseProviders($decoded) {
    if (!is_array($decoded) || ($decoded['result'] ?? '') !== 'success') return null;
    $providers = meet_mailValidProviders($decoded['providers'] ?? []);
    if (!$providers) return null;
    $from = is_array($decoded['from'] ?? null) ? $decoded['from'] : [];
    $fromEmail = (string)($from['email'] ?? '');
    return [
        'from' => [
            'email' => filter_var($fromEmail, FILTER_VALIDATE_EMAIL) ? $fromEmail : (string)MEET_SMTP_FROM_EMAIL,
            'name' => (string)($from['name'] ?? '') !== '' ? (string)$from['name'] : (string)MEET_SMTP_FROM_NAME,
        ],
        'providers' => $providers,
        'ttl' => max(60, min(3600, (int)($decoded['ttl'] ?? 600))),
    ];
}

/**
 * Ask apiringq for the provider order. Bounded hard: the worker loop also drains
 * every other queue, and an unanswered request here would stall all of them.
 */
function meet_mailFetchProviders($licenseKey, $fqdn) {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init(MEET_SMTP_PROVIDERS_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['licenseKey' => (string)$licenseKey, 'fqdn' => (string)$fqdn]),
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        echo "[Meet mail] provider list unavailable from apiringq (HTTP {$code})\n";
        return null;
    }
    return meet_mailParseProviders(json_decode((string)$body, true));
}

/**
 * The SMTP providers to try, in order, with the From address to use.
 *
 * Fresh cache, then apiringq, then the last list apiringq ever gave (kept with no
 * expiry), then the built-in relay. A failed fetch is not retried for a minute,
 * so an unreachable apiringq costs one bounded request per minute, not per email.
 * Never returns an empty list while the built-in relay is configured.
 */
function meet_mailProviders($licenseConfig = null) {
    $cacheKey = 'meet:smtp:providers';
    $lastKnownKey = 'meet:smtp:providers:lkg';
    $backoffKey = 'meet:smtp:providers:backoff';
    $redis = meet_mailRedis();

    if ($redis) {
        try {
            $cached = meet_mailParseProviders(json_decode((string)$redis->get($cacheKey), true));
            if ($cached) return $cached + ['source' => 'cache'];
        } catch (\Throwable $e) {
            $redis = null;
        }
    }

    $licenseKey = is_array($licenseConfig) ? (string)($licenseConfig['data']['licenseKey'] ?? '') : '';
    $fqdn = is_array($licenseConfig) ? (string)($licenseConfig['data']['fqdn'] ?? '') : '';
    $backedOff = false;
    if ($redis) {
        try {
            $backedOff = (bool)$redis->exists($backoffKey);
        } catch (\Throwable $e) {
            $backedOff = false;
        }
    }

    if ($licenseKey !== '' && !$backedOff) {
        $fresh = meet_mailFetchProviders($licenseKey, $fqdn);
        if ($fresh) {
            if ($redis) {
                try {
                    $stored = json_encode(['result' => 'success'] + $fresh);
                    $redis->setex($cacheKey, $fresh['ttl'], $stored);
                    $redis->set($lastKnownKey, $stored);
                } catch (\Throwable $e) {
                    // A cache failure only costs another fetch next time.
                }
            }
            return $fresh + ['source' => 'apiringq'];
        }
        if ($redis) {
            try {
                $redis->setex($backoffKey, 60, '1');
            } catch (\Throwable $e) {
            }
        }
    }

    if ($redis) {
        try {
            $lastKnown = meet_mailParseProviders(json_decode((string)$redis->get($lastKnownKey), true));
            if ($lastKnown) return $lastKnown + ['source' => 'last-known'];
        } catch (\Throwable $e) {
        }
    }

    $builtin = meet_mailConfig();
    if ($builtin === null) {
        return ['from' => ['email' => (string)MEET_SMTP_FROM_EMAIL, 'name' => (string)MEET_SMTP_FROM_NAME],
            'providers' => [], 'ttl' => 0, 'source' => 'none'];
    }
    return [
        'from' => ['email' => $builtin['fromEmail'], 'name' => $builtin['fromName']],
        'providers' => [[
            'name' => 'smtpgo',
            'host' => $builtin['host'],
            'port' => $builtin['port'],
            'encryption' => '',
            'username' => $builtin['username'],
            'password' => $builtin['password'],
            'headers' => [],
        ]],
        'ttl' => 0,
        'source' => 'builtin',
    ];
}

function meet_mailRecord($key, array $value) {
    $redis = meet_mailRedis();
    if (!$redis) return;
    try {
        $redis->set($key, json_encode($value));
    } catch (\Throwable $e) {
    }
}

/**
 * Record one send attempt in apiringq's Smtp Logs.
 *
 * Only the envelope travels: never the body, which for an OTP carries the code,
 * and the code in an OTP subject is masked on both ends. Bounded tightly and
 * never allowed to throw, so a slow or failed report cannot delay or lose mail.
 */
function meet_mailReport($licenseConfig, $plan, $provider, $ok, $to, $subject, $error = '') {
    $licenseKey = is_array($licenseConfig) ? (string)($licenseConfig['data']['licenseKey'] ?? '') : '';
    if ($licenseKey === '' || !function_exists('curl_init')) return;
    try {
        $ch = curl_init(MEET_SMTP_REPORT_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'licenseKey' => $licenseKey,
                'provider' => (string)$provider,
                'status' => $ok ? 'success' : 'failed',
                'to' => (string)$to,
                'from' => (string)($plan['from']['email'] ?? ''),
                'subject' => preg_replace('/(?<!\d)\d{6}(?!\d)/', '******', (string)$subject),
                'source' => (string)($plan['source'] ?? ''),
                'fqdn' => (string)($licenseConfig['data']['fqdn'] ?? ''),
                'error' => substr((string)$error, 0, 500),
            ]),
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (\Throwable $e) {
        // A missing log line is never worth an email.
    }
}

/**
 * A readable text/plain alternative derived from the HTML body.
 *
 * Worth having for its own sake — screen readers, plain clients and text-only
 * digests all prefer it — but the reason it is here is that an HTML-only
 * message scores worse with spam filters, and Library\Email could not send one.
 */
function meet_mailPlainText($html) {
    $text = preg_replace('/<(style|head|script)\b[^>]*>.*?<\/\1>/is', '', $html);
    // Keep the link text rather than the markup around it.
    $text = preg_replace('/<a\b[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', '$2 ($1)', $text);
    $text = preg_replace('/<\/(p|div|tr|h1|h2|h3|table)>/i', "\n", $text);
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/ *\n */', "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

/**
 * Send one Meet email through the SMTP providers, in apiringq's priority order.
 *
 * Returns the name of the provider that accepted it, or false when none did —
 * never throws, so the caller can fall back. The .ics is
 * delivered twice on purpose: once as a `text/calendar` alternative part, which
 * is what lets Gmail and Outlook render it as an invitation and add it to a
 * calendar in one click, and once as a normal attachment, which is what
 * guarantees a visible, savable file in every client that does not.
 */
function meet_smtpSend($to, $subject, $html, $ics = null, $licenseConfig = null) {
    $plan = meet_mailProviders($licenseConfig);
    if (!$plan['providers']) return false;
    try {
        $message = new \Swift_Message($subject);
        $message->setFrom([$plan['from']['email'] => $plan['from']['name']]);
        $message->setTo($to);

        $message->setBody($html, 'text/html', 'UTF-8');
        $message->addPart(meet_mailPlainText($html), 'text/plain', 'UTF-8');

        if ($ics !== null && $ics !== '') {
            // The method must match the body, or a cancellation is announced as
            // a fresh invitation. Library\Email could not do this: it stated
            // REQUEST for every calendar part it ever sent.
            $method = strpos($ics, 'METHOD:CANCEL') !== false ? 'CANCEL' : 'REQUEST';

            // The method is set as a PARAMETER, not by writing the whole
            // Content-Type: Swift appends its own charset afterwards, so a
            // hand-built string arrives with charset stated twice.
            $calendar = new \Swift_MimePart($ics, 'text/calendar', 'UTF-8');
            $calendar->getHeaders()->get('Content-Type')->setParameter('method', $method);
            $message->attach($calendar);

            $file = new \Swift_Attachment($ics, 'invite.ics', 'application/ics');
            $file->setDisposition('attachment');
            $message->attach($file);
        }
    } catch (\Throwable $e) {
        echo "[Meet mail] could not build the message for {$to}: " . $e->getMessage() . "\n";
        return false;
    }

    // The same message goes to each provider in the order apiringq set, so the
    // .ics parts above survive whichever one takes it.
    foreach ($plan['providers'] as $provider) {
        $headers = $message->getHeaders();
        foreach ($provider['headers'] as $name => $value) {
            $headers->addTextHeader($name, $value);
        }
        try {
            $transport = new \Swift_SmtpTransport(
                $provider['host'],
                $provider['port'],
                $provider['encryption'] !== '' ? $provider['encryption'] : null
            );
            $transport->setUsername($provider['username']);
            $transport->setPassword($provider['password']);
            $transport->setTimeout(15);
            $sent = (new \Swift_Mailer($transport))->send($message) > 0;
            $failure = $sent ? '' : 'the provider accepted no recipients';
        } catch (\Throwable $e) {
            echo "[Meet mail] {$provider['name']} failed for {$to}: " . $e->getMessage() . "\n";
            $sent = false;
            $failure = $e->getMessage();
        }
        meet_mailReport($licenseConfig, $plan, $provider['name'], $sent, $to, $subject, $failure);
        if ($sent) {
            meet_mailRecord('meetMail:lastSuccess', ['at' => time(), 'provider' => $provider['name'], 'source' => (string)$plan['source']]);
        } else {
            meet_mailRecord('meetMail:lastFailure', ['at' => time(), 'provider' => $provider['name'], 'source' => (string)$plan['source'], 'error' => substr((string)$failure, 0, 300)]);
        }
        foreach (array_keys($provider['headers']) as $name) {
            $headers->removeAll($name);
        }
        if ($sent) {
            echo "[Meet mail] sent to {$to} via {$provider['name']} ({$plan['source']})\n";
            return $provider['name'];
        }
        echo "[Meet mail] {$provider['name']} did not accept {$to}, trying the next provider\n";
    }
    return false;
}

/**
 * Deliver a Meet email, preferring the account's SMTP relay.
 *
 * Falls back to Library\Email so that a missing or broken relay degrades to
 * exactly the previous behaviour rather than losing the message.
 */
function meet_deliver($email, $to, $subject, $template, $html, $ics, $licenseConfig) {
    $provider = meet_smtpSend($to, $subject, $html, $ics, $licenseConfig);
    if ($provider !== false) {
        return ['success' => true, 'via' => 'smtp', 'provider' => $provider];
    }
    $params = ['type' => 'custom', 'body' => $html];
    if ($ics !== null && $ics !== '') {
        $params['filebytes'] = $ics;
    }
    echo "[Meet mail] falling back to Library\\Email for {$to}\n";
    meet_mailRecord('meetMail:lastFallback', ['at' => time()]);
    $result = $email->send($to, $subject, $template, $params, $licenseConfig);
    return ['success' => true, 'via' => 'library', 'result' => $result];
}

/**
 * Colours, stated once.
 *
 * Text/background pairs are verified against WCAG 4.5:1 by
 * ops/meet/tests/worker_email_test.php, which computes the ratio rather than
 * trusting these values — the previous button was #00B4B4 behind white text at
 * about 2.6:1, and nothing in review had caught it.
 *
 * The accent keeps the brand teal and darkens the LABEL instead, which reaches
 * 7:1 without changing the brand colour.
 */
function meet_mailColors() {
    return [
        // light                        dark
        'bg'        => ['#EBF4FA',      '#0B121B'],
        'card'      => ['#FFFFFF',      '#16202C'],
        'heading'   => ['#0A1628',      '#F1F5F9'],
        'text'      => ['#333333',      '#CBD5E1'],
        'muted'     => ['#6B7280',      '#94A3B8'],
        'chipBg'    => ['#F0F4F8',      '#22303F'],
        'chipText'  => ['#0A1628',      '#E2E8F0'],
        'link'      => ['#0E7490',      '#22D3EE'],
        'rule'      => ['#E5EAF0',      '#2A3746'],
        // Pinned in both schemes — see rule 3 above.
        'bar'       => ['#0A1628',      '#0A1628'],
        'barText'   => ['#FFFFFF',      '#FFFFFF'],
        'accent'    => ['#00B4B4',      '#00B4B4'],
        'accentText'=> ['#0A1628',      '#0A1628'],
    ];
}

/**
 * One detail row: a coloured chip, a label and a value.
 *
 * `$glyph` is rendered as text, so it must stay inside the Basic Multilingual
 * Plane and out of the emoji range — emoji render in colour on some clients,
 * monochrome on others, and sit off the baseline. Digits and initials are
 * preferred where they carry meaning: the date chip shows the day number, and
 * a person chip shows initials, matching the avatars used inside the meeting.
 *
 * `$trailing` is appended after the value inside the same cell; it is how the
 * timezone note rides along on the Time row.
 */
function meet_mailRow($glyph, $label, $value, $trailing = '') {
    $c = meet_mailColors();
    return '
                            <tr>
                                <td style="padding: 7px 0; vertical-align: top;">
                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse;">
                                        <tr>
                                            <td class="m-chip" width="36" style="width: 36px; height: 36px; background-color: ' . $c['chipBg'][0] . '; color: ' . $c['chipText'][0] . '; border-radius: 18px; text-align: center; vertical-align: middle; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: bold; line-height: 36px; mso-line-height-rule: exactly;">' . $glyph . '</td>
                                            <td style="padding-left: 14px; vertical-align: middle; font-family: Arial, Helvetica, sans-serif; font-size: 14px;">
                                                <span class="m-muted" style="color: ' . $c['muted'][0] . ';">' . $label . ': </span><strong class="m-head" style="color: ' . $c['heading'][0] . ';">' . $value . '</strong>' . $trailing . '
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>';
}

/** The primary call to action. Bulletproof enough without VML at this size. */
function meet_mailButton($href, $label) {
    $c = meet_mailColors();
    return '
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin: 28px auto 16px auto;">
                            <tr>
                                <td style="background-color: ' . $c['accent'][0] . '; border-radius: 26px;">
                                    <a href="' . $href . '" style="display: inline-block; padding: 15px 42px; font-family: Arial, Helvetica, sans-serif; font-size: 15px; font-weight: bold; color: ' . $c['accentText'][0] . '; text-decoration: none; border-radius: 26px;">' . $label . ' &rarr;</a>
                                </td>
                            </tr>
                        </table>';
}

/**
 * The copy-and-paste fallback.
 *
 * Kept for the two cases a button cannot cover: a client that strips the
 * anchor, and a reader forwarding the link to somebody else.
 */
function meet_mailLinkFallback($href) {
    $c = meet_mailColors();
    return '
                        <p class="m-muted" style="font-family: Arial, Helvetica, sans-serif; font-size: 11px; line-height: 17px; color: ' . $c['muted'][0] . '; text-align: center; margin: 0;">If the button does not work, copy and paste this link into your browser:<br><a class="m-link" href="' . $href . '" style="color: ' . $c['link'][0] . '; word-break: break-all;">' . $href . '</a></p>';
}

/** A centred muted note, for the sentence that follows the details. */
function meet_mailNote($html) {
    $c = meet_mailColors();
    return '
                        <p class="m-muted" style="font-family: Arial, Helvetica, sans-serif; font-size: 13px; line-height: 20px; color: ' . $c['muted'][0] . '; text-align: center; margin: 18px 0 0 0;">' . $html . '</p>';
}

/** An email address rendered as a mailto link that follows the colour scheme. */
function meet_mailAddress($address) {
    $c = meet_mailColors();
    $safe = htmlspecialchars((string)$address);
    return '<a class="m-link" href="mailto:' . $safe . '" style="color: ' . $c['link'][0] . ';">' . $safe . '</a>';
}

/** A small circular chip used for the social row, so no icon is fetched. */
function meet_mailSocial() {
    $links = [
        ['https://www.facebook.com/ringqcallcenter', 'f',  'Facebook'],
        ['https://www.linkedin.com/company/ringqcallcenter/', 'in', 'LinkedIn'],
        ['https://www.youtube.com/@RingQ-ry6kw', 'YT', 'YouTube'],
        ['https://x.com/RingQcloudcx', 'X', 'X'],
        ['https://www.instagram.com/ringqcloudcx.', 'ig', 'Instagram'],
    ];
    $out = '';
    foreach ($links as $l) {
        $out .= '<a href="' . $l[0] . '" title="' . $l[2] . '" style="display: inline-block; width: 26px; height: 26px; margin: 0 3px; background-color: #1B2838; border-radius: 13px; color: #C7D2DE; font-family: Arial, Helvetica, sans-serif; font-size: 11px; font-weight: bold; line-height: 26px; mso-line-height-rule: exactly; text-align: center; text-decoration: none;">' . $l[1] . '</a>';
    }
    return $out;
}

/**
 * The whole document.
 *
 * $o: title, badge, intro, rows, cta (['href','label']), after, recipient.
 */
function meet_mailShell(array $o) {
    $c = meet_mailColors();
    $title     = $o['title'] ?? 'RingQ';
    $intro     = $o['intro'] ?? '';
    $rows      = $o['rows'] ?? '';
    $after     = $o['after'] ?? '';
    $recipient = $o['recipient'] ?? '';
    $preheader = $o['preheader'] ?? '';

    $cta = '';
    if (!empty($o['cta']['href'])) {
        $cta = meet_mailButton($o['cta']['href'], $o['cta']['label'] ?? 'Open')
             . meet_mailLinkFallback($o['cta']['href']);
    }

    // Dark overrides live here, on classes, WITH !important so they beat the
    // inline light baseline. Outlook.com rewrites to [data-ogsc], so every
    // rule is stated twice.
    $dark = '
            .m-bg    { background-color: ' . $c['bg'][1] . ' !important; }
            .m-card  { background-color: ' . $c['card'][1] . ' !important; }
            .m-head  { color: ' . $c['heading'][1] . ' !important; }
            .m-text  { color: ' . $c['text'][1] . ' !important; }
            .m-muted { color: ' . $c['muted'][1] . ' !important; }
            .m-link  { color: ' . $c['link'][1] . ' !important; }
            .m-rule  { border-color: ' . $c['rule'][1] . ' !important; }
            .m-chip  { background-color: ' . $c['chipBg'][1] . ' !important; color: ' . $c['chipText'][1] . ' !important; }';

    $ogsc = str_replace('.m-', '[data-ogsc] .m-', $dark);

    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>' . $title . '</title>
    <style>
        :root { color-scheme: light dark; supported-color-schemes: light dark; }
        body { margin: 0; padding: 0; width: 100% !important; }
        table { border-collapse: collapse; }
        img { border: 0; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
        a { text-decoration: none; }
        @media (prefers-color-scheme: dark) {' . $dark . '
        }
' . $ogsc . '
        @media only screen and (max-width: 620px) {
            .m-pad  { padding-left: 18px !important; padding-right: 18px !important; }
        }
    </style>
</head>
<body class="m-bg" style="margin: 0; padding: 0; width: 100%; background-color: ' . $c['bg'][0] . ';">
    <div style="display: none; max-height: 0; overflow: hidden; mso-hide: all; opacity: 0;">' . ($preheader !== '' ? htmlspecialchars($preheader) : $title . ($cta !== '' ? ' — details and join link inside.' : '')) . '&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;</div>
    <table role="presentation" class="m-bg" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse; background-color: ' . $c['bg'][0] . ';">
        <tr>
            <td align="center" style="padding: 24px 12px;">
                <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td><![endif]-->
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse; width: 100%; max-width: 600px; margin: 0 auto;">

                    <!--
                        Card. It carries the rounded top corners because the
                        header bar above it was removed by operator decision on
                        2026-08-16, along with the banner image that briefly
                        lived inside it. The email now opens on the greeting;
                        what kind of message it is comes from the subject line.
                    -->
                    <tr>
                        <td class="m-card m-pad" style="background-color: ' . $c['card'][0] . '; border-radius: 12px 12px 0 0; padding: 30px 30px 26px 30px;">
                            ' . $intro . '
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 6px 0 4px 0;">' . $rows . '
                            </table>
                            ' . $cta . '
                            ' . $after . '
                        </td>
                    </tr>

                    <!-- Footer: pinned dark in both schemes -->
                    <tr>
                        <td style="background-color: ' . $c['bar'][0] . '; border-radius: 0 0 12px 12px; padding: 24px 26px;">
                            <p style="font-family: Arial, Helvetica, sans-serif; font-size: 12px; line-height: 18px; color: #9CA3AF; text-align: center; margin: 0 0 16px 0;">
                                ' . ($recipient !== '' ? 'This email was sent to <a href="mailto:' . $recipient . '" style="color: #56C8DC;">' . $recipient . '</a>.' : '') . '
                            </p>
                            <p style="text-align: center; margin: 0 0 14px 0;">' . meet_mailSocial() . '</p>
                            <p style="font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #8A94A6; text-align: center; margin: 0;">Copyright &copy; ' . date('Y') . ' RingQ.</p>
                        </td>
                    </tr>
                </table>
                <!--[if mso]></td></tr></table><![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>';
}

/** Intro paragraphs, so the greeting is worded the same way in every email. */
function meet_mailIntro($greetingName, $sentence) {
    $c = meet_mailColors();
    return '<p class="m-head" style="font-family: Arial, Helvetica, sans-serif; font-size: 17px; color: ' . $c['heading'][0] . '; margin: 0 0 6px 0;">Hi <strong>' . htmlspecialchars($greetingName) . '</strong>,</p>
                            <p class="m-text" style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 21px; color: ' . $c['text'][0] . '; margin: 0 0 18px 0;">' . $sentence . '</p>';
}

/** Two initials, matching the avatars shown inside the meeting itself. */
function meet_mailInitials($name) {
    $name = trim(preg_replace('/\s+/', ' ', (string)$name));
    if ($name === '') return '&#9679;';
    $parts = explode(' ', $name);
    if (count($parts) === 1) {
        return strtoupper(substr($parts[0], 0, 2));
    }
    return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
}

/** The day number, used as the date chip — a digit can never fail to render. */
function meet_mailDayChip($ts, $zone = null) {
    $day = meet_zonedFormat('j', $ts, $zone);
    return $day === '' ? '&#9679;' : $day;
}

/**
 * "45 min", "1 hr", "1 hr 30 min", or '' when it cannot be stated.
 *
 * Returns empty rather than "0 min" for an unreadable pair, because every
 * producer here treats a zero-length span as "unknown", and printing 0 would
 * assert something the data does not say.
 */
function meet_mailDuration($startTs, $endTs) {
    $mins = (int) round(((int)$endTs - (int)$startTs) / 60);
    if ($mins <= 0) return '';
    if ($mins < 60) return $mins . ' min';
    $h = intdiv($mins, 60);
    $m = $mins % 60;
    return $m === 0 ? $h . ' hr' : $h . ' hr ' . $m . ' min';
}

function meet_processSchedule($redis, $email, $data, $licenseConfig) {
    $hostEmail = $data['hostEmail'] ?? '';
    $emailAddresses = [];
    if (isset($data['emailAddresses'])) {
        $emailAddresses = explode(",", $data['emailAddresses']);
    }
    
    // Create map for internal users
    $internalUserLinks = [];
    if (isset($data['internalUsers']) && is_array($data['internalUsers'])) {
        foreach ($data['internalUsers'] as $user) {
            if (isset($user['user_email'])) {
                $emailAddresses[] = $user['user_email'];
                // The per-user `meetLink` on the record is deliberately not
                // read: older rows hold the retired participant URL. Everyone
                // on this meeting is sent the one link built below.
            }
        }
    }
    
    // Add host
    $emailAddresses[] = $hostEmail;
    $emailAddresses = array_unique(array_filter(array_map('trim', $emailAddresses)));

    $meetingName = $data['meetingTitle'] ?? 'Scheduled Meeting';
    $description = $data['meetingDescription'] ?? 'No description provided';
    
    // Date parsing, in the meeting's own timezone when it states one.
    $dateStr = $data['date'] ?? '';
    $meetingZone = meet_timezoneOf($data);

    $startTimeSec = isset($data['startTime']) ? intval($data['startTime']) : 0;
    $endTimeSec = isset($data['endTime']) ? intval($data['endTime']) : 0;

    // Midnight of the chosen day; the schedule form stores its times as
    // seconds from that, not as a clock string.
    $startTimestamp = meet_zonedTimestamp($dateStr, '', $meetingZone);
    if ($startTimestamp === false) $startTimestamp = 0;

    $startDateTime = $startTimestamp + $startTimeSec;
    $endDateTime = $startTimestamp + $endTimeSec;

    $zoneLabel = meet_zoneLabel($meetingZone, $startDateTime);
    $zoneSuffix = $zoneLabel === '' ? '' : ' ' . $zoneLabel;
    $emailDate = meet_zonedFormat("M d, Y", $startTimestamp, $meetingZone);
    $startTimeDisplay = meet_zonedFormat("h:i A", $startDateTime, $meetingZone) . $zoneSuffix;
    $endTimeDisplay = meet_zonedFormat("h:i A", $endDateTime, $meetingZone) . $zoneSuffix;

    // The schedule email never named who called the meeting. A recipient could
    // read the whole invitation without learning who invited them.
    $organizerName = $data['hostName'] ?? '';
    if (trim($organizerName) === '') {
        $organizerName = $hostEmail !== '' ? strstr($hostEmail, '@', true) : 'Host';
    }
    $durationText = meet_mailDuration($startDateTime, $endDateTime);
    $durationNote = $durationText === '' ? '' : ' (' . $durationText . ')';
    $palette = meet_mailColors();
    $linkColor = $palette['link'][0];

    $storedMeetingLink = $data['ringqmeetLink'] ?? ($data['ringqmeetParticipantLink'] ?? '');
    $storedMeetingHost = parse_url($storedMeetingLink, PHP_URL_HOST);
    $ringqmeetId = $data['ringqmeetId'] ?? '';

    $canonicalMeetingLink = meetInvitationLink(
        $redis,
        $ringqmeetId,
        $storedMeetingHost,
        $data['meetingTitle'] ?? '',
        $data['hostEmail'] ?? '',
        $data['hostName'] ?? '',
        '',
        $storedMeetingLink
    );
    $hostMeetingLink = meetHostLink($canonicalMeetingLink);
    $participantBaseLink = $canonicalMeetingLink;

    foreach ($emailAddresses as $emailAddress) {
        $meetingLink = $emailAddress === $hostEmail
            ? $hostMeetingLink
            : $participantBaseLink;

        $icsContent = meet_generateICS(
            $data['id'] ?? uniqid(),
            $startDateTime,
            $endDateTime,
            $meetingName,
            $description,
            $hostEmail,
            $data['hostName'] ?? 'Host',
            $meetingLink,
            'REQUEST',
            $emailAddress,
            $recipientName
        );

        $recipientName = 'Guest';
        if (isset($data['internalUsers']) && is_array($data['internalUsers'])) {
            foreach ($data['internalUsers'] as $user) {
                if (isset($user['user_email']) && $user['user_email'] === $emailAddress) {
                    $recipientName = $user['user_name'] ?? $user['name'] ?? strstr($emailAddress, '@', true);
                    break;
                }
            }
        }
        if ($recipientName === 'Guest') {
             $recipientName = strstr($emailAddress, '@', true);
        }
        // Composed here, below the block that resolves the name: this loop's
        // $recipientName is read one line above where it is assigned, which is
        // a defect of its own and deliberately not moved.
        $meetingLink = meetAddressedLink($meetingLink, $recipientName, $emailAddress);
        
        $htmlBody = meet_mailShell([
            'title' => 'New Meeting',
            'recipient' => $emailAddress,
            'intro' => meet_mailIntro(
                $recipientName,
                'Your meeting <strong>' . htmlspecialchars($meetingName) . '</strong> has been scheduled. The invitation is attached &mdash; open it to add this to your calendar.'
            ),
            'rows' =>
                meet_mailRow(meet_mailInitials($organizerName), 'Organizer', htmlspecialchars($organizerName))
                . meet_mailRow(meet_mailInitials($recipientName), 'To', htmlspecialchars($recipientName))
                . meet_mailRow(meet_mailDayChip($startTimestamp, $meetingZone), 'Date', $emailDate)
                . meet_mailRow('&#9719;', 'Time', $startTimeDisplay . ' - ' . $endTimeDisplay . $durationNote, meet_timezoneNoteHtml($meetingZone, $startDateTime))
                . meet_mailRow('@', 'Email', '<a class="m-link" href="mailto:' . $emailAddress . '" style="color: ' . $linkColor . ';">' . $emailAddress . '</a>')
                . meet_mailRow('&#9636;', 'Notes', htmlspecialchars($description)),
            'cta' => ['href' => $meetingLink, 'label' => 'Join Meeting'],
        ]);

        echo "[Schedule] Sending to: $emailAddress | ICS length: " . strlen($icsContent) . "\n";
        $result = meet_deliver($email, $emailAddress, $meetingName, "scheduled", $htmlBody, $icsContent, $licenseConfig);
        echo "[Schedule] Send result: " . json_encode($result) . "\n";
    }
}

/**
 * The host's own invitation, sent once, when the webinar is created.
 *
 * This is the only webinar email that carries an .ics for the host. A
 * registration notice deliberately carries none: the host's calendar needs one
 * entry for the webinar, not one per attendee, and the UID here is the webinar
 * id so that even a repeat send lands on the same event rather than beside it.
 *
 * Refuses out loud rather than sending something useless. A webinar with no
 * host address or no readable start is a record the create path failed to
 * finish, and a silent return is exactly how the owner-wipe defect stayed
 * invisible for three of five webinars on the test host.
 */
function meet_processWebinarCreated($redis, $email, $webinarId, $requestHost, $licenseConfig) {
    $webinarJson = $redis->get("webinar_details:" . $webinarId);
    if (!$webinarJson) {
        echo "[WebinarCreated] no webinar_details:" . $webinarId . "\n";
        return;
    }

    $webinar = json_decode($webinarJson, true);
    if (!is_array($webinar)) {
        echo "[WebinarCreated] unreadable webinar_details:" . $webinarId . "\n";
        return;
    }

    $hostEmail = trim((string)($webinar['hostEmail'] ?? ''));
    if ($hostEmail === '') {
        echo "[WebinarCreated] skipped " . $webinarId . ": the record carries no hostEmail\n";
        return;
    }

    $meetingName = $webinar['title'] ?? 'Webinar';
    $description = $webinar['description'] ?? '';
    $hostName    = $webinar['hostName'] ?? 'Host';

    $meetingZone   = meet_timezoneOf($webinar);
    $startDateTime = meet_zonedTimestamp($webinar['date'] ?? '', $webinar['time'] ?? '00:00', $meetingZone);
    if ($startDateTime === false || $startDateTime <= 0) {
        echo "[WebinarCreated] skipped " . $webinarId . ": unreadable start (date='"
            . ($webinar['date'] ?? '') . "', time='" . ($webinar['time'] ?? '') . "')\n";
        return;
    }
    $endDateTime = $startDateTime + meet_parseDuration($webinar['duration'] ?? '1 hr');

    // Opening the room now is what puts the short code in the very first
    // invitation rather than the opaque reference; the reference form still
    // resolves, so a control plane that does not answer costs nothing.
    $ringqmeetId = $webinar['ringqmeetId'] ?? '';
    $hostMeetingLink = meetAddressedLink(
        meetHostLink(meetInvitationLink(
            $redis, $ringqmeetId, $requestHost, $meetingName, $hostEmail, $hostName
        )),
        $hostName,
        $hostEmail
    );

    $zoneLabel  = meet_zoneLabel($meetingZone, $startDateTime);
    $zoneSuffix = $zoneLabel === '' ? '' : ' ' . $zoneLabel;

    $icsContent = meet_generateICS(
        $webinarId,
        $startDateTime,
        $endDateTime,
        $meetingName,
        $description,
        $hostEmail,
        $hostName,
        $hostMeetingLink,
        'REQUEST',
        $hostEmail,
        $hostName
    );

    $htmlBody = meet_mailShell([
        'title' => 'Webinar Created',
        'recipient' => $hostEmail,
        'intro' => meet_mailIntro(
            $hostName,
            'Your webinar <strong>' . htmlspecialchars($meetingName) . '</strong> is ready. The invitation is attached &mdash; open it to add this to your calendar.'
        ),
        'rows' =>
            meet_mailRow('&#9636;', 'Webinar', htmlspecialchars($meetingName))
            . meet_mailRow(meet_mailDayChip($startDateTime, $meetingZone), 'Date', meet_zonedFormat('M d, Y', $startDateTime, $meetingZone))
            . meet_mailRow('&#9719;', 'Time',
                meet_zonedFormat('h:i A', $startDateTime, $meetingZone) . $zoneSuffix . ' - '
                . meet_zonedFormat('h:i A', $endDateTime, $meetingZone) . $zoneSuffix,
                // Defaults: no second zone to reconcile (the recipient IS the
                // organiser) and the note may point at the attachment, because
                // unlike the registration notice this email has one.
                meet_timezoneNoteHtml($meetingZone, $startDateTime))
            . meet_mailRow(meet_mailInitials($hostName), 'Organizer', htmlspecialchars($hostName))
            . meet_mailRow('&#9636;', 'Notes', htmlspecialchars($description !== '' ? $description : 'None')),
        'cta' => ['href' => $hostMeetingLink, 'label' => 'Open Webinar'],
    ]);

    $result = meet_deliver($email, $hostEmail, "Your Webinar: " . $meetingName, "scheduled", $htmlBody, $icsContent, $licenseConfig);
    echo "[WebinarCreated] " . $webinarId . " -> " . $hostEmail . " " . json_encode($result) . "\n";
}

function meet_processWebinar($redis, $email, $participantData, $webinarId, $licenseConfig) {
    // Fetch webinar details
    $webinarJson = $redis->get("webinar_details:" . $webinarId);
    if (!$webinarJson) return; 
    
    $webinar = json_decode($webinarJson, true);
    
    $participantEmail = $participantData['email'] ?? '';
    if (!$participantEmail) return;

    $meetingName = $webinar['title'] ?? 'Webinar';
    $description = $webinar['description'] ?? '';
    
    $dateStr = $webinar['date'] ?? '';
    $timeStr = $webinar['time'] ?? '00:00'; // e.g. "10:0"
    
    $meetingZone = meet_timezoneOf($webinar);
    $startDateTime = meet_zonedTimestamp($dateStr, $timeStr, $meetingZone);
    if ($startDateTime === false) $startDateTime = 0;

    $durationStr = $webinar['duration'] ?? '1 hr';
    $durationSec = meet_parseDuration($durationStr);
    $endDateTime = $startDateTime + $durationSec;

    // Written in the registrant's own timezone when the public page recorded
    // it, otherwise in the webinar's own — which is every registration made
    // before that page collected it.
    $recipientZone = meet_recipientZone($participantData, $meetingZone);
    $zoneLabel = meet_zoneLabel($recipientZone, $startDateTime);
    $zoneSuffix = $zoneLabel === '' ? '' : ' ' . $zoneLabel;
    $emailDate = meet_zonedFormat("M d, Y", $startDateTime, $recipientZone);
    $startTimeDisplay = meet_zonedFormat("h:i A", $startDateTime, $recipientZone) . $zoneSuffix;
    $endTimeDisplay = meet_zonedFormat("h:i A", $endDateTime, $meetingZone) . $zoneSuffix;
    
    $storedParticipantLink = $participantData['meetLink'] ?? '';
    $hostEmail = $webinar['hostEmail'] ?? '';
    $ringqmeetId = $webinar['ringqmeetId'] ?? '';
    $meetingHost = parse_url($storedParticipantLink, PHP_URL_HOST);
    $participantMeetingLink = meetInvitationLink(
        $redis,
        $ringqmeetId,
        $meetingHost,
        $meetingName,
        $hostEmail,
        $webinar['hostName'] ?? '',
        '',
        $storedParticipantLink
    );

    $participantMeetingLink = meetAddressedLink(
        $participantMeetingLink,
        trim(($participantData['firstName'] ?? '') . ' ' . ($participantData['lastName'] ?? '')),
        $participantEmail
    );
    $inviteToken = ($participantData['role'] ?? '') === 'cohost'
        ? meetWebinarInviteToken($redis, $webinarId, $participantData['id'] ?? '')
        : '';
    $isCohostInvite = $inviteToken !== '';
    $participantMeetingLink = meetInviteLink($participantMeetingLink, $inviteToken);
    $hostAdded = ($participantData['source'] ?? '') === 'host';
    if ($isCohostInvite) {
        $participantIntro = 'You have been invited to <strong>' . htmlspecialchars($meetingName) . '</strong> as a co-host. The invitation is attached &mdash; open it to add this to your calendar. This link is personal: anyone you forward it to can join as a co-host.';
    } elseif ($hostAdded) {
        $participantIntro = 'You have been invited to <strong>' . htmlspecialchars($meetingName) . '</strong>. The invitation is attached &mdash; open it to add this to your calendar.';
    } else {
        $participantIntro = 'Thank you for registering for <strong>' . htmlspecialchars($meetingName) . '</strong>. The invitation is attached &mdash; open it to add this to your calendar.';
    }

    // 1. Send to Participant
    $icsContentParticipant = meet_generateICS(
        $webinar['id'] ?? uniqid(),
        $startDateTime,
        $endDateTime,
        $meetingName,
        $description,
        $hostEmail,
        $webinar['hostName'] ?? 'Host',
        $participantMeetingLink,
        'REQUEST',
        $participantEmail,
        trim(($participantData['firstName'] ?? '') . ' ' . ($participantData['lastName'] ?? ''))
    );

    $htmlBodyParticipant = meet_mailShell([
        'title' => $isCohostInvite ? 'Co-host Invitation' : 'New Webinar',
        'recipient' => $participantEmail,
        'intro' => meet_mailIntro(
            $participantData['firstName'] ?? 'there',
            $participantIntro
        ),
        'rows' =>
            meet_mailRow('&#9636;', 'Webinar', htmlspecialchars($meetingName))
            . meet_mailRow(meet_mailDayChip($startDateTime, $recipientZone), 'Date', $emailDate)
            . meet_mailRow('&#9719;', 'Time', $startTimeDisplay . ' - ' . $endTimeDisplay, meet_timezoneNoteHtml($recipientZone, $startDateTime, $meetingZone))
            . meet_mailRow(meet_mailInitials($webinar['hostName'] ?? 'Host'), 'Organizer', htmlspecialchars($webinar['hostName'] ?? 'Host')),
        'cta' => ['href' => $participantMeetingLink, 'label' => $isCohostInvite ? 'Join as Co-host' : 'Join Webinar'],
    ]);

    meet_deliver($email, $participantEmail, $meetingName, "scheduled", $htmlBodyParticipant, $icsContentParticipant, $licenseConfig);

    if ($hostAdded) return;

    // 2. Send Notification to Host
    $htmlBodyHost = meet_mailShell([
        'title' => 'New Registration',
        'recipient' => $hostEmail,
        'intro' => meet_mailIntro(
            $webinar['hostName'] ?? 'Host',
            'A new participant has registered for your webinar <strong>' . htmlspecialchars($meetingName) . '</strong>.'
        ),
        'rows' =>
            meet_mailRow(meet_mailInitials(trim(($participantData['firstName'] ?? '') . ' ' . ($participantData['lastName'] ?? ''))), 'Participant', htmlspecialchars(trim(($participantData['firstName'] ?? '') . ' ' . ($participantData['lastName'] ?? ''))))
            . meet_mailRow('@', 'Email', meet_mailAddress($participantEmail))
            . meet_mailRow(meet_mailDayChip($startDateTime, $meetingZone), 'Date', meet_zonedFormat('M d, Y', $startDateTime, $meetingZone))
            // Still no .ics here; the host's invitation arrives once at
            // creation. The note must not point at an attachment.
            . meet_mailRow('&#9719;', 'Time', meet_zonedFormat('h:i A', $startDateTime, $meetingZone) . ' - ' . meet_zonedFormat('h:i A', $endDateTime, $meetingZone), meet_timezoneNoteHtml($meetingZone, $startDateTime, null, false))
            . meet_mailRow('&#9636;', 'Notes', htmlspecialchars($participantData['notes'] ?? 'None')),
        // No join button and no .ics, deliberately. The host's invitation is
        // sent once, when the webinar is created (meet_processWebinarCreated),
        // so this stays what its subject says it is: a notification that
        // somebody registered. Attaching one per registrant is what would put
        // the same webinar in the host's calendar once per attendee.
    ]);

    meet_deliver($email, $hostEmail, "New Webinar Registration: " . $meetingName, "scheduled", $htmlBodyHost, null, $licenseConfig);
}

function meet_processWebinarReminder($redis, $email, $participantData, $webinarId, $licenseConfig) {
    // Fetch webinar details
    $webinarJson = $redis->get("webinar_details:" . $webinarId);
    if (!$webinarJson) return; 
    
    $webinar = json_decode($webinarJson, true);
    
    $participantEmail = $participantData['email'] ?? '';
    if (!$participantEmail) return;

    $meetingName = $webinar['title'] ?? 'Webinar';
    
    $dateStr = $webinar['date'] ?? '';
    $timeStr = $webinar['time'] ?? '00:00';
    
    $meetingZone = meet_timezoneOf($webinar);
    $startDateTime = meet_zonedTimestamp($dateStr, $timeStr, $meetingZone);
    if ($startDateTime === false) $startDateTime = 0;

    $durationStr = $webinar['duration'] ?? '1 hr';
    $durationSec = meet_parseDuration($durationStr);
    $endDateTime = $startDateTime + $durationSec;

    // Written in the registrant's own timezone when the public page recorded
    // it, otherwise in the webinar's own — which is every registration made
    // before that page collected it.
    $recipientZone = meet_recipientZone($participantData, $meetingZone);
    $zoneLabel = meet_zoneLabel($recipientZone, $startDateTime);
    $zoneSuffix = $zoneLabel === '' ? '' : ' ' . $zoneLabel;
    $emailDate = meet_zonedFormat("M d, Y", $startDateTime, $recipientZone);
    $startTimeDisplay = meet_zonedFormat("h:i A", $startDateTime, $recipientZone) . $zoneSuffix;
    $endTimeDisplay = meet_zonedFormat("h:i A", $endDateTime, $meetingZone) . $zoneSuffix;
    
    // The reminder trusted the stored link outright, so it re-sent whatever
    // shape the record happened to hold — including the retired one.
    $storedParticipantLink = $participantData['meetLink'] ?? '';
    $hostEmail = $webinar['hostEmail'] ?? '';
    $participantMeetingLink = meetAddressedLink(
        meetInvitationLink(
            $redis,
            $webinar['ringqmeetId'] ?? '',
            parse_url($storedParticipantLink, PHP_URL_HOST),
            $meetingName,
            $hostEmail,
            $webinar['hostName'] ?? '',
            '',
            $storedParticipantLink
        ),
        trim(($participantData['firstName'] ?? '') . ' ' . ($participantData['lastName'] ?? '')),
        $participantData['email'] ?? ''
    );
    $reminderToken = ($participantData['role'] ?? '') === 'cohost'
        ? meetWebinarInviteToken($redis, $webinarId, $participantData['id'] ?? '')
        : '';
    $participantMeetingLink = meetInviteLink($participantMeetingLink, $reminderToken);
    
    $subject = "Reminder: " . $meetingName . " starts soon";

    $icsContent = meet_generateICS(
        $webinar['id'] ?? uniqid(),
        $startDateTime,
        $endDateTime,
        $meetingName,
        $webinar['description'] ?? '',
        $hostEmail,
        $webinar['hostName'] ?? 'Host',
        $participantMeetingLink,
        'REQUEST',
        $participantEmail,
        trim(($participantData['firstName'] ?? '') . ' ' . ($participantData['lastName'] ?? ''))
    );

    $htmlBody = meet_mailShell([
        'title' => 'Reminder',
        'recipient' => $participantEmail,
        'intro' => meet_mailIntro(
            $participantData['firstName'] ?? 'there',
            $reminderToken !== ''
                ? 'This is a friendly reminder that the following webinar is starting soon. You are joining as a co-host; your link is personal.'
                : 'This is a friendly reminder that the following webinar is starting soon.'
        ),
        'rows' =>
            meet_mailRow('&#9636;', 'Webinar', htmlspecialchars($meetingName))
            . meet_mailRow(meet_mailDayChip($startDateTime, $recipientZone), 'Date', $emailDate)
            . meet_mailRow('&#9719;', 'Time', $startTimeDisplay . ' - ' . $endTimeDisplay, meet_timezoneNoteHtml($recipientZone, $startDateTime, $meetingZone))
            . meet_mailRow(meet_mailInitials($webinar['hostName'] ?? 'Host'), 'Organizer', htmlspecialchars($webinar['hostName'] ?? 'Host')),
        'cta' => ['href' => $participantMeetingLink, 'label' => $reminderToken !== '' ? 'Join as Co-host' : 'Join Webinar'],
    ]);

    meet_deliver($email, $participantEmail, $subject, "reminder", $htmlBody, $icsContent, $licenseConfig);
}

function meet_processBooking($redis, $email, $appointmentData, $bookingId, $ownerExtension, $ownerDomain, $ownerDomainUuid, $licenseConfig, $userType) {
    // Fetch booking configuration
    $key = "booking:" . $ownerExtension . ":" . $ownerDomainUuid;
    $bookingList = $redis->lrange($key, 0, -1);
    
    $bookingConfig = null;
    $bookingTypeId = $appointmentData['bookingTypeId'] ?? $bookingId;

    foreach ($bookingList as $item) {
        $b = json_decode($item, true);
        if (($b['id'] ?? '') == $bookingTypeId) {
            $bookingConfig = $b;
            break;
        }
    }

    if (!$bookingConfig) {
        echo "Booking config not found for ID: $bookingTypeId\n";
        return;
    }

    $guestEmail = $appointmentData['guestEmail'] ?? '';
    if (!$guestEmail) return;

    $meetingName = $bookingConfig['meetingTitle'] ?? 'Booking';
    $description = $bookingConfig['description'] ?? '';
    
    $dateStr = $appointmentData['date'] ?? ''; 
    $timeStr = $appointmentData['time'] ?? ''; 
    
    $meetingZone = meet_timezoneOf($bookingConfig);
    $startDateTime = meet_zonedTimestamp($dateStr, $timeStr, $meetingZone);
    
    $durationStr = $bookingConfig['duration'] ?? '30 min';
    $durationSec = meet_parseDuration($durationStr);
    $endDateTime = $startDateTime ? ($startDateTime + $durationSec) : null;

    // Written in the guest's own timezone when the booking page recorded it.
    $recipientZone = meet_recipientZone($appointmentData, $meetingZone);
    $zoneLabel = meet_zoneLabel($recipientZone, $startDateTime);
    $zoneSuffix = $zoneLabel === '' ? '' : ' ' . $zoneLabel;
    $emailDate = $startDateTime ? meet_zonedFormat("M d, Y", $startDateTime, $recipientZone) : '-';
    $startTimeDisplay = $startDateTime ? meet_zonedFormat("h:i A", $startDateTime, $recipientZone) . $zoneSuffix : '-';
    $endTimeDisplay = $endDateTime ? meet_zonedFormat("h:i A", $endDateTime, $recipientZone) . $zoneSuffix : '-';
    
    $storedGuestMeetingLink = $appointmentData['meetLink'] ?? '';
    $hostEmail = $bookingConfig['hostEmail'] ?? 'noreply@ringq.com';
    // The reference lives on the APPOINTMENT, not on the booking type: every
    // guest booking mints its own room, and the booking config's own
    // `ringqmeetId` is null on every record measured on the test host. Reading
    // it from the config is what left `meetCreateRoomLink` with an empty
    // reference, which it refuses outright -- so the room was never opened and
    // the invitation carried a link the lobby answered "No meeting matches
    // that link or code" to. The config is kept as the fallback for any older
    // row that did carry one.
    $ringqmeetId = $appointmentData['ringqmeetId'] ?? '';
    if (trim((string)$ringqmeetId) === '') {
        $ringqmeetId = $bookingConfig['ringqmeetId'] ?? '';
    }
    // Booking is the one flow whose job carries a tenant scope, so the fallback
    // lookup inside is scoped exactly as the unique index is.
    $guestMeetingLink = meetInvitationLink(
        $redis,
        $ringqmeetId,
        $ownerDomain,
        $meetingName,
        $hostEmail,
        $bookingConfig['hostName'] ?? '',
        $ownerDomainUuid ?? '',
        $storedGuestMeetingLink
    );
    // The host link is marked first, then addressed: meetAddressedLink picks its
    // separator from what is already there, so `?as=host` becomes `&name=`.
    $hostMeetingLink = meetAddressedLink(
        meetHostLink($guestMeetingLink),
        $bookingConfig['hostName'] ?? '',
        $hostEmail
    );
    $guestMeetingLink = meetAddressedLink(
        $guestMeetingLink,
        $appointmentData['guestName'] ?? '',
        $guestEmail
    );

    // Preparation for HTML body details
    $detailsHtml = '';
    if ($userType == 'internal' || $userType == 'custom') {
        $datesList = '-';
        if (!empty($bookingConfig['selectedDates']) && is_array($bookingConfig['selectedDates'])) {
            $formattedDates = array_map(function($isoDate) {
                $ts = strtotime($isoDate);
                return $ts ? date("M d, Y", $ts) : $isoDate;
            }, $bookingConfig['selectedDates']);
            $datesList = implode(", ", $formattedDates);
        }

        $detailsHtml = '
            <tr>
                <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Duration:</strong></td>
                <td style="padding: 8px 0; border-bottom: 1px solid #eee;">' . htmlspecialchars($durationStr) . '</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Selected Dates:</strong></td>
                <td style="padding: 8px 0; border-bottom: 1px solid #eee;">' . $datesList . '</td>
            </tr>';
    } else {
        $detailsHtml = '
            <tr>
                <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Date:</strong></td>
                <td style="padding: 8px 0; border-bottom: 1px solid #eee;">' . $emailDate . '</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Time:</strong></td>
                <td style="padding: 8px 0; border-bottom: 1px solid #eee;">' . $startTimeDisplay . ' - ' . $endTimeDisplay . '</td>
            </tr>';
    }

    $detailsHtml .= '
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Organizer:</strong></td>
            <td style="padding: 8px 0; border-bottom: 1px solid #eee;">' . htmlspecialchars($bookingConfig['hostName'] ?? 'Host') . '</td>
        </tr>';

    // 1. Send to Guest/Internal User
    $icsContentGuest = null;
    if ($startDateTime !== false && $startDateTime > 0) {
        $icsContentGuest = meet_generateICS(
            $appointmentData['id'] ?? uniqid(),
            $startDateTime,
            $endDateTime,
            $meetingName,
            $description,
            $hostEmail,
            $bookingConfig['hostName'] ?? 'Host',
            $guestMeetingLink,
            'REQUEST',
            $guestEmail,
            $appointmentData['guestName'] ?? ''
        );
    } else {
        echo "[Booking] ICS skipped for guest: invalid startDateTime (date='" . ($appointmentData['date'] ?? '') . "', time='" . ($appointmentData['time'] ?? '') . "')\n";
    }

    $htmlBodyGuest = meet_mailShell([
        'title' => 'New Meeting',
        'recipient' => $guestEmail,
        'intro' => meet_mailIntro(
            $appointmentData['guestName'] ?? 'Guest',
            'Your meeting <strong>' . htmlspecialchars($meetingName) . '</strong> has been scheduled. The invitation is attached &mdash; open it to add this to your calendar.'
        ),
        'rows' =>
            meet_mailRow(meet_mailInitials($bookingConfig['hostName'] ?? 'Host'), 'Organizer', htmlspecialchars($bookingConfig['hostName'] ?? 'Host'))
            . meet_mailRow(meet_mailDayChip($startDateTime, $recipientZone), 'Date', $emailDate)
            . meet_mailRow('&#9719;', 'Time', $startTimeDisplay . ' - ' . $endTimeDisplay, meet_timezoneNoteHtml($recipientZone, $startDateTime, $meetingZone))
            . meet_mailRow('&#9636;', 'Notes', htmlspecialchars($description ?: 'None')),
        'cta' => ['href' => $guestMeetingLink, 'label' => 'Join Meeting'],
    ]);

    meet_deliver($email, $guestEmail, $meetingName, "scheduled", $htmlBodyGuest, $icsContentGuest, $licenseConfig);

    if ($userType == 'external') {
        // 2. Send to Host
        $icsContentHost = null;
        if ($startDateTime !== false && $startDateTime > 0) {
            $icsContentHost = meet_generateICS(
                $appointmentData['id'] ?? uniqid(),
                $startDateTime,
                $endDateTime,
                $meetingName,
                $description,
                $hostEmail,
                $bookingConfig['hostName'] ?? 'Host',
                $hostMeetingLink,
                'REQUEST',
                $hostEmail,
                $bookingConfig['hostName'] ?? ''
            );
        } else {
            echo "[Booking] ICS skipped for host: invalid startDateTime (date='" . ($appointmentData['date'] ?? '') . "', time='" . ($appointmentData['time'] ?? '') . "')\n";
        }

        $htmlBodyHost = meet_mailShell([
            'title' => 'New Booking',
            'recipient' => $hostEmail,
            'intro' => meet_mailIntro(
                $bookingConfig['hostName'] ?? 'Host',
                'A new booking has been scheduled. The invitation is attached &mdash; open it to add this to your calendar.'
            ),
            'rows' =>
                meet_mailRow(meet_mailInitials($appointmentData['guestName'] ?? 'Guest'), 'Guest', htmlspecialchars($appointmentData['guestName'] ?? 'Guest'))
                . meet_mailRow('@', 'Email', meet_mailAddress($appointmentData['guestEmail'] ?? ''))
                . meet_mailRow(meet_mailDayChip($startDateTime, $recipientZone), 'Date', $emailDate)
                . meet_mailRow('&#9719;', 'Time', $startTimeDisplay . ' - ' . $endTimeDisplay, meet_timezoneNoteHtml($recipientZone, $startDateTime, $meetingZone))
                . meet_mailRow('&#9636;', 'Notes', htmlspecialchars($appointmentData['guestNotes'] ?? 'None')),
            'cta' => ['href' => $hostMeetingLink, 'label' => 'Join Meeting'],
        ]);

        meet_deliver($email, $hostEmail, "New Booking: " . $meetingName, "scheduled", $htmlBodyHost, $icsContentHost, $licenseConfig);
    }
}

function meet_processBookingCancellation($redis, $email, $appointmentData, $bookingId, $ownerExtension, $ownerDomain, $ownerDomainUuid, $licenseConfig) {
    // Fetch booking configuration
    $key = "booking:" . $ownerExtension . ":" . $ownerDomainUuid;
    $bookingList = $redis->lrange($key, 0, -1);
    
    $bookingConfig = null;
    $bookingTypeId = $appointmentData['bookingTypeId'] ?? $bookingId;

    foreach ($bookingList as $item) {
        $b = json_decode($item, true);
        if (($b['id'] ?? '') == $bookingTypeId) {
            $bookingConfig = $b;
            break;
        }
    }

    if (!$bookingConfig) return;

    $guestEmail = $appointmentData['guestEmail'] ?? '';
    if (!$guestEmail) return;

    $meetingName = $bookingConfig['meetingTitle'] ?? 'Booking';
    $description = $bookingConfig['description'] ?? '';
    $dateStr = $appointmentData['date'] ?? ''; 
    $timeStr = $appointmentData['time'] ?? ''; 
    $meetingZone = meet_timezoneOf($bookingConfig);
    $startDateTime = meet_zonedTimestamp($dateStr, $timeStr, $meetingZone);
    $durationStr = $bookingConfig['duration'] ?? '30 min';
    $durationSec = meet_parseDuration($durationStr);
    $endDateTime = $startDateTime ? ($startDateTime + $durationSec) : null;

    $recipientZone = meet_recipientZone($appointmentData, $meetingZone);
    $zoneLabel = meet_zoneLabel($recipientZone, $startDateTime);
    $zoneSuffix = $zoneLabel === '' ? '' : ' ' . $zoneLabel;
    $emailDate = $startDateTime ? meet_zonedFormat("M d, Y", $startDateTime, $recipientZone) : '-';
    $startTimeDisplay = $startDateTime ? meet_zonedFormat("h:i A", $startDateTime, $recipientZone) . $zoneSuffix : '-';
    $hostEmail = $bookingConfig['hostEmail'] ?? 'noreply@ringq.com';

    $icsContent = null;
    if ($startDateTime !== false && $startDateTime > 0) {
        $icsContent = meet_generateICS(
            $appointmentData['id'] ?? uniqid(),
            $startDateTime,
            $endDateTime,
            $meetingName,
            $description,
            $hostEmail,
            $bookingConfig['hostName'] ?? 'Host',
            '',
            'CANCEL',
            $guestEmail,
            $appointmentData['guestName'] ?? ''
        );
    } else {
        echo "[Booking] ICS skipped for cancellation: invalid startDateTime (date='" . ($appointmentData['date'] ?? '') . "', time='" . ($appointmentData['time'] ?? '') . "')\n";
    }

    $htmlBody = meet_mailShell([
        'title' => 'Meeting Cancelled',
        'recipient' => $guestEmail,
        'intro' => meet_mailIntro(
            $appointmentData['guestName'] ?? 'Guest',
            'The following meeting has been cancelled and removed from the schedule.'
        ),
        'rows' =>
            meet_mailRow('&#9636;', 'Meeting', htmlspecialchars($meetingName))
            . meet_mailRow(meet_mailDayChip($startDateTime, $recipientZone), 'Date', $emailDate)
            . meet_mailRow('&#9719;', 'Time', $startTimeDisplay, meet_timezoneNoteHtml($recipientZone, $startDateTime, $meetingZone))
            . meet_mailRow(meet_mailInitials($bookingConfig['hostName'] ?? 'Host'), 'Organized by', htmlspecialchars($bookingConfig['hostName'] ?? 'Host')),
        'after' => meet_mailNote('We apologise for any inconvenience. The attached update removes this meeting from your calendar.'),
    ]);

    meet_deliver($email, $guestEmail, "Cancelled: " . $meetingName, "cancellation", $htmlBody, $icsContent, $licenseConfig);
}

/**
 * Escape a TEXT value for iCalendar (RFC 5545 section 3.3.11).
 *
 * The original only replaced newlines, which left `,` and `;` — both field
 * separators in this format — to be read as structure by the parser. A meeting
 * called "Review, then sign-off" arrived truncated or rejected depending on the
 * calendar client.
 *
 * The backslash MUST be escaped first. Doing it last would escape the
 * backslashes this function itself just introduced, and every comma would
 * arrive as a literal "\,".
 */
function meet_icsEscape($text) {
    $text = str_replace('\\', '\\\\', (string)$text);
    $text = str_replace(["\r\n", "\r", "\n"], '\\n', $text);
    $text = str_replace(';', '\\;', $text);
    $text = str_replace(',', '\\,', $text);
    return $text;
}

/**
 * Fold one content line to 75 octets (RFC 5545 section 3.1).
 *
 * The limit is octets, not characters, so a naive substr can cut a multi-byte
 * character in half and hand the client a broken sequence — a real risk here
 * because meeting titles carry accents and non-Latin scripts. The split point
 * walks back off any UTF-8 continuation byte (10xxxxxx) before cutting.
 */
function meet_icsFold($line) {
    $line = (string)$line;
    if (strlen($line) <= 75) {
        return $line . "\r\n";
    }
    $out = '';
    $first = true;
    while (strlen($line) > 0) {
        // Continuation lines are prefixed with a space, which counts toward 75.
        $limit = $first ? 75 : 74;
        if (strlen($line) <= $limit) {
            $out .= ($first ? '' : ' ') . $line . "\r\n";
            break;
        }
        $take = $limit;
        while ($take > 0 && (ord($line[$take]) & 0xC0) === 0x80) {
            $take--;
        }
        if ($take === 0) {
            $take = $limit; // a single character longer than the limit; cut it
        }
        $out .= ($first ? '' : ' ') . substr($line, 0, $take) . "\r\n";
        $line = substr($line, $take);
        $first = false;
    }
    return $out;
}

/**
 * $attendeeEmail is what makes the attachment visible at all in Gmail.
 *
 * Email.php is ionCube-encoded and hardcodes the part as
 * `text/calendar; charset=UTF-8; method=REQUEST`, so Gmail treats it as a
 * meeting INVITATION rather than a file. An invitation carrying no ATTENDEE
 * cannot be rendered — Gmail then shows neither an RSVP card nor a downloadable
 * attachment, and the .ics simply disappears. That is why no invitation this
 * system has ever sent showed an attachment, before or after the template
 * rebuild.
 *
 * Naming the recipient makes the REQUEST valid, so the invitation renders and
 * can be added to their calendar. The accepted consequence is that Gmail and
 * Outlook now offer Yes/No/Maybe and the organiser receives RSVP replies.
 */
function meet_generateICS($uid, $startTs, $endTs, $summary, $description, $organizerEmail, $organizerName, $url, $method = 'REQUEST', $attendeeEmail = '', $attendeeName = '') {
    // The join link belongs in LOCATION. Google Calendar does not surface the
    // URL property at all, so an invitation saved to a calendar used to open
    // with no way back into the meeting — the one thing the attachment exists
    // for. It goes into the description too, because clients disagree about
    // which field they show and a duplicated link costs nothing.
    $fullDescription = (string)$description;
    if ($url) {
        $fullDescription = trim($fullDescription) === ''
            ? 'Join the meeting: ' . $url
            : rtrim($fullDescription) . "\n\nJoin the meeting: " . $url;
    }

    $icsContent = "BEGIN:VCALENDAR\r\n";
    $icsContent .= "VERSION:2.0\r\n";
    $icsContent .= "PRODID:-//RingQ//EN\r\n";
    $icsContent .= "CALSCALE:GREGORIAN\r\n";
    $icsContent .= "METHOD:" . $method . "\r\n";
    $icsContent .= "BEGIN:VEVENT\r\n";
    $icsContent .= meet_icsFold("UID:" . $uid);
    // DTSTAMP/DTSTART/DTEND are absolute UTC instants and are deliberately
    // untouched: the recipient's own calendar converts them, which is what
    // makes an invitation read correctly in every timezone.
    $icsContent .= "DTSTAMP:" . gmdate("Ymd\THis\Z") . "\r\n";
    $icsContent .= "DTSTART:" . gmdate("Ymd\THis\Z", $startTs) . "\r\n";
    $icsContent .= "DTEND:" . gmdate("Ymd\THis\Z", $endTs) . "\r\n";
    $icsContent .= meet_icsFold("SUMMARY:" . meet_icsEscape($summary));
    $icsContent .= meet_icsFold("DESCRIPTION:" . meet_icsEscape($fullDescription));
    if ($url) {
        $icsContent .= meet_icsFold("LOCATION:" . meet_icsEscape($url));
    }
    if ($method === 'CANCEL') {
        $icsContent .= "STATUS:CANCELLED\r\n";
    } else {
        $icsContent .= "STATUS:CONFIRMED\r\n";
    }
    $icsContent .= "SEQUENCE:0\r\n";
    $icsContent .= "TRANSP:OPAQUE\r\n";
    $icsContent .= meet_icsFold(
        "ORGANIZER;CN=\"" . str_replace('"', "'", $organizerName) . "\":MAILTO:" . $organizerEmail
    );
    if ($attendeeEmail !== '') {
        $cn = str_replace('"', "'", $attendeeName !== '' ? $attendeeName : $attendeeEmail);
        $icsContent .= meet_icsFold(
            'ATTENDEE;CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE;CN="'
            . $cn . '":MAILTO:' . $attendeeEmail
        );
    }
    if ($url) {
        $icsContent .= meet_icsFold("URL:" . $url);
    }
    $icsContent .= "END:VEVENT\r\n";
    $icsContent .= "END:VCALENDAR\r\n";

    return $icsContent;
}

/**
 * The meeting's own timezone, or null when the row does not state one.
 *
 * Null is a real answer and the whole migration rests on it: rows written
 * before schedules, bookings and webinars carried a timezone have nothing
 * here, and every timestamp below keeps its original behaviour for those. If
 * an absent zone were replaced by a default, every meeting already sitting in
 * somebody's calendar would silently move.
 *
 * An unparseable zone is treated as absent rather than fatal — a typo in a
 * stored row must not stop an invitation going out.
 */
function meet_timezoneOf($row) {
    if (!is_array($row)) return null;
    $name = isset($row['timezone']) ? trim((string)$row['timezone']) : '';
    if ($name === '') return null;
    try {
        return new \DateTimeZone($name);
    } catch (\Exception $e) {
        return null;
    }
}

/**
 * A stored date and time as a UNIX timestamp, read in the meeting's own zone.
 *
 * Before this, every site did strtotime($date . ' ' . $time), which interprets
 * in PHP's *default* timezone, and the calendar attachment was then written
 * with gmdate(...Z). The chain is self-consistent, so it was correct only when
 * the organiser happened to mean the server's timezone; every other guest was
 * invited to the wrong hour.
 *
 * The calendar date is taken literally when the stored value starts with one.
 * Producers store both `2026-08-19` and a full ISO-8601 string, and the offset
 * in the second form is the *browser's* — running that through strtotime and
 * back out through date() can land a day earlier or later than the day the
 * organiser picked.
 */
function meet_zonedTimestamp($dateStr, $timeStr, $zone = null) {
    $dateStr = trim((string)$dateStr);
    $timeStr = trim((string)$timeStr);
    // A real stored value meaning "no date", not a parse failure.
    if ($dateStr === '' || strpos($dateStr, '0000-00-00') === 0) return false;

    if ($zone === null) {
        // The original path, reproduced exactly: every caller normalised the
        // date to Y-m-d in the server's timezone before adding the time.
        // Schedules store `date` as a full ISO-8601 string, so skipping that
        // step would move every legacy schedule by the distance between the
        // stored instant and local midnight.
        $base = strtotime($dateStr);
        if ($base === false) return false;
        $dateOnly = date('Y-m-d', $base);
        return $timeStr === ''
            ? strtotime($dateOnly)
            : strtotime($dateOnly . ' ' . $timeStr);
    }

    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $dateStr, $m)) {
        $dateOnly = $m[1];
    } else {
        $base = strtotime($dateStr);
        if ($base === false) return false;
        $dateOnly = date('Y-m-d', $base);
    }

    try {
        $dt = new \DateTime($dateOnly . ' ' . ($timeStr === '' ? '00:00' : $timeStr), $zone);
    } catch (\Exception $e) {
        return false;
    }
    return $dt->getTimestamp();
}

/**
 * The timezone an invitation should be WRITTEN in.
 *
 * The public booking and webinar pages record the zone the guest was reading
 * the offered times in, so their copy of the email can be written in their own
 * clock rather than the organiser's. Falls back to the meeting's own timezone
 * when the guest's is absent or unusable — which is every row written before
 * the pages collected it, and every invitation sent to an internal user who
 * never saw a public page.
 */
function meet_recipientZone($row, $meetingZone) {
    if (!is_array($row)) return $meetingZone;
    $name = isset($row['guestTimezone']) ? trim((string)$row['guestTimezone']) : '';
    if ($name === '') return $meetingZone;
    try {
        return new \DateTimeZone($name);
    } catch (\Exception $e) {
        return $meetingZone;
    }
}

/**
 * The line under the time that stops a reader in another country guessing.
 *
 * The times above are the ORGANISER's, and this email cannot be written in the
 * recipient's own clock: the public booking and webinar pages collect the
 * guest's timezone, but the controllers that store those registrations build
 * their rows from an explicit field list and drop it, and both ship
 * ionCube-encoded. So instead of a conversion nobody can make here, this says
 * which clock the numbers are in and points at the attachment, which IS an
 * absolute instant and does render in the reader's own local time.
 *
 * Empty when the meeting states no timezone: a row written before that field
 * existed has no clock worth naming, and naming the server's would be a guess.
 */
/**
 * $hasAttachment must be false on an email that carries no .ics.
 *
 * The webinar registration notice tells a host somebody signed up; it
 * deliberately attaches nothing, because an invitation per registrant would put
 * a duplicate of the same webinar in the host's calendar once per person. The
 * note below otherwise ends by pointing at an attachment that is not there.
 */
function meet_timezoneNoteHtml($zone, $ts, $organiserZone = null, $hasAttachment = true) {
    if ($zone === null) return '';
    $describe = function ($z) use ($ts) {
        $label = meet_zoneLabel($z, $ts);
        return $label === '' || $label === $z->getName()
            ? $z->getName()
            : $z->getName() . ' (' . $label . ')';
    };

    $note = 'All times are shown in ' . htmlspecialchars($describe($zone)) . '.';
    // Both clocks, when the reader's is not the organiser's. Naming only one
    // of two different times is how somebody joins an hour late.
    if ($organiserZone !== null && $organiserZone->getName() !== $zone->getName()) {
        $note .= ' The organiser is in ' . htmlspecialchars($describe($organiserZone))
            . ', where this is ' . htmlspecialchars(meet_zonedFormat('M d, h:i A', $ts, $organiserZone)) . '.';
    } elseif ($hasAttachment) {
        $note .= ' The attached calendar invitation will appear in your own local time.';
    }

    // No `!important` here, and the class is what carries the dark override:
    // an inline !important outranks a class rule that also carries it, so the
    // note would have stayed dark grey on a dark card — unreadable in exactly
    // the mode it was meant to survive.
    $c = meet_mailColors();
    return '
                                                <div class="m-muted" style="margin-top: 6px; font-family: Arial, Helvetica, sans-serif; font-size: 12px; line-height: 17px; color: ' . $c['muted'][0] . ';">
                                                    ' . $note . '
                                                </div>';
}

/** Format a timestamp in the meeting's zone; server default when absent. */
function meet_zonedFormat($format, $ts, $zone = null) {
    if ($ts === false || $ts === null) return '';
    if ($zone === null) return date($format, $ts);
    $dt = new \DateTime('@' . (int)$ts);
    $dt->setTimezone($zone);
    return $dt->format($format);
}

/**
 * What to print beside a time so a guest in another country can read it.
 *
 * Prefers the abbreviation ("EDT"), which is what people recognise, and falls
 * back to the zone name where PHP only offers a numeric offset ("+08"), which
 * reads as noise next to a time.
 */
function meet_zoneLabel($zone, $ts) {
    if ($zone === null) return '';
    $dt = new \DateTime('@' . (int)($ts ?: time()));
    $dt->setTimezone($zone);
    $abbr = $dt->format('T');
    return preg_match('/^[A-Za-z]+$/', $abbr) ? $abbr : $zone->getName();
}

function meet_parseDuration($durationStr) {
    $hours = 0;
    $minutes = 0;
    
    if (preg_match('/(\d+)\s*hr/', $durationStr, $matches)) {
        $hours = intval($matches[1]);
    }
    if (preg_match('/(\d+)\s*min/', $durationStr, $matches)) {
        $minutes = intval($matches[1]);
    }
    
    return ($hours * 3600) + ($minutes * 60);
}
