<?php
declare(strict_types=1);

use Phalcon\Mvc\View;

/**
 * RingQ session and tenant-identity endpoints for Meet.
 *
 * PHP is the only component that knows what a RINGQ session means and which
 * FusionPBX domain it belongs to, so node asks rather than guessing. The
 * previous meet-api duplicated all of this against its own database, which is
 * how it ended up single-tenant.
 *
 * Convention-routed: meetApi/<action> (for example /meetApi/accessToken).
 *
 * tenantMeetConfig is loopback-only. accessToken is the intentionally public
 * same-origin exchange used by authenticated RingQ apps; it stores only a
 * short-lived opaque token hash.
 */
class MeetApiController extends ControllerBase
{
    /** Addresses that count as "this machine". */
    private const LOOPBACK = ['127.0.0.1', '::1', '::ffff:127.0.0.1'];

    /**
     * Memoised result of [identityFromDatabase].
     *
     * `false` is "not looked up yet" and null is a real answer meaning "there
     * is no row" -- distinguishing them is what stops a user with no q_users
     * row re-querying on every read.
     */
    private $identityRow = false;

    private function json($payload)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $this->response->setHeader("Cache-Control", "no-store");
        return $this->response->setContent(json_encode($payload));
    }

    /**
     * POST /meetApi/accessToken
     *
     * Exchanges the normal RingQ login session for a random, 15-minute bearer.
     * Only its SHA-256 hash is stored, so a Redis snapshot cannot be used to join
     * meetings. Calling this action again is the refresh operation; tokens are
     * intentionally not refreshed by the Node control plane.
     */
    public function accessTokenAction()
    {
        if (!$this->request->isPost()) {
            $this->response->setStatusCode(405, "Method Not Allowed");
            return $this->json(["result" => false, "code" => "method_not_allowed"]);
        }

        $session = $this->session->get("auth");
        if (!$session || empty($session["domain_uuid"])) {
            $this->response->setStatusCode(401, "Unauthorized");
            return $this->json(["result" => false, "code" => "unauthorized"]);
        }

        $ownerKey = (string)($session["extension_uuid"]
            ?? $session["user_uuid"]
            ?? $session["extension"]
            ?? '');
        if ($ownerKey === '') {
            $this->response->setStatusCode(401, "Unauthorized");
            return $this->json(["result" => false, "code" => "unauthorized"]);
        }

        // Who is this, in words a person would recognise.
        //
        // The session is asked first and the database only when it cannot
        // answer, because a session that carries the name is both cheaper and
        // authoritative -- an admin may have renamed the row since login.
        //
        // The fallback is not belt-and-braces, it is the fix for a real class
        // of failure: there is more than one writer of the "auth" session and
        // they drift. DesktopController::loginAction wrote five routing keys
        // and no identity at all, so this read fell through to $ownerKey and
        // minted a uuid into the LiveKit token as the participant's *name* --
        // every other person in the meeting saw
        // "47180ea5-b577-47d5-a5b6-60af0846e985" with "47" for their avatar
        // initials. Enriching that writer fixes new logins; resolving it here
        // fixes the sessions already open, and any writer added next year.
        //
        // Ordered ahead of "username" deliberately: on this schema username is
        // often an opaque login token (a 20-character hex blob), which is an
        // identifier wearing a name's clothes exactly like the uuid was.
        $displayName = trim((string)($session["firstname"] ?? '') . ' ' . (string)($session["lastname"] ?? ''));
        if ($displayName === '') {
            $row = $this->identityFromDatabase($session);
            if ($row !== null) {
                $displayName = trim((string)$row["firstname"] . ' ' . (string)$row["lastname"]);
            }
        }
        if ($displayName === '') {
            $displayName = (string)($session["username"] ?? $ownerKey);
        }
        // The participant's profile photo, so a meeting shows the person rather
        // than the two letters of their name. Built here because this is the one
        // place the whole stack already trusts to describe who the caller is.
        //
        // Deliberately NOT lib/assets.dart's avatarPath(): that helper
        // substitutes https://<host>:8443/img/ringq-square.png when a user has
        // no photo, which is right for a profile header and wrong for a meeting
        // — it would put the identical company logo on every tile in the room,
        // strictly worse than initials, which at least tell people apart.
        // Absent must stay absent so the client falls back to the acronym.
        //
        // rawurlencode because the filename is derived from user-supplied data
        // at upload time and this string becomes an <img src> in every
        // participant's browser, guests included.
        //
        // Resolved from the database on the same terms as the name above, and
        // for the same reason: a lean session has no photo either, so the tile
        // fell back to the initials of a uuid. The lookup is memoised, so the
        // name and the photo together cost at most one query per exchange.
        $avatarImage = trim((string)($session["image"] ?? ''));
        if ($avatarImage === '') {
            $row = $this->identityFromDatabase($session);
            if ($row !== null) {
                $avatarImage = trim((string)$row["image"]);
            }
        }
        $avatarHost = trim((string)($session["subdomain"] ?? ''));
        $avatarUrl = '';
        if ($avatarImage !== ''
            && strcasecmp($avatarImage, 'null') !== 0
            && $avatarHost !== ''
        ) {
            $avatarUrl = "https://" . $avatarHost . ":8443/avr/" . rawurlencode($avatarImage);
        }

        $canHost = !(isset($session["permissionMode"]) && (int)$session["permissionMode"] === 10);
        $packageName = (string)($session["packageName"] ?? '');
        if ($packageName !== '' && stripos($packageName, 'Essential') !== false) {
            $canHost = false;
        }

        try {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        } catch (\Throwable $e) {
            $this->response->setStatusCode(503, "Service Unavailable");
            return $this->json(["result" => false, "code" => "token_unavailable"]);
        }

        $ttl = 15 * 60;
        $record = [
            "ownerKey"    => $ownerKey,
            "displayName" => $displayName,
            // Same resolution as the name and the photo: a lean session
            // carries no address, and the control plane stores this one.
            "email"       => $this->sessionEmail($session),
            "avatarUrl"   => $avatarUrl,
            "domainUuid"  => strtolower((string)$session["domain_uuid"]),
            "canHost"     => $canHost,
            "permissions" => ["host" => $canHost, "join" => true],
            "issuedAt"    => time(),
            "expiresAt"   => time() + $ttl,
        ];
        $key = "meet:access:" . hash('sha256', $token);
        try {
            $stored = $this->redis->setex($key, $ttl, json_encode($record));
            if ($stored === false) {
                throw new \RuntimeException("Redis rejected access token");
            }
        } catch (\Throwable $e) {
            $this->response->setStatusCode(503, "Service Unavailable");
            return $this->json(["result" => false, "code" => "token_unavailable"]);
        }

        // Older ionCube ACL builds may have already marked this action as a
        // redirect before dispatching it. The authenticated exchange succeeded,
        // so explicitly clear that stale status for Fetch/HTTP clients.
        $this->response->setStatusCode(200, "OK");
        return $this->json([
            "result"      => true,
            "accessToken" => $token,
            "tokenType"   => "Bearer",
            "expiresIn"   => $ttl,
            "expiresAt"   => $record["expiresAt"],
            // Additive, and it exists to close a class of client bug rather
            // than as a convenience.
            //
            // A host app that caches the profile it was handed at login shows
            // the PREVIOUS account's photo after a switch, because an account
            // with no photo answers "" and a cache that only overwrites
            // non-empty values keeps what it had. The name never does this --
            // a name is never empty -- which is exactly why the failure reads
            // as "the avatar is from the last account but the name is fine",
            // and why it is easy to look for in the wrong place.
            //
            // Taken from $record, so this is the same identity the control
            // plane mints into the LiveKit token: the pre-join lobby cannot
            // disagree with the tile in the meeting. A client that renders
            // from here needs to cache nothing and cannot go stale, and an
            // empty avatarUrl is a real answer meaning "no photo, draw the
            // initials" -- never "leave the last one up".
            "identity"    => [
                "displayName" => $record["displayName"],
                "email"       => $record["email"],
                "avatarUrl"   => $record["avatarUrl"],
            ],
        ]);
    }

    /**
     * Refuse anything not from loopback.
     *
     * Deliberately uses the real peer address and NOT X-Forwarded-For, which a
     * client controls. If this ever gets proxied, it must fail closed.
     */
    private function denyRemote()
    {
        $peer = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!in_array($peer, self::LOOPBACK, true)) {
            $this->response->setStatusCode(403, "Forbidden");
            return $this->json([
                "result"  => false,
                "message" => "This endpoint is available on loopback only.",
            ]);
        }
        return null;
    }

    /**
     * The caller's name, photo and address, read from the database.
     *
     * Only reached when the session cannot answer, and memoised, so the
     * ordinary browser session -- which carries all three -- costs no query at
     * all and a lean one costs exactly one per 15-minute token exchange.
     *
     * Keyed on `user_uuid`, never on `extension_uuid` or `extension`: it is the
     * one identifier every writer of the "auth" session has always set, and it
     * is what `q_users` is keyed by. Scoped to the session's own domain so a
     * stale or tampered session cannot name a user in another tenant.
     *
     * Returns null on anything unexpected -- a missing row, a model failure, a
     * session with no user_uuid. A meeting must never fail to start over a
     * decoration, and returning null simply leaves the previous behaviour.
     */
    private function identityFromDatabase(array $session)
    {
        if ($this->identityRow !== false) {
            return $this->identityRow;
        }
        $this->identityRow = null;

        $userUuid = trim((string)($session["user_uuid"] ?? ''));
        $domainUuid = trim((string)($session["domain_uuid"] ?? ''));
        if ($userUuid === '' || $domainUuid === '') {
            return null;
        }

        try {
            $vUser = VUsers::findFirst([
                "conditions" => "user_uuid = :user_uuid: AND domain_uuid = :domain_uuid:",
                "bind" => [
                    "user_uuid" => $userUuid,
                    "domain_uuid" => $domainUuid,
                ],
            ]);
            if (!$vUser) {
                return null;
            }
            $qUser = QUsers::findFirst([
                "conditions" => "user_uuid = :user_uuid:",
                "bind" => ["user_uuid" => $userUuid],
            ]);
            $this->identityRow = [
                "firstname" => $qUser ? (string)$qUser->first_name : '',
                "lastname"  => $qUser ? (string)$qUser->last_name : '',
                // The bare filename, as the session stores it: the /avr/ URL is
                // built by the caller above.
                "image"     => $qUser ? (string)$qUser->image : '',
                "email"     => (string)$vUser->user_email,
            ];
        } catch (\Throwable $e) {
            $this->identityRow = null;
        }
        return $this->identityRow;
    }

    /** The caller's address, from the session when it has one. */
    private function sessionEmail(array $session): string
    {
        $email = trim((string)($session["email"] ?? ''));
        if ($email !== '') {
            return $email;
        }
        $row = $this->identityFromDatabase($session);
        return $row === null ? '' : (string)$row["email"];
    }

    /**
     * POST /meetApi/tenantMeetConfig
     *
     * The per-tenant meet configuration node needs in order to resolve a
     * request's tenant. Lets one box serve several FusionPBX domains without
     * node holding a copy of the domain table.
     *
     * Deliberately returns identity only. SFU URLs and secrets belong to the
     * central gateway and are never exposed to the tenant application.
     */
    public function tenantMeetConfigAction()
    {
        if ($denied = $this->denyRemote()) {
            return $denied;
        }

        $rawBody = $this->request->getRawBody();
        $data    = json_decode((string)$rawBody, true) ?: [];
        $host    = strtolower(trim((string)($data["host"] ?? '')));
        if ($host === '') {
            $this->response->setStatusCode(400, "Bad Request");
            return $this->json(["result" => false, "message" => "host is required."]);
        }
        // Strip a port if node passed one through.
        if (strpos($host, ':') !== false) {
            $host = explode(':', $host)[0];
        }

        try {
            // FusionPBX stores each tenant domain in v_domains; the meet tenant
            // is whichever domain matches the requested host.
            $row = $this->db->fetchOne(
                "SELECT domain_uuid, domain_name, domain_enabled
                   FROM v_domains
                  WHERE lower(domain_name) = :host
                  LIMIT 1",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ["host" => $host]
            );
        } catch (\Throwable $e) {
            $this->response->setStatusCode(503, "Service Unavailable");
            return $this->json(["result" => false, "message" => "Domain lookup failed."]);
        }

        if (!$row || empty($row["domain_uuid"])) {
            // Unknown host: node must refuse the request rather than fall back
            // to a default tenant.
            $this->response->setStatusCode(404, "Not Found");
            return $this->json(["result" => false, "message" => "Unknown meet host."]);
        }
        $enabled = $row["domain_enabled"] ?? 'true';
        if ($enabled === 'false' || $enabled === false || $enabled === 'f') {
            $this->response->setStatusCode(403, "Forbidden");
            return $this->json(["result" => false, "message" => "Domain is disabled."]);
        }

        $this->response->setStatusCode(200, "OK");
        return $this->json([
            "result"     => true,
            "host"       => $host,
            "domainUuid" => (string)$row["domain_uuid"],
            "domainName" => (string)$row["domain_name"],
        ]);
    }

    /**
     * GET /meetApi/history — meeting history for the logged-in domain.
     *
     * Replaces the legacy lrange('rooms', 0, -1) scan, which required every room
     * ever created to stay in the list that every other meet operation scanned.
     * Reads the durable meet_meetings table via its (domain_uuid, created_at)
     * index, so cost no longer grows with the tenant's lifetime meeting count.
     */
    public function historyAction()
    {
        $session = $this->session->get("auth");
        if (!$session || empty($session["domain_uuid"])) {
            $this->response->setStatusCode(401, "Unauthorized");
            return $this->json(["result" => false, "message" => "Unauthorized."]);
        }

        $limit  = (int)$this->request->get("limit", "int", 50);
        $limit  = max(1, min(200, $limit));
        $page   = max(1, (int)$this->request->get("page", "int", 1));
        $offset = ($page - 1) * $limit;

        try {
            $rows = $this->db->fetchAll(
                "SELECT m.id, m.slug, m.title, m.owner_key, m.meet_type, m.status,
                        m.started_at, m.ended_at, m.created_at,
                        (SELECT count(*) FROM meet_participants p WHERE p.meeting_id = m.id)
                            AS participant_count
                   FROM meet_meetings m
                  WHERE m.domain_uuid = :domain_uuid
                  ORDER BY m.created_at DESC
                  LIMIT :limit OFFSET :offset",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                [
                    "domain_uuid" => (string)$session["domain_uuid"],
                    "limit"       => $limit,
                    "offset"      => $offset,
                ]
            );
        } catch (\Throwable $e) {
            // Schema not yet applied on this tenant — say so actionably.
            $this->response->setStatusCode(503, "Service Unavailable");
            return $this->json([
                "result"  => false,
                "message" => "Meet history is unavailable. Run: php cli.php meetSchema up",
            ]);
        }

        $this->response->setStatusCode(200, "OK");
        return $this->json(["result" => true, "data" => $rows, "page" => $page, "limit" => $limit]);
    }

    /**
     * Operator-facing copy for each reconcile gate, in the order they run.
     *
     * Three strings, because the screen shows them in three places and they do
     * different work: `title` names the step in the checklist and headline,
     * `detail` says what is happening now, and `hint` sets an expectation for
     * the steps that take long enough to look stuck without one. A gate with
     * nothing worth adding leaves `hint` empty rather than padding it.
     */
    private const SETUP_PHASES = [
        "topology"      => ["Server topology", "Checking how this server hosts meetings.", ""],
        "manifest"      => ["Release package", "Verifying the meeting release package.", ""],
        "docker"        => ["Container runtime", "Preparing the container runtime.", ""],
        "preflight"     => ["System requirements", "Checking this server meets the requirements.", ""],
        "dependencies"  => ["Supporting packages", "Installing the packages meetings depend on.", "This step downloads from the package mirrors and can take a few minutes."],
        "legacy"        => ["Previous installation", "Retiring the previous meeting product.", ""],
        "identity"      => ["Server identity", "Registering this server's meeting identity.", ""],
        "pki"           => ["Internal certificates", "Issuing the internal certificates meetings use.", ""],
        "gateway_stage" => ["Meeting gateway", "Installing the meeting gateway.", ""],
        "sfu_stage"     => ["Media server", "Downloading and preparing the media server.", "This is usually the longest part of the setup."],
        "configuration" => ["Meeting configuration", "Writing the meeting configuration.", ""],
        "services"      => ["Services", "Installing the meeting services.", ""],
        "start"         => ["Start media server", "Starting the media server.", ""],
        "health"        => ["Health check", "Waiting for the media server to become healthy.", ""],
        "tenant_scope"  => ["Tenant access", "Verifying access for this account.", ""],
        "transcriber"   => ["Live transcription", "Installing live transcription.", "The speech model is downloaded here, which can take a few minutes."],
        "state"         => ["Finalizing", "Finishing up.", ""],
    ];

    /**
     * The gates above, grouped into the stages the screen actually lists.
     *
     * Seventeen rows is a scrolling list nobody reads; five is a shape you can
     * take in at a glance and still find yourself in. The counts are what the
     * client resolves a step number against, so they must cover every gate in
     * SETUP_PHASES exactly once and in order — a gate added to one and not the
     * other would silently shift every later group.
     */
    private const SETUP_GROUPS = [
        ["Server Checks",                ["topology", "manifest", "docker", "preflight"]],
        ["Environment Setup",            ["dependencies", "legacy", "identity", "pki"]],
        ["Meeting Components",           ["gateway_stage", "sfu_stage"]],
        ["Service Configuration",        ["configuration", "services", "start"]],
        ["Verification & Finalization",  ["health", "tenant_scope", "transcriber", "state"]],
    ];

    /**
     * Which gate a failure code belongs to.
     *
     * Most failures are recorded as the gate name itself, but a few are
     * semantic codes raised inside a gate. Without this the screen can name the
     * reason but not the place, and "where did it stop" is most of what an
     * operator wants to know.
     */
    private const SETUP_FAILURE_GATES = [
        "insufficient_cpu" => "preflight",
        "local_stack"      => "start",
    ];

    /**
     * Why a reconcile stopped, in language the operator can act on.
     *
     * Every gate has an entry: a gate that falls through to the generic message
     * tells the operator only that something went wrong, which is what the
     * screen said before it could name the step at all.
     */
    private const SETUP_FAILURES = [
        "insufficient_cpu" => "This server does not have enough CPU for meetings. Two CPUs are required.",
        "preflight"        => "This server did not meet the requirements for meetings.",
        "topology"         => "This server is not configured to host its own meetings.",
        "manifest"         => "The release package was incomplete. The next update will retry.",
        "docker"           => "The container runtime could not be prepared.",
        "dependencies"     => "Supporting packages could not be installed. The server may not have been able to reach the package mirrors.",
        "legacy"           => "The previous meeting product could not be retired.",
        "identity"         => "This server could not register its meeting identity. Check the license and hostname.",
        "pki"              => "Internal certificates could not be issued.",
        "gateway_stage"    => "The meeting gateway could not be installed.",
        "sfu_stage"        => "The media server could not be prepared. The download may not have completed.",
        "configuration"    => "The meeting configuration could not be written.",
        "services"         => "The meeting services could not be installed.",
        "local_stack"      => "The media server could not be started.",
        "start"            => "The media server could not be started.",
        "health"           => "The media server did not become healthy in time.",
        "tenant_scope"     => "Tenant access could not be verified.",
        "transcriber"      => "Live transcription could not be installed.",
        "state"            => "The installation could not be recorded.",
    ];

    /**
     * GET /meetApi/setupStatus
     *
     * Reports whether the co-located meeting stack is still being installed.
     *
     * This lives in PHP rather than the Meet control plane on purpose: during
     * early setup the Meet API is not routed yet and nginx answers
     * `File not found.`, which the client can only report as an invalid
     * response. The window this endpoint exists to describe is exactly the
     * window the control plane cannot answer in, so it must be served by
     * something that is always routed.
     *
     * Never 5xx on missing state: absent files mean this tenant does not host
     * its own meetings, which is the correct answer for every central-topology
     * tenant and must not read as an outage.
     */
    public function setupStatusAction()
    {
        $session = $this->session->get("auth");
        if (!$session || empty($session["domain_uuid"])) {
            $this->response->setStatusCode(401, "Unauthorized");
            return $this->json(["result" => false, "code" => "unauthorized"]);
        }

        $topology = @file_get_contents("/etc/ringq/meet-topology");
        if ($topology === false || trim((string)$topology) !== "local") {
            $this->response->setStatusCode(200, "OK");
            return $this->json(["result" => true, "state" => "not_applicable"]);
        }

        $progress = $this->readJsonFile("/var/lib/ringq-meet/local-progress.json");
        $result   = $this->readJsonFile("/var/lib/ringq-meet/local-result.json");

        // A reconcile that is still running is the only reason to show a
        // progress screen. The pid check is what distinguishes it from a run
        // killed mid-flight: a Docker PBX recreates its container during every
        // update, orphaning a "running" file that is otherwise indistinguishable
        // from work in progress.
        $running = is_array($progress)
            && ($progress["status"] ?? "") === "running"
            && $this->processIsAlive((int)($progress["pid"] ?? 0));

        if ($running) {
            $gate      = (string)($progress["gate"] ?? "");
            $step      = (int)($progress["step"] ?? 0);
            $startedAt = (string)($progress["startedAt"] ?? "");
            $elapsed   = $startedAt !== "" ? max(0, time() - (int)strtotime($startedAt)) : 0;
            // An unrecognised gate is a newer reconciler than this build.
            // Degrade to a generic phase rather than showing nothing.
            $phase = self::SETUP_PHASES[$gate]
                ?? ["Setting up meetings", "Setting up meetings on this server.", ""];

            $this->response->setStatusCode(200, "OK");
            return $this->json([
                "result"         => true,
                "state"          => "setting_up",
                "step"           => $step,
                "totalSteps"     => (int)($progress["totalSteps"] ?? 0),
                "phase"          => $phase[0],
                "detail"         => $phase[1],
                "hint"           => $phase[2],
                "groups"         => $this->setupGroups(),
                "startedAt"      => $startedAt,
                "elapsedSeconds" => $elapsed,
                "failure"        => null,
            ]);
        }

        if (is_array($result) && ($result["status"] ?? "") === "healthy") {
            // The install finished, which is not the same as everything it
            // promised being usable: freshclam downloads ~195 MB of virus
            // definitions AFTER the reconcile ends, and until it does the
            // attachment scanner cannot start. Meet works throughout, so this
            // is reported alongside `ready` rather than instead of it.
            $this->response->setStatusCode(200, "OK");
            return $this->json([
                "result"  => true,
                "state"   => "ready",
                "pending" => $this->pendingFeatureGroups(),
            ]);
        }

        // Either the reconcile recorded a failure, or it stopped without
        // recording one — a stale "running" file with a dead pid. Both are
        // failures to the operator; only the wording differs.
        $failureCode = "";
        if (is_array($result) && ($result["status"] ?? "") === "failed") {
            $failureCode = (string)($result["failureCode"] ?? "");
        } elseif (is_array($progress) && ($progress["status"] ?? "") === "failed") {
            $failureCode = (string)($progress["gate"] ?? "");
        } elseif (is_array($progress) && ($progress["status"] ?? "") === "running") {
            $failureCode = (string)($progress["gate"] ?? "");
        }

        // A file that exists but cannot be read is a deployment fault, not an
        // install that has yet to start, and reporting it as the latter shows
        // "Preparing to set up meetings" forever on a finished host. This runs
        // as www-data while the reconciler runs as root, so it is worth saying
        // out loud rather than guessing.
        foreach (["/var/lib/ringq-meet/local-progress.json",
                  "/var/lib/ringq-meet/local-result.json"] as $path) {
            if (@file_exists($path) && !@is_readable($path)) {
                $this->response->setStatusCode(200, "OK");
                return $this->json([
                    "result"  => true,
                    "state"   => "failed",
                    "step"    => 0,
                    "phase"   => "",
                    "groups"  => $this->setupGroups(),
                    "failure" => [
                        "code"    => "status_unreadable",
                        "message" => "Meeting setup status cannot be read by the "
                            . "web server. The next update repairs this.",
                    ],
                ]);
            }
        }

        if ($failureCode === "" && !is_array($progress) && !is_array($result)) {
            // Marked local, but nothing has run yet. The update triggers the
            // reconcile asynchronously, so this is a normal early state and
            // must not be reported as a failure.
            $this->response->setStatusCode(200, "OK");
            return $this->json([
                "result"         => true,
                "state"          => "setting_up",
                "step"           => 0,
                "totalSteps"     => count(self::SETUP_PHASES),
                "phase"          => "Preparing to set up meetings",
                "detail"         => "The server is about to begin setting up meetings.",
                "hint"           => "",
                "groups"         => $this->setupGroups(),
                "startedAt"      => "",
                "elapsedSeconds" => 0,
                "failure"        => null,
            ]);
        }

        // Where it stopped is half the answer. Most failures are recorded as
        // the gate itself; the rest are semantic codes raised inside one.
        $failedGate = self::SETUP_FAILURE_GATES[$failureCode] ?? $failureCode;
        $phase      = self::SETUP_PHASES[$failedGate] ?? null;
        $step       = (int)($progress["step"] ?? 0);
        if ($step === 0 && $phase !== null) {
            // A failure recorded without progress can still be placed: the gate
            // order is known, so the step number is derivable from the name.
            $step = (int)array_search($failedGate, array_keys(self::SETUP_PHASES), true) + 1;
        }

        $startedAt = (string)($progress["startedAt"] ?? "");
        $stoppedAt = (string)($progress["updatedAt"] ?? "");
        $elapsed   = ($startedAt !== "" && $stoppedAt !== "")
            ? max(0, (int)strtotime($stoppedAt) - (int)strtotime($startedAt))
            : 0;

        $this->response->setStatusCode(200, "OK");
        return $this->json([
            "result"         => true,
            "state"          => "failed",
            "step"           => $step,
            "totalSteps"     => count(self::SETUP_PHASES),
            "phase"          => $phase[0] ?? "",
            "detail"         => $phase[1] ?? "",
            "hint"           => "",
            "groups"         => $this->setupGroups(),
            "elapsedSeconds" => $elapsed,
            "failure"        => [
                "code"    => $failureCode,
                "message" => self::SETUP_FAILURES[$failureCode]
                    ?? self::SETUP_FAILURES[$failedGate]
                    ?? "Meetings could not be set up on this server.",
            ],
        ]);
    }

    /**
     * The five setup stages, each with the number of gates it covers.
     *
     * Counts rather than gate names: the client resolves a step number against
     * them, and sending the names too would invite a second, disagreeing
     * derivation of the same thing.
     */
    /** Where ClamAV keeps its signature database. */
    private const CLAMAV_DB_DIR = "/var/lib/clamav";

    /**
     * Feature groups that are installed but not yet usable, or that this server
     * will never be able to run.
     *
     * Three states, not two, and the distinction is the point: reporting a
     * feature this server is too small to install as "still setting up" would
     * replace a silent absence with a promise that never arrives, which is
     * worse than the silence. `unavailable` states the requirement instead.
     */
    private function pendingFeatureGroups(): array
    {
        $pending = [];

        $scanner = $this->pendingAttachmentScanner();
        if ($scanner !== null) {
            $pending[] = $scanner;
        }

        $documents = $this->pendingDocumentPipeline();
        if ($documents !== null) {
            $pending[] = $documents;
        }

        return $pending;
    }

    /**
     * @return array<string,mixed>|null null when the pipeline is complete.
     *
     * Checks every program the conversion health check runs, not just
     * LibreOffice: the converter renders through poppler and ImageMagick, so a
     * server with LibreOffice and no `pdfinfo` reports `documentConversion`
     * false with nothing on screen explaining it -- which is exactly the
     * silence this feature exists to remove, reproduced one binary along.
     */
    private function pendingDocumentPipeline()
    {
        $missing = [];
        if (!$this->programExists("libreoffice")) {
            $missing[] = "LibreOffice";
        }
        if (!$this->programExists("pdfinfo") || !$this->programExists("pdftoppm")) {
            $missing[] = "poppler-utils";
        }
        if (!$this->programExists("convert") || !$this->programExists("identify")) {
            $missing[] = "ImageMagick";
        }
        if ($missing === []) {
            return null;
        }

        // Below 3 GB free the reconciler never installs these, so on that
        // server it is a standing fact rather than a wait. Above it, the
        // install failed and the next update retries -- a different sentence,
        // and the operator can act on only one of them.
        $free  = @disk_free_space("/usr");
        $small = is_float($free) && $free < (3 * 1024 * 1024 * 1024);

        return [
            "key"      => "documentPipeline",
            "title"    => "Document conversion",
            "features" => ["documentConversion", "filePresentations"],
            "status"   => $small ? "unavailable" : "stalled",
            "detail"   => $small
                ? "Converting documents needs at least 3 GB of free disk space on "
                    . "this server."
                : "Could not install: " . implode(", ", $missing)
                    . ". The next update retries.",
        ];
    }

    /** @return array<string,mixed>|null null when there is nothing to say. */
    private function pendingAttachmentScanner()
    {
        // Not installed at all. The reconciler skips it below 4 GiB of memory,
        // so on those servers this is a standing fact, not a wait.
        if (!$this->programExists("clamdscan")) {
            return [
                "key"      => "attachmentScanner",
                "title"    => "Attachment scanning",
                "features" => ["chatAttachments", "filePresentations"],
                "status"   => "unavailable",
                "detail"   => "Scanning attachments needs at least 4 GB of memory on "
                    . "this server.",
            ];
        }

        // Already running: nothing to report, and the capability is true.
        // Tested by its socket rather than by asking systemd -- the socket is
        // what `clamdscan` actually connects to, so it answers the question the
        // capability depends on, and it needs no process spawn on a path that
        // is polled.
        if ($this->clamdIsListening()) {
            return null;
        }

        // A directory that cannot be read is reported as nothing rather than as
        // a fault: guessing "stalled" on a healthy server is the same defect as
        // the status endpoint that once read "Preparing to set up meetings"
        // forever because it could not read its own files.
        if (!@is_dir(self::CLAMAV_DB_DIR) || !@is_readable(self::CLAMAV_DB_DIR)) {
            return null;
        }

        $files      = @scandir(self::CLAMAV_DB_DIR) ?: [];
        $bytes      = 0;
        $filesReady = 0;
        $newest     = 0;
        foreach (["main", "daily", "bytecode"] as $definition) {
            if (@is_file(self::CLAMAV_DB_DIR . "/$definition.cvd")
                || @is_file(self::CLAMAV_DB_DIR . "/$definition.cld")) {
                $filesReady++;
            }
        }
        foreach ($files as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }
            // `tmp.*` is what freshclam writes into while downloading, and it
            // holds most of the bytes for most of the wait. Excluding it makes
            // the figure sit still through the largest file, which reads as a
            // stall.
            $bytes  += $this->pathSize(self::CLAMAV_DB_DIR . "/$entry");
            $newest  = max($newest, (int)@filemtime(self::CLAMAV_DB_DIR . "/$entry"));
        }

        // Progress is measured by the directory changing, not by asking systemd
        // whether freshclam is active: a download writes constantly, so a
        // directory untouched for minutes is the honest signal that nothing is
        // happening. Saying "downloading" forever would be a claim the operator
        // cannot act on.
        $downloading = $newest > 0 && (time() - $newest) < 300;
        $complete    = $filesReady >= 3;
        return [
            "key"        => "attachmentScanner",
            "title"      => "Attachment scanning",
            "features"   => ["chatAttachments", "filePresentations"],
            "status"     => ($downloading || $complete) ? "preparing" : "stalled",
            "detail"     => $complete
                ? "Starting the attachment scanner."
                : ($downloading
                    ? "Downloading virus definitions."
                    : "The virus definition download did not finish."),
            "filesReady" => $filesReady,
            "filesTotal" => 3,
            "bytes"      => $bytes,
        ];
    }

    /** Bytes at a path, following one level into a directory. */
    private function pathSize(string $path): int
    {
        if (@is_file($path)) {
            return (int)@filesize($path);
        }
        if (!@is_dir($path)) {
            return 0;
        }
        $total = 0;
        foreach (@scandir($path) ?: [] as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }
            $total += (int)@filesize("$path/$entry");
        }
        return $total;
    }

    private function programExists(string $program): bool
    {
        foreach (["/usr/bin", "/usr/sbin", "/bin", "/sbin", "/usr/local/bin"] as $dir) {
            if (@is_executable("$dir/$program")) {
                return true;
            }
        }
        return false;
    }

    /** Whether clamd has a listening socket, which is what clamdscan needs. */
    private function clamdIsListening(): bool
    {
        foreach (["/run/clamav/clamd.ctl", "/var/run/clamav/clamd.ctl"] as $socket) {
            if (@file_exists($socket)) {
                return true;
            }
        }
        return false;
    }

    private function setupGroups(): array
    {
        $groups = [];
        foreach (self::SETUP_GROUPS as $group) {
            $groups[] = ["title" => $group[0], "steps" => count($group[1])];
        }
        return $groups;
    }

    /** Decode a JSON state file, or null when it is absent or unreadable. */
    private function readJsonFile(string $path)
    {
        $raw = @file_get_contents($path);
        if ($raw === false || trim((string)$raw) === "") {
            return null;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Whether a pid is still running, without signalling it. */
    private function processIsAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        return @file_exists("/proc/" . $pid);
    }
}
