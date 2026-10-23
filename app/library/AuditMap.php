<?php
declare(strict_types=1);

/**
 * AuditMap — the single place that defines which controller/action pairs
 * count as a "feature change" and get written to audit_logs by
 * ControllerBase::afterExecuteRoute.
 *
 * Two modes:
 *  - EXPLICIT: always audited, whatever the HTTP method (covers GET
 *    endpoints that mutate, e.g. ssl/renew).
 *  - WRITES: audited only for non-GET requests. Used for controllers whose
 *    actions serve reads on GET and saves on POST/PUT/DELETE (ApiController,
 *    AccountsController). NOTE: report/chat style actions use POST for plain
 *    queries in this app, so they must simply not be listed here — never
 *    wildcard a controller whose POSTs are not writes.
 *
 * Adding audit coverage for a new feature = adding one line here.
 */
class AuditMap
{
    /** controller => [action => verb] — audited regardless of HTTP method. */
    private const EXPLICIT = [
        "tunnel" => [
            "create"          => "create",
            "update"          => "update",
            "delete"          => "delete",
            "generateAuthKey" => "generate-key",
        ],
        "ssl" => [
            "renew" => "renew",
        ],
        "storage" => [
            "cleanup"    => "cleanup",
            "savePolicy" => "save-policy",
            "saveNotify" => "save-notify",
        ],
        "ports" => [
            "scan"       => "scan",
            "scanall"    => "scan-all",
            "scancancel" => "scan-cancel",
        ],
        // Emergency Support. Audited even though both actions are read-only:
        // knowing WHO opened the support screen, and when, is the whole point
        // of an internal break-glass surface. `terminal` executes nothing --
        // see AccessController::terminalAction -- but it is recorded anyway so
        // the intent to reach a shell is on the record.
        "access" => [
            "diagnostics" => "read-diagnostics",
            "terminal"    => "view-terminal-access",
        ],
        "reports" => [
            "startCapture"    => "start-capture",
            "stopCapture"     => "stop-capture",
            "captureDebug"    => "capture-debug",
            "runTerminal"     => "run-terminal",
            "scheduleReports" => "schedule-reports",
        ],
        "audit" => [
            "saveSettings" => "update-retention",
            "download"     => "export",
        ],
        "calldebug" => [
            "toggle" => "call-debug",
        ],
        // The OAuth callback is a GET that mutates -- it is what stores the
        // token -- which is exactly the case EXPLICIT exists for, the same as
        // ssl/renew. Listing it under WRITES would never audit the one action
        // that grants a third party access to somebody's calendar.
        "calendar" => [
            "callback" => "provider-connected",
        ],
        "company" => [
            "save" => "update",
        ],
        // Boutique Hotel: saving the settings and rotating the guest QR both
        // change what every printed code does, so both are always audited.
        "hotelpbx" => [
            "save"   => "update",
            "rotate" => "rotate-qr",
        ],
    ];

    /**
     * controller => [action => feature label] — audited only when the HTTP
     * method is not GET. "*" as the action list audits every non-GET request
     * on that controller (feature = controller, verb from the method).
     */
    private const WRITES = [
        "roles" => [
            "custom" => "custom-role",
        ],
        "api" => [
            "contact"         => "call-landing",
            "gateway"         => "gateway",
            "ivr"             => "ivr",
            "queues"          => "queue",
            "callcenter"      => "call-center",
            "did"             => "did",
            "destinations"    => "destination",
            "outbound"        => "outbound",
            "outboundrules"   => "outbound-rules",
            "followme"        => "follow-me",
            "timescheduling"  => "time-scheduling",
            "timescheduling2" => "time-scheduling",
            "scheduling"      => "scheduling",
            "scheduling2"     => "scheduling",
            "holidayexempt"   => "holiday-exempt",
            "conference"      => "conference",
            "fax"             => "fax",
            "recordings"      => "recordings",
            "voicemails"      => "voicemail",
            "voicemails2"     => "voicemail",
        ],
        "accounts" => "*",
        "register" => [
            "firewall" => "security-firewall",
            "system"   => "system-settings",
        ],
        // `events` and `status` are deliberately ABSENT. `events` is a POST
        // that only reads a calendar -- the exact "POST that is not a write"
        // this file warns about -- and auditing it would write a row every
        // time somebody pressed Sync to look at a preview.
        "calendar" => [
            "settings"   => "calendar-provider",
            "connect"    => "calendar-connect",
            "disconnect" => "calendar-disconnect",
            "push"       => "calendar-sync",
        ],
    ];

    /** Request-payload keys whose values are never stored (case-insensitive substring match, or exact "key"). */
    private const REDACT_SUBSTRINGS = [
        "password", "secret", "token", "auth_key", "authkey",
        "api_key", "apikey", "private", "credential", "pin", "passphrase",
    ];

