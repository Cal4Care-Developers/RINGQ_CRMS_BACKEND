<?php
declare(strict_types=1);

/**
 * CustomRoles -- the Owner-defined "Custom 1" and "Custom 2" user roles.
 *
 * Settings > User Roles has two extra groups beside Owner and Manager. The
 * Owner picks, per group, which menus and which individual actions (add /
 * edit / delete a queue, an IVR, an extension, each Global Settings card...)
 * its members get. Membership rides on the existing q_company_modes
 * permission slots, so nothing new is needed to assign people:
 *
 *   Custom 1 = permissionMode 9  (q_company_modes.permission3, chameleon action 9)
 *   Custom 2 = permissionMode 12 (q_company_modes.permission6, chameleon action 12)
 *
 * The chosen permissions are kept in q_custom_roles (one row per tenant and
 * role) and cached in redis under "customroles-<domain_uuid>".
 *
 * ControllerBase::roleAllows() asks allows() for every request a Custom
 * member makes: anything a plain User may do stays allowed, admin endpoints
 * are allowed only when the matching permission is switched on.
 */
class CustomRoles
{
    const ROLES = [
        "custom1" => ["mode" => 9,  "slot" => "permission3", "label" => "Custom 1"],
        "custom2" => ["mode" => 12, "slot" => "permission6", "label" => "Custom 2"],
    ];

    /** Every permission the Owner can switch on (must match the web catalog in utils/customRoles.ts). */
    const KEYS = [
        // Menus
        "menu.dashboard", "menu.teamdirectory", "menu.extensions", "menu.hotel", "menu.connections",
        "menu.voicemails", "menu.contacts", "menu.wallboard", "menu.chat", "menu.efax", "menu.sms",
        "menu.calls", "menu.reports", "menu.callflow", "menu.globalsettings",
        "menu.meet", "menu.calendar",
        // Extensions
        "extension.view", "extension.add", "extension.edit", "extension.delete", "extension.enable",
        // Queues
        "queue.view", "queue.add", "queue.edit", "queue.delete",
        // IVR
        "ivr.view", "ivr.add", "ivr.edit", "ivr.delete",
        // Conferences
        "conference.view", "conference.add", "conference.edit", "conference.delete",
        // Other call flow items
        "schedule.edit", "holiday.edit", "trunk.view", "trunk.edit", "contact.edit",
        // Reports
        "report.view",
        // Global Settings, one per card
        "gs.company", "gs.outbound_rules", "gs.emergency_rules", "gs.language_office_hours", "gs.music_on_hold",
        "gs.security", "gs.service_restart", "gs.updates", "gs.time_machine",
        "gs.aux_codes", "gs.wrap_up_codes", "gs.ai_transcribe", "gs.ai_agent", "gs.email_notification",
        "gs.chat_settings", "gs.network", "gs.parameters", "gs.sip_profile",
        "gs.integrations", "gs.migration",
        "gs.port_scanner", "gs.call_debug", "gs.capture", "gs.audit_logs", "gs.call_flow_debug",
        "gs.call_landing", "gs.reports", "gs.ssl", "gs.storage", "gs.secure_tunnel",
    ];

    /**
     * What Custom 1 has before the Owner first saves it: the pages the old
     * permissionMode 9 menu showed, so anyone already in that slot keeps
     * roughly what they had. Custom 2 (mode 12) never had a menu.
     */
    const DEFAULTS = [
        "custom1" => [
            "menu.dashboard", "menu.teamdirectory", "menu.extensions", "menu.hotel", "menu.connections",
            "menu.voicemails", "menu.contacts", "menu.wallboard", "menu.chat", "menu.efax", "menu.meet",
            "extension.view", "extension.add", "extension.edit", "extension.delete", "extension.enable",
            "contact.edit",
        ],
        "custom2" => [],
    ];