    /**
     * Decide whether this dispatched request is auditable.
     * Returns ["feature" => ..., "verb" => ...] or null.
     */
    public static function match(string $controller, string $action, string $httpMethod): ?array
    {
        if (isset(self::EXPLICIT[$controller][$action])) {
            return [
                "feature" => $controller,
                "verb"    => self::EXPLICIT[$controller][$action],
            ];
        }

        $method = strtoupper($httpMethod);
        if ($method === "GET" || $method === "HEAD" || $method === "OPTIONS") {
            return null;
        }

        $writes = self::WRITES[$controller] ?? null;
        if ($writes === "*") {
            return [
                "feature" => $controller,
                "verb"    => self::verbForMethod($method, $action),
            ];
        }
        if (is_array($writes) && isset($writes[$action])) {
            return [
                "feature" => $writes[$action],
                "verb"    => self::verbForMethod($method, $action),
            ];
        }
        return null;
    }

    /** All feature labels the map can produce (for the viewer's filter dropdown). */
    public static function features(): array
    {
        $features = array_keys(self::EXPLICIT);
        foreach (self::WRITES as $controller => $actions) {
            if ($actions === "*") {
                $features[] = $controller;
                continue;
            }
            foreach ($actions as $label) {
                $features[] = $label;
            }
        }
        $features = array_values(array_unique($features));
        sort($features);
        return $features;
    }

    /** Strip secrets from a request payload before it is stored as metadata. */
    public static function redact($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $clean = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $clean[$key] = "[REDACTED]";
                continue;
            }
            $clean[$key] = is_array($item) ? self::redact($item) : $item;
        }
        return $clean;
    }

    public static function insert($db, array $row): void
    {
        $entity = $row["entity"] ?? null;
        $db->execute(
            "INSERT INTO audit_logs
                (domain_uuid, user_uuid, username, user_fullname, user_email,
                 extension, ip_address, user_agent, feature, action, entity,
                 result, metadata)
             VALUES
                (:domain_uuid, :user_uuid, :username, :user_fullname, :user_email,
                 :extension, :ip_address, :user_agent, :feature, :action, :entity,
                 :result, CAST(:metadata AS jsonb))",
            [
                "domain_uuid"   => $row["domain_uuid"] ?? null,
                "user_uuid"     => $row["user_uuid"] ?? null,
                "username"      => $row["username"] ?? "system",
                "user_fullname" => $row["user_fullname"] ?? null,
                "user_email"    => $row["user_email"] ?? null,
                "extension"     => isset($row["extension"]) && $row["extension"] !== null ? substr((string)$row["extension"], 0, 32) : null,
                "ip_address"    => substr((string)($row["ip_address"] ?? ""), 0, 45),
                "user_agent"    => $row["user_agent"] ?? null,
                "feature"       => substr((string)$row["feature"], 0, 64),
                "action"        => substr((string)$row["action"], 0, 64),
                "entity"        => $entity !== null ? substr((string)$entity, 0, 255) : null,
                "result"        => $row["result"] ?? "success",
                "metadata"      => $row["metadata"] ?? null,
            ]
        );

        self::enforceRetention($db, $row["domain_uuid"] ?? null);
    }

    public static function enforceRetention($db, $domainUuid): void
    {
        if (!$domainUuid) {
            return;
        }
        $max = 10000;
        try {
            $row = $db->fetchOne(
                "SELECT max_logs FROM audit_settings WHERE domain_uuid = :d",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ["d" => $domainUuid]
            );
            if ($row && (int)$row["max_logs"] > 0) {
                $max = (int)$row["max_logs"];
            }
        } catch (\Throwable $e) {
            // audit_settings may not exist yet — use the default
        }
        $db->execute(
            "DELETE FROM audit_logs
              WHERE domain_uuid = :d1
                AND id NOT IN (
                    SELECT id FROM audit_logs
                     WHERE domain_uuid = :d2
                     ORDER BY id DESC
                     LIMIT " . (int)$max . "
                )",
            ["d1" => $domainUuid, "d2" => $domainUuid]
        );
    }

    public static function encodeMetadata($payload): ?string
    {
        if (!is_array($payload) || count($payload) === 0) {
            return null;
        }
        $metadata = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($metadata !== false && strlen($metadata) > 8192) {
            $metadata = json_encode(["truncated" => true], JSON_UNESCAPED_SLASHES);
        }
        return $metadata === false ? null : $metadata;
    }

    public static function domainUuidFor($db, $domainName): ?string
    {
        $domainName = trim((string)$domainName);
        if ($domainName === "") {
            return null;
        }
        try {
            $rows = $db->fetchAll(
                "SELECT domain_uuid FROM v_domains WHERE domain_name = :n LIMIT 2",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ["n" => $domainName]
            );
        } catch (\Throwable $e) {
            error_log("AuditMap::domainUuidFor: " . $e->getMessage());
            return null;
        }
        if (!is_array($rows) || count($rows) !== 1) {
            error_log("AuditMap::domainUuidFor: " . (is_array($rows) ? count($rows) : 0) . " domains named " . $domainName);
            return null;
        }
        return (string)$rows[0]["domain_uuid"];
    }

    private static function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        if ($lower === "key" || $lower === "otp") {
            return true;
        }
        foreach (self::REDACT_SUBSTRINGS as $needle) {
            if (strpos($lower, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function verbForMethod(string $method, string $action): string
    {
        switch ($method) {
            case "DELETE":
                return "delete";
            case "PUT":
            case "PATCH":
                return "update";
            default:
                // POST saves: keep the action name so "accounts/security" reads
                // as feature=accounts, verb=security rather than a bare "save".
                return $action;
        }
    }
}