    const TABLE_SQL = "CREATE TABLE IF NOT EXISTS public.q_custom_roles (
        domain_uuid CHAR(36) NOT NULL,
        role_key VARCHAR(16) NOT NULL,
        role_name VARCHAR(60) NOT NULL DEFAULT '',
        permissions TEXT NOT NULL DEFAULT '[]',
        update_user CHAR(36),
        update_date TIMESTAMP DEFAULT now(),
        PRIMARY KEY (domain_uuid, role_key)
    );";

    /** POST/PUT/DELETE "type" codes -> verb. Most RingQ endpoints use 1=add, 2=edit, 3=delete. */
    const CRUD_TYPES = ["1" => "add", "2" => "edit", "3" => "delete"];

    public static function roleForMode(int $mode): ?string
    {
        foreach (self::ROLES as $key => $role) {
            if ($role["mode"] === $mode) {
                return $key;
            }
        }
        return null;
    }

    public static function isCustomMode(int $mode): bool
    {
        return self::roleForMode($mode) !== null;
    }

    public static function ensureTable($db): void
    {
        $db->execute(self::TABLE_SQL);
    }

    private static function cacheKey(string $domainUuid): string
    {
        return "customroles-" . $domainUuid;
    }

    /** Only known keys, each once, in catalog order. */
    public static function clean($permissions): array
    {
        if (!is_array($permissions)) {
            return [];
        }
        $wanted = array_flip(array_map("strval", $permissions));
        return array_values(array_filter(self::KEYS, function ($key) use ($wanted) {
            return isset($wanted[$key]);
        }));
    }

    /**
     * Both roles for one tenant: ["custom1" => ["name" => ..., "permissions" => [...], "saved" => bool], ...]
     */
    public static function load($db, $redis, string $domainUuid): array
    {
        if ($domainUuid === "") {
            return self::defaults();
        }
        try {
            $cached = $redis->get(self::cacheKey($domainUuid));
            if (is_string($cached) && $cached !== "") {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (\Throwable $e) {
            error_log("customroles: cache read failed: " . $e->getMessage());
        }

        $roles = self::defaults();
        try {
            self::ensureTable($db);
            $rows = $db->fetchAll(
                "SELECT role_key, role_name, permissions FROM public.q_custom_roles WHERE domain_uuid = ?",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                [$domainUuid]
            );
            foreach ($rows as $row) {
                $key = (string) $row["role_key"];
                if (!isset($roles[$key])) {
                    continue;
                }
                $name = trim((string) $row["role_name"]);
                $roles[$key] = [
                    "name"        => $name !== "" ? $name : self::ROLES[$key]["label"],
                    "permissions" => self::clean(json_decode((string) $row["permissions"], true)),
                    "saved"       => true,
                ];
            }
        } catch (\Throwable $e) {
            error_log("customroles: load failed: " . $e->getMessage());
            return $roles;
        }

        try {
            $redis->set(self::cacheKey($domainUuid), json_encode($roles));
        } catch (\Throwable $e) {
            error_log("customroles: cache write failed: " . $e->getMessage());
        }
        return $roles;
    }

    public static function save($db, $redis, string $domainUuid, string $roleKey, string $name, array $permissions, string $userUuid): array
    {
        $permissions = self::clean($permissions);
        self::ensureTable($db);
        $db->execute(
            "INSERT INTO public.q_custom_roles (domain_uuid, role_key, role_name, permissions, update_user, update_date)
             VALUES (?, ?, ?, ?, ?, now())
             ON CONFLICT (domain_uuid, role_key) DO UPDATE
             SET role_name = EXCLUDED.role_name, permissions = EXCLUDED.permissions,
                 update_user = EXCLUDED.update_user, update_date = now()",
            [$domainUuid, $roleKey, $name, json_encode($permissions), $userUuid !== "" ? $userUuid : null]
        );
        try {
            $redis->del(self::cacheKey($domainUuid));
        } catch (\Throwable $e) {
            error_log("customroles: cache clear failed: " . $e->getMessage());
        }
        return ["name" => $name, "permissions" => $permissions, "saved" => true];
    }

    public static function defaults(): array
    {
        $roles = [];
        foreach (self::ROLES as $key => $role) {
            $roles[$key] = ["name" => $role["label"], "permissions" => self::DEFAULTS[$key], "saved" => false];
        }
        return $roles;
    }

    /** The CRUD verb of a request: GET = view, otherwise its "type" field. */
    private static function verb(string $method, string $type): string
    {
        if ($method === "GET") {
            return "view";
        }
        return self::CRUD_TYPES[$type] ?? "edit";
    }

    /**
     * The permission an admin endpoint needs, or null when no Custom
     * permission opens it (it stays Owner/Manager only).
     *
     * $controller/$action lower-case, $method upper-case, $type the request's
     * "type" field ("" when absent).
     */
    public static function requiredPermission(string $controller, string $action, string $method, string $type): ?string
    {
        $verb = self::verb($method, $type);

        if ($controller === "api") {
            switch ($action) {
                case "callcenter":
                    return "queue." . $verb;
                case "ivr":
                    return "ivr." . $verb;
                case "conference":
                    return "conference." . $verb;
                case "timescheduling":
                case "timescheduling2":
                    return $method === "GET" ? "menu.callflow" : "schedule.edit";
                case "holidayexempt":
                    return $method === "GET" ? "menu.callflow" : "holiday.edit";
                case "did":
                case "gateway":
                    return $method === "GET" ? "trunk.view" : "trunk.edit";
                case "outboundrules":
                    return "gs.outbound_rules";
                case "aiagentrepair":
                case "aiagenttest":
                case "aiagentlog":
                case "aiagentrestart":
                    return "gs.ai_agent";
                case "siprepair":
                case "servicerestart":
                    return "gs.sip_profile";
                case "dbownerrepair":
                    return "gs.time_machine";
                case "queueagentreload":
                    return "queue.edit";
                case "pushrepair":
                    return "gs.network";
                case "mailtest":
                    return "gs.email_notification";
                case "callmanager":
                    return "menu.wallboard";
            }
            return null;
        }

        if ($controller === "accounts") {
            switch ($action) {
                case "extension2":
                case "extension3":
                    return $method === "GET" ? "extension.view" : "extension.edit";
                case "gateway":
                case "did":
                    return $method === "GET" ? "trunk.view" : "trunk.edit";
                case "sipprofile":
                    return "gs.sip_profile";
                case "restriction":
                case "interface":
                    return "gs.security";
                case "integration":
                case "sso":
                    return "gs.integrations";
                case "autoprov":
                case "autosip":
                    return "extension.edit";
            }
            return null;
        }

        if ($controller === "reports") {
            if (in_array($action, ["startcapture", "stopcapture", "capturedebug", "downloadcapture", "showsinglecapture", "showsinglecapture2", "showsinglecapture3", "getinterfaces"], true)) {
                return "gs.capture";
            }
            if (in_array($action, ["schedulereports", "savecustomwebhookdetails", "getcustomwebhookdetails"], true)) {
                return "gs.reports";
            }
            if ($action === "runterminal") {
                return null;
            }
            return "report.view";
        }

        $byController = [
            "audit"     => "gs.audit_logs",
            "calldebug" => "gs.call_debug",
            "ssl"       => "gs.ssl",
            "storage"   => "gs.storage",
            "backup"    => "gs.time_machine",
            "ports"     => "gs.port_scanner",
            "tunnel"    => "gs.secure_tunnel",
            "chatadmin" => "gs.chat_settings",
            "sms"       => "gs.chat_settings",
            "telegram"  => "gs.chat_settings",
            "teams"     => "gs.integrations",
            "calendar"  => "gs.integrations",
            "index"     => "gs.integrations",
            "hotel"     => "menu.hotel",
            "fax"       => "menu.efax",
            "numberblock" => "gs.security",
        ];
        return $byController[$controller] ?? null;
    }

    /**
     * Whether a Custom member may call an endpoint that a plain User may not.
     * Extensions are special: plain Users may read them and edit their own
     * profile (handled before this is asked), the rest maps to extension.*.
     */
    public static function allowsAdminEndpoint(array $permissions, string $controller, string $action, string $method, string $type): bool
    {
        if ($controller === "accounts" && $action === "extension") {
            $need = $method === "GET" ? "extension.view"
                : ($type === "1" ? "extension.add"
                : ($type === "5" ? "extension.delete"
                : ($type === "6" ? "extension.enable" : "extension.edit")));
            return in_array($need, $permissions, true);
        }
        $need = self::requiredPermission($controller, $action, $method, $type);
        return $need !== null && in_array($need, $permissions, true);
    }
}
