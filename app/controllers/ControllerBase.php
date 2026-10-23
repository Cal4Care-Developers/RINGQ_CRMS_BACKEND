<?php
declare(strict_types=1);

use Phalcon\Db\Column;
use Phalcon\Mvc\Controller;
use Phalcon\Mvc\Dispatcher;
use ElephantIO\Client;

require_once __DIR__ . "/../library/AuditMap.php";
require_once __DIR__ . "/../library/CustomRoles.php";

class ControllerBase extends Controller
{
    /** Set by audit() so afterExecuteRoute doesn't double-log a request. */
    private $auditLogged = false;

    public function beforeExecuteRoute(Dispatcher $dispatcher)
    {
        $session["access"] = "nonregistered";
        $action = $dispatcher->getActionName();
        $controller = $dispatcher->getControllerName();
        if ($this->session->has("auth")) {
            $this->view->session = $session = $this->session->get("auth");
        } else if ($this->cookies->has("RINGQKIE")) {
            // Guarded: a DB/model failure in the remember-me lookup must fall
            // through to the nonregistered path, not bubble into the
            // dispatcher (which forwards in a loop and 404s every
            // cookie-holding request).
            try {
            $cookie = $this->cookies->get("RINGQENS")->getValue();
            $user_uuid = $this->cookies->get("RINGQKIE")->getValue();
            $vUser = VUsers::findFirst(
                [
                    "conditions" => "user_uuid = :user_uuid:",
                    "bind" =>
                        [
                            "user_uuid" => $user_uuid,
                        ],
                    "bindTypes" =>
                        [
                            Column::BIND_PARAM_STR,
                        ]
                ]
            );
            if ($vUser) {
                $ipAdress = $this->filter->sanitize($this->request->getClientAddress(), "string");
                $userAgent = $this->filter->sanitize($this->request->getUserAgent(), "string");
                $access_token = is_string($cookie) ? $cookie : "";
                if (self::isRandomRememberToken($access_token)) {
                    // [remember-me] the browser's version changes with every Chrome / Edge / Safari update;
                    // requiring the exact same user agent signed everyone out after a browser update.
                    // Same token + same browser and OS (version numbers ignored) is accepted, and the
                    // stored user agent is refreshed.
                    $qToken = QTokens::findFirst(
                        [
                            "conditions" => "user_uuid = :user_uuid: AND access_token = :access_token:",
                            "bind" =>
                                [
                                    "user_uuid" => $user_uuid,
                                    "access_token" => $access_token,
                                ],
                            "bindTypes" =>
                                [
                                    Column::BIND_PARAM_STR,
                                    Column::BIND_PARAM_STR,
                                ]
                        ]
                    );
                    if ($qToken && $qToken->user_agent !== $userAgent) {
                        if (self::sameBrowser((string) $qToken->user_agent, (string) $userAgent)) {
                            $qToken->user_agent = $userAgent;
                            $qToken->update();
                        } else {
                            $qToken = false;
                        }
                    }
                    if ($qToken) {
                        $vExtensionUser = VExtensionUsers::findFirst(
                            [
                                "conditions" => "user_uuid = :user_uuid: AND domain_uuid = :domain_uuid:",
                                "bind" =>
                                    [
                                        "user_uuid" => $user_uuid,
                                        "domain_uuid" => $vUser->domain_uuid
                                    ],
                                "bindTypes" =>
                                    [
                                        Column::BIND_PARAM_STR,
                                        Column::BIND_PARAM_STR,
                                    ]
                            ]
                        );
                        if ($vUser->user_enabled == false) {
                            $this->flash->error("Account banned");
                            $this->cookies->get("RINGQKIE")->delete();
                            $this->cookies->get("RINGQENS")->delete();
                            return;
                        }
                        $vUser->user_status = "Available";
                        $vUser->update();
                        $vUserLogs = new VUserLogs();
                        $security = new \Phalcon\Security\Random();
                        $vUserLogs->user_log_uuid = $security->uuid();
                        $vUserLogs->domain_uuid = $vUser->domain_uuid;
                        $vUserLogs->timestamp = date("Y-m-d H:i:sP");
                        $vUserLogs->user_uuid = $user_uuid;
                        $vUserLogs->username = $vUser->VExtensionUsers->VExtensions->effective_caller_id_name . " (" . $vUser->VExtensionUsers->VExtensions->effective_caller_id_number . ")";
                        $vUserLogs->type = "login";
                        $vUserLogs->result = "success";
                        $vUserLogs->remote_address = $ipAdress;
                        $vUserLogs->user_agent = $userAgent;
                        $vUserLogs->insert_date = date("Y-m-d H:i:sP");
                        $vUserLogs->insert_user = $user_uuid;
                        $vUserLogs->create();
                        $qCallBehavior = QCallBehaviors::findFirst(
                            [
                                "conditions" => "domain_uuid = :domain_uuid: AND extension = :extension:",
                                "bind" =>
                                    [
                                        "domain_uuid" => $vUser->domain_uuid,
                                        "extension" => $vExtensionUser->vExtensions->extension
                                    ],
                                "bindTypes" =>
                                    [
                                        Column::BIND_PARAM_STR,
                                        Column::BIND_PARAM_STR,
                                    ]
                            ]
                        );
                        $this->redis->set("wallstatus:" . $vUser->vDomains->domain_name . ":". $vExtensionUser->vExtensions->extension, 1);
                        $this->redis->expire("wallstatus:" . $vUser->vDomains->domain_name . ":". $vExtensionUser->vExtensions->extension, 86400);
                        $this->redis->set($vExtensionUser->vExtensions->extension . ":" . $vUser->vDomains->domain_name, $vUser->qUsers->image);
                        $walltoken = $this->security->hash($user_uuid);
                        $this->redis->sadd("wallboard:" . $vUser->vDomains->domain_name, $walltoken);
                        $this->view->session = $session = array(
                            "username" => $vUser->username,
                            "firstname" => $vUser->qUsers->first_name,
                            "lastname" => $vUser->qUsers->last_name,
                            "email" => $vUser->user_email,
                            "image" => $vUser->qUsers->image,
                            "extension" => $vExtensionUser->vExtensions->extension,
                            "user_uuid" => $user_uuid,
                            "domain_uuid" => $vUser->domain_uuid,
                            "subdomain" => $vUser->vDomains->domain_name,
                            "domain" => $this->config->application->publicUrl,
                            "walltoken" => $walltoken,
                            "mobile" => isset($qCallBehavior) ? $qCallBehavior->mobile_number : "",
                            "pushtoken" => $vUser->qUsers->push_token,
                            "access" => "registered",
                            "result" => "success"
                        );
                        $this->session->set("auth", $session);
                        return;
                    }
                } else {
                    $vUserLogs = new VUserLogs();
                    $security = new \Phalcon\Security\Random();
                    $vUserLogs->user_log_uuid = $security->uuid();
                    $vUserLogs->domain_uuid = $vUser->domain_uuid;
                    $vUserLogs->timestamp = date("Y-m-d H:i:sP");
                    $vUserLogs->user_uuid = $user_uuid;
                    $vUserLogs->username = $vUser->VExtensionUsers->VExtensions->effective_caller_id_name . " (" . $vUser->VExtensionUsers->VExtensions->effective_caller_id_number . ")";
                    $vUserLogs->type = "login";
                    $vUserLogs->result = "fail";
                    $vUserLogs->remote_address = $ipAdress;
                    $vUserLogs->user_agent = $userAgent;
                    $vUserLogs->insert_date = date("Y-m-d H:i:sP");
                    $vUserLogs->insert_user = $user_uuid;
                    $vUserLogs->create();
                    $this->cookies->get("RINGQKIE")->delete();
                    $this->cookies->get("RINGQENS")->delete();
                }
            }
            } catch (\Throwable $e) {
                error_log("cookie auth failed: " . $e->getMessage());
            }
            error_log("COOKIE FAILED");
            $this->cookies->get("RINGQKIE")->delete();
            $this->cookies->get("RINGQENS")->delete();
        }
        // [one-mobile] a mobile app whose extension was since signed in on another phone (by QR) is
        // signed out here: its session and remember-me cookie are dropped and it gets a 401 with
        // code MOBILE_REPLACED, so the app can say why it was signed out (an older app just sees a
        // 401 and signs out quietly).
        if (($session["access"] ?? "") === "registered" && $this->mobileReplaced($session)) {
            error_log("[one-mobile] signed out replaced mobile app: " . strval($session["extension"] ?? "") . "@" . strval($session["subdomain"] ?? ""));
            $this->session->remove("auth");
            if ($this->cookies->has("RINGQKIE")) $this->cookies->get("RINGQKIE")->delete();
            if ($this->cookies->has("RINGQENS")) $this->cookies->get("RINGQENS")->delete();
            $this->view->disable();
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setHeader("Content-Type", "application/json");
            $this->response->setContent(json_encode([
                "result" => "error",
                "code" => "MOBILE_REPLACED",
                "message" => "Your extension was signed in on another phone. (Code AM4011)",
            ]));
            $this->response->send();
            exit;
        }
        if (!$this->acl->isAllowed($session["access"], $controller, $action)) {
            $registered = ($session["access"] ?? "") === "registered";
            error_log("[acl] refused " . ($registered ? "registered" : "nonregistered") . " " . strval($controller) . "/" . strval($action));
            $this->view->disable();
            if ($this->request->isOptions()) {
                $this->response->setStatusCode(204, "No Content");
            } else if ($registered) {
                $this->response->setStatusCode(403, "Forbidden");
                $this->response->setHeader("Content-Type", "application/json");
                $this->response->setContent(json_encode([
                    "result" => "error",
                    "code" => "not_available",
                    "message" => "Sorry, this is not available. (Code AC4031)",
                ]));
            } else {
                $this->response->setStatusCode(401, "Unauthorized");
                $this->response->setHeader("Content-Type", "application/json");
                $this->response->setContent(json_encode([
                    "result" => "error",
                    "code" => "unauthorized",
                    "message" => "Unauthorized. (Code AI1001)",
                ]));
            }
            $this->response->send();
            exit;
        }
        if (($session["access"] ?? "") !== "registered" && in_array(strtolower((string) $controller), self::SESSION_REQUIRED, true)) {
            $this->view->disable();
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setHeader("Content-Type", "application/json");
            $this->response->setContent(json_encode([
                "result" => "error",
                "message" => "Unauthorized. (Code AI1001)",
            ]));
            $this->response->send();
            exit;
        }
        if (($session["access"] ?? "") === "registered" && $this->isCrossSiteRequest($controller, $action)) {
            $this->view->disable();
            $this->response->setStatusCode(403, "Forbidden");
            $this->response->setHeader("Content-Type", "application/json");
            $this->response->setContent(json_encode([
                "result" => "error",
                "message" => "Sorry, this request must come from the RingQ app. (Code AC4032)",
            ]));
            $this->response->send();
            exit;
        }
        if (($session["access"] ?? "") === "registered" && !$this->roleAllows($controller, $action, $session)) {
            $this->view->disable();
            $this->response->setStatusCode(403, "Forbidden");
            $this->response->setHeader("Content-Type", "application/json");
            $this->response->setContent(json_encode([
                "result" => "error",
                "message" => "Sorry, you do not have permission to do this. (Code AC4030)",
            ]));
            $this->response->send();
            exit;
        }
    }

    private const SESSION_REQUIRED = ["calls", "campaign"];
    private const PLAIN_USER_MODE = 10;
    private const OWNER_MODE = 7;

    private const ADMIN_ACTIONS = [
        "accounts" => ["chameleon", "restriction", "interface", "sipprofile", "gateway", "did", "autoprov", "autosip", "extension2", "extension3", "integration", "sso", "callbroadcast", "mcpupdate"],
        "api" => ["callcenter", "gateway", "did", "ivr", "outboundrules", "timescheduling", "timescheduling2", "holidayexempt", "conference", "callmanager", "fax", "siprepair", "dbownerrepair", "queueagentreload", "pushrepair", "servicerestart", "mailtest", "aiagentrepair", "aiagenttest", "aiagentlog", "aiagentrestart", "chatassign", "chatresolve", "permissionsrepair", "permissionsrollback"],
        "calls" => ["*"],
        "backup" => ["*"],
        "ssl" => ["*"],
        "ports" => ["*"],
        "storage" => ["*"],
        "audit" => ["*"],
        "calldebug" => ["*"],
        "tunnel" => ["create", "list", "view", "update", "delete", "generateauthkey"],
        "fax" => ["updatesettings"],
        "reports" => ["startcapture", "stopcapture", "capturedebug", "downloadcapture", "showsinglecapture", "showsinglecapture2", "showsinglecapture3", "getinterfaces", "runterminal", "overall", "callqueue", "callcost", "callrating", "wrapupnotes", "wrapupnotes2", "auxcodes2", "agentactivity", "agentsreport", "agentsreports", "schedulereports", "savecustomwebhookdetails", "getcustomwebhookdetails"],
        "index" => ["createkey", "revokekey", "rotatekey", "keystatus", "savecallsapi", "listcallapis"],
        "teams" => ["o365settings", "googlesettings", "enableteams", "customcrmcontactsettings", "savecustomcrmcontactsettings"],
        "calendar" => ["settings"],
        "hotel" => ["ivrsettings"],
        "sms" => ["tikosconfig", "telnyxconfig", "twilioconfig"],
        // Support access. `tool` is absent on purpose: it carries a bearer
        // grant instead of a session, so it never reaches the role check.
        "mcp" => ["settings", "grants", "activity", "keys", "approvals"],
        "chatadmin" => ["whatsappconfig", "facebookconfig", "webchatconfig", "lineconfig", "telegramconfig"],
        "telegram" => ["*"],
        "numberblock" => ["*"],
    ];

    protected function isCrossSiteRequest($controller, $action): bool
    {
        if ($this->request->isOptions()) {
            return false;
        }
        $site = strtolower((string) $this->request->getHeader("Sec-Fetch-Site"));
        if ($site !== "cross-site") {
            return false;
        }
        $controller = strtolower((string) $controller);
        $action = strtolower((string) $action);
        if (substr($action, -8) === "callback" || $controller === "payment") {
            return false;
        }
        if (in_array($controller, ["login", "register", "oauth"], true) || ($controller === "ringqtalk" && $action === "config")) {
            return false;
        }
        return true;
    }

    protected function roleAllows($controller, $action, $session): bool
    {
        $controller = strtolower((string) $controller);
        $action = strtolower((string) $action);
        $mode = $this->permissionModeOf($session);
        if ($controller === "accounts" && $action === "chameleon" && !$this->request->isGet()) {
            return $mode === self::OWNER_MODE;
        }
        if (CustomRoles::isCustomMode($mode)) {
            return $this->customRoleAllows($controller, $action, $session, $mode);
        }
        if ($mode !== self::PLAIN_USER_MODE) {
            return true;
        }
        if ($this->request->isOptions()) {
            return true;
        }
        if ($controller === "accounts" && $action === "credentail") {
            return $this->request->isGet();
        }
        if ($controller === "accounts" && $action === "ai") {
            // Reading is shared (the dashboard needs a flag from it); saving is an admin setting.
            return $this->request->isGet();
        }
        if ($controller === "accounts" && $action === "extension") {
            return $this->request->isGet() || $this->isOwnProfileUpdate($session);
        }
        $actions = self::ADMIN_ACTIONS[$controller] ?? [];
        return !(in_array("*", $actions, true) || in_array($action, $actions, true));
    }

    /**
     * Custom 1 / Custom 2 (Settings > User Roles): everything a plain User may
     * do, plus the admin endpoints the Owner switched on for that role.
     */
    private function customRoleAllows(string $controller, string $action, $session, int $mode): bool
    {
        if ($this->request->isOptions()) {
            return true;
        }
        $method = strtoupper((string) $this->request->getMethod());
        if ($controller === "accounts" && $action === "extension") {
            if ($method === "GET" || $this->isOwnProfileUpdate($session)) {
                return true;
            }
        } else {
            if ($controller === "accounts" && in_array($action, ["credentail", "ai"], true) && $method === "GET") {
                return true;
            }
            $actions = self::ADMIN_ACTIONS[$controller] ?? [];
            if (!(in_array("*", $actions, true) || in_array($action, $actions, true))) {
                return true;
            }
        }
        $roles = CustomRoles::load($this->db, $this->redis, (string) ($session["domain_uuid"] ?? ""));
        $permissions = $roles[CustomRoles::roleForMode($mode)]["permissions"] ?? [];
        return CustomRoles::allowsAdminEndpoint($permissions, $controller, $action, $method, $this->requestType());
    }

    /** The request's "type" field, from a form post or a JSON body ("" when absent). */
    private function requestType(): string
    {
        $type = $this->request->getPost("type");
        if ($type === null || $type === "") {
            $body = json_decode((string) $this->request->getRawBody(), true);
            $type = is_array($body) ? ($body["type"] ?? "") : "";
        }
        return is_scalar($type) ? trim((string) $type) : "";
    }

    private function isOwnProfileUpdate($session): bool
    {
        $body = json_decode((string) $this->request->getRawBody(), true);
        if (!is_array($body)) {
            $body = [
                "type" => $this->request->getPost("type"),
                "extension" => $this->request->getPost("extension"),
            ];
        }
        return (string) ($body["type"] ?? "") === "4"
            && (string) ($body["extension"] ?? "") !== ""
            && (string) $body["extension"] === (string) ($session["extension"] ?? "");
    }

    protected function serverWideRefusal($session): ?string
    {
        if (!empty($session["mcp_staff"])) {
            return null;
        }
        if ($this->permissionModeOf($session) !== self::OWNER_MODE) {
            return "Only the account owner can do this. (Code AC4033)";
        }
        if (self::isSharedServer((string) ($session["subdomain"] ?? ""))) {
            return "This server is shared by several accounts, so this affects all of them. Ask RingQ support to run it. (Code AC4034)";
        }
        return null;
    }

    public static function isSharedServer(string $host): bool
    {
        $host = trim($host);
        return $host === "" || count(explode(".", $host)) >= 4;
    }

    protected function isPlainUser($session): bool
    {
        return $this->permissionModeOf($session) === self::PLAIN_USER_MODE;
    }

    protected function forbiddenForUser()
    {
        $this->response->setStatusCode(403, "Forbidden");
        $this->response->setHeader("Content-Type", "application/json");
        $this->response->setContent(json_encode([
            "result" => "error",
            "message" => "Sorry, you can only access your own records. (Code AC4031)",
        ]));
        return $this->response;
    }

    protected function permissionModeOf($session): int
    {
        $extension = (string) ($session["extension"] ?? "");
        $subdomain = (string) ($session["subdomain"] ?? "");
        try {
            $mode = $this->redis->get($extension . "-" . $subdomain . "-permission");
            if ($mode !== null && $mode !== false && $mode !== "") {
                return intval($mode);
            }
        } catch (\Throwable $e) {
            error_log("permission mode lookup failed: " . $e->getMessage());
        }
        $companyMode = $this->companyModeOf($session);
        return $companyMode !== null ? $companyMode : self::PLAIN_USER_MODE;
    }

    protected function companyModeOf($session): ?int
    {
        $extension = trim((string) ($session["extension"] ?? ""));
        if ($extension === "") {
            return null;
        }
        try {
            $modes = QCompanyModes::findFirst([
                "conditions" => "domain_uuid = :domain_uuid:",
                "bind" => ["domain_uuid" => (string) ($session["domain_uuid"] ?? "")],
                "bindTypes" => [Column::BIND_PARAM_STR],
            ]);
            if ($modes) {
                for ($i = 1; $i <= 6; $i++) {
                    $list = array_map("trim", explode(",", (string) $modes->{"permission" . $i}));
                    if (in_array($extension, $list, true)) {
                        return $i + 6;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("company mode lookup failed: " . $e->getMessage());
        }
        return null;
    }

    public static function safeErrorMessage($e): string
    {
        if (!($e instanceof \Throwable)) {
            return "An internal error occurred.";
        }
        error_log(get_class($e) . ": " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
        $message = (string) $e->getMessage();
        if ($e instanceof \PDOException
            || stripos($message, "SQLSTATE") !== false
            || preg_match('#(/[A-Za-z0-9_.-]+){2,}|\.php\b|\bline \d+|\bSELECT\b|\bINSERT\b|\bUPDATE\b|\bDELETE\b#i', $message)) {
            return "An internal error occurred.";
        }
        return mb_substr($message, 0, 200);
    }

    /** [remember-me] same browser and OS, ignoring version numbers (they change on every update). */
    public static function sameBrowser(string $a, string $b): bool
    {
        $norm = function ($ua) {
            return strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/[0-9]+([._][0-9]+)*/', '#', $ua))));
        };
        return $a !== "" && $b !== "" && $norm($a) === $norm($b);
    }

    /**
     * [one-mobile] The mobile apps: iOS ("RingQ/<build> CFNetwork ..."), Android (okhttp) and the old
     * Flutter app (Dart). Browsers and the desktop app send a Mozilla user agent and are never
     * touched by the one-mobile-per-extension rule.
     */
    public static function isMobileAppUserAgent(string $userAgent): bool
    {
        if ($userAgent === "" || stripos($userAgent, "Mozilla/") !== false) {
            return false;
        }
        return (bool) preg_match('#^RingQ/|CFNetwork|okhttp|Dart/#i', $userAgent);
    }

    /** [one-mobile] this app's sign-in: set at QR sign-in, else its remember-me token cookie. */
    private function mobileSessionToken($session): string
    {
        $mine = strval($session["mobile_session"] ?? "");
        if ($mine === "" && $this->cookies->has("RINGQENS")) {
            $value = $this->cookies->get("RINGQENS")->getValue();
            $mine = is_string($value) ? $value : "";
        }
        return $mine;
    }

    /** [one-mobile] a later QR sign-in on another phone took this mobile app's extension. */
    private function mobileReplaced($session): bool
    {
        // The desktop app is Flutter: its HTTP requests carry Dart's default "Dart/x (dart:io)"
        // user agent, the same as the old Flutter mobile app, so the user agent alone cannot tell
        // them apart. A desktop session (DesktopController::loginAction) is never a phone.
        if (($session["client"] ?? "") === "desktop") {
            return false;
        }
        if (!self::isMobileAppUserAgent(strval($this->request->getUserAgent()))) {
            return false;
        }
        try {
            $owner = $this->redis->get("mobileOwner-" . strval($session["extension"] ?? "") . "@" . strval($session["subdomain"] ?? ""));
        } catch (\Throwable $e) {
            return false;
        }
        if (!is_string($owner) || $owner === "") {
            return false;
        }
        // No sign-in token of its own (no QR sign-in on this session, no remember-me cookie): not a
        // phone sign-in this rule can judge (e.g. a desktop session created before the "client"
        // marker existed). Leave it alone; claimMobileApp already removed the old phone's pushes
        // and remember-me token, so a replaced phone stops ringing either way.
        $mine = $this->mobileSessionToken($session);
        if ($mine === "") {
            return false;
        }
        return !hash_equals($owner, $mine);
    }

    /**
     * [one-mobile] An extension is signed in on one mobile app at a time and the latest QR sign-in
     * wins. Called on a successful QR sign-in from a mobile app ($accessToken is its new
     * remember-me token): every earlier phone loses its pushes now (its q_devices rows and Redis
     * push entries go; the new phone registers its own right after signing in), its remember-me
     * token, and on its next request its session (see mobileReplaced). An open app is told at once
     * over the socket. Web and desktop sign-ins are untouched.
     */
    protected function claimMobileApp(string $extension, array $domainNames, string $domainUuid, string $userUuid, string $accessToken): void
    {
        if (!self::isMobileAppUserAgent(strval($this->request->getUserAgent()))) {
            return;
        }
        $domainNames = array_values(array_unique(array_filter($domainNames, function ($d) { return strval($d) !== ""; })));
        try {
            foreach ($domainNames as $domainName) {
                $this->redis->set("mobileOwner-" . $extension . "@" . $domainName, $accessToken);
            }
            $oldTokens = QTokens::find([
                "conditions" => "user_uuid = :user_uuid: AND access_token <> :access_token:",
                "bind" => ["user_uuid" => $userUuid, "access_token" => $accessToken],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
            ]);
            foreach ($oldTokens as $oldToken) {
                if (self::isMobileAppUserAgent(strval($oldToken->user_agent))) {
                    $oldToken->delete();
                }
            }
            $oldDevices = QDevices::find([
                "conditions" => "domain_uuid = :domain_uuid: AND extension = :extension: AND device_type IN (1, 2)",
                "bind" => ["domain_uuid" => $domainUuid, "extension" => $extension],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
            ]);
            foreach ($oldDevices as $oldDevice) {
                $pushToken = trim(strval($oldDevice->push_token));
                if ($pushToken !== "") {
                    foreach ($domainNames as $domainName) {
                        $base = $extension . "@" . $domainName;
                        $this->redis->srem("iosDevicePushToken-" . $base, $pushToken);
                        $this->redis->srem("iosSucessPushToken-" . $base, $pushToken);
                        foreach ((array) $this->redis->smembers($base) as $member) {
                            $member = strval($member);
                            if ($member === $pushToken || strpos($member, $pushToken . ":") === 0) {
                                $this->redis->srem($base, $member);
                            }
                        }
                    }
                }
                $oldDevice->delete();
            }
            error_log("[one-mobile] " . $extension . "@" . implode(",", $domainNames) . " signed in on a new phone; earlier mobile apps signed out (" . count($oldDevices) . " device row(s))");
        } catch (\Throwable $e) {
            error_log("[one-mobile] claim failed: " . $e->getMessage());
        }
        try {
            $client = new Client(Client::engine(Client::CLIENT_4X, "ws://127.0.0.1:3000", ["persistent" => false]));
            $client->initialize();
            foreach ($domainNames as $domainName) {
                $client->emit("mobileReplaced", ["extension" => $extension, "subdomain" => $domainName]);
            }
            $client->close();
        } catch (\Throwable $e) {
            error_log("[one-mobile] notify failed: " . $e->getMessage());
        }
    }

    public static function isRandomRememberToken($token): bool
    {
        return is_string($token) && strlen($token) === 64 && ctype_xdigit($token);
    }

    public function initialize() {
        $session = $this->session->get("auth");
        $this->view->baseUrl = $baseUrl = $this->config->application["publicUrl"];
        if ($session) {
            $this->view->loggedIn = true;
            $this->view->session = $session;
        } else {
            $this->view->loggedIn = false;
            $this->view->session = null;
        }
    }

    /**
     * Central audit hook: after any action runs, write an audit_logs row when
     * AuditMap says the controller/action is a feature change. Must NEVER
     * break the request it observes — everything is wrapped in try/catch
     * (including "relation audit_logs does not exist" on servers that have
     * not received the migration yet).
     */
    public function afterExecuteRoute(Dispatcher $dispatcher)
    {
        if ($this->auditLogged) {
            return;
        }
        try {
            $match = AuditMap::match(
                $dispatcher->getControllerName(),
                $dispatcher->getActionName(),
                $this->request->getMethod()
            );
            if (!$match) {
                return;
            }
            $this->writeAuditRow(
                $match["feature"],
                $match["verb"],
                [],
                $dispatcher->getReturnedValue()
            );
        } catch (\Throwable $e) {
            error_log("audit hook: " . $e->getMessage());
        }
    }

    /**
     * Manual audit entry for actions that want richer detail than the
     * automatic hook (e.g. a resolved entity name). Suppresses the automatic
     * hook for the rest of the request.
     */
    protected function audit(string $feature, string $verb, array $extra = [])
    {
        try {
            $this->writeAuditRow($feature, $verb, $extra, null);
            $this->auditLogged = true;
        } catch (\Throwable $e) {
            error_log("audit: " . $e->getMessage());
        }
    }

    private function writeAuditRow(string $feature, string $verb, array $extra, $returnedValue)
    {
        $session = $this->session->get("auth");

        $fullname = null;
        if ($session) {
            $fullname = trim(($session["firstname"] ?? "") . " " . ($session["lastname"] ?? ""));
            $fullname = $fullname === "" ? null : $fullname;
        }
        if (isset($extra["actor"]) && is_array($extra["actor"])) {
            $session = $extra["actor"];
            $fullname = $extra["actor"]["user_fullname"] ?? null;
        }

        $payload = empty($extra["skipPayload"]) ? $this->auditPayload() : null;
        $entity  = $extra["entity"] ?? $this->auditEntity($payload);
        if (isset($extra["metadata"]) && is_array($extra["metadata"])) {
            $payload = array_merge(is_array($payload) ? $payload : [], AuditMap::redact($extra["metadata"]));
        }

        AuditMap::insert($this->db, [
            "domain_uuid"   => $session["domain_uuid"] ?? null,
            "user_uuid"     => $session["user_uuid"] ?? null,
            "username"      => $session["username"] ?? "system",
            "user_fullname" => $fullname,
            "user_email"    => $session["email"] ?? null,
            "extension"     => $session["extension"] ?? null,
            "ip_address"    => (string)$this->request->getClientAddress(),
            "user_agent"    => $this->request->getUserAgent(),
            "feature"       => $feature,
            "action"        => $verb,
            "entity"        => $entity,
            "result"        => $extra["result"] ?? $this->auditOutcome($returnedValue),
            "metadata"      => AuditMap::encodeMetadata($payload),
        ]);
    }

    protected function enforceAuditRetention($domainUuid)
    {
        AuditMap::enforceRetention($this->db, $domainUuid);
    }

    /** Redacted request payload: JSON body, form fields, or query string. */
    private function auditPayload(): ?array
    {
        $payload = null;
        try {
            $payload = $this->request->getJsonRawBody(true);
        } catch (\Throwable $e) {
            // malformed/non-JSON body — fall through
        }
        if (!is_array($payload) || count($payload) === 0) {
            $post = $this->request->getPost();
            if (is_array($post) && count($post) > 0) {
                $payload = $post;
            }
        }
        if (!is_array($payload) || count($payload) === 0) {
            $query = $this->request->getQuery();
            unset($query["_url"]);
            $payload = is_array($query) && count($query) > 0 ? $query : null;
        }
        return $payload === null ? null : AuditMap::redact($payload);
    }

    /** Best-effort target identifier pulled from common payload keys. */
    private function auditEntity(?array $payload)
    {
        if (!is_array($payload)) {
            return null;
        }
        foreach (["tunnel_name", "name", "category", "extension", "id", "uuid", "domain"] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && (string)$payload[$key] !== "") {
                return (string)$payload[$key];
            }
        }
        return null;
    }

    /**
     * success|error from the action's JSON response. Handles both idioms:
     * actions that fill the shared response service and actions that return
     * a fresh Response object.
     */
    private function auditOutcome($returnedValue): string
    {
        $content = null;
        if ($returnedValue instanceof \Phalcon\Http\ResponseInterface) {
            $content = $returnedValue->getContent();
        }
        if (!$content) {
            $content = $this->response->getContent();
        }
        if (!$content) {
            return "success";
        }
        $decoded = json_decode((string)$content, true);
        if (!is_array($decoded)) {
            return "success";
        }
        $outcome = $decoded["result"] ?? $decoded["status"] ?? null;
        if ($outcome === "success" || $outcome === true || $outcome === null) {
            return "success";
        }
        return "error";
    }

    // Outbound rule action for a registered gateway (extension 100000-199999): bridge to the
    // gateway's current contact, swapping the user part for the dialled number. Two regex calls
    // because "%1" followed by the digits of the number would be read as one large group number.
    protected function gatewayBridgeAction($numberAlias, $subdomain, $prepend = '', $strip = 0)
    {
        $contact = '${sofia_contact(*/'. $numberAlias .'@'. $subdomain .')}';
        // Same strip/prepend as a SIP trunk rule: drop $strip leading digits, then add $prepend.
        $number = $prepend . (intval($strip) > 0 ? '${dest_number:'. intval($strip) .'}' : '${dest_number}');
        return '<action application="bridge" data="{sip_invite_to_uri=sip:'. $number .'@'. $subdomain .',origination_caller_id_name=${outbound_caller_id_number},origination_caller_id_number=${outbound_caller_id_number}}${regex('. $contact .'|^(.*?sip:).*$|%1)}'. $number .'${regex('. $contact .'|^.*?sip:[^@]*(@.*)$|%1)}"/>';
    }

    // Inbound Setting of a registered gateway (extension 100000-199999), like a SIP trunk's:
    // which header gives the caller's number and name, and which one is matched against the DIDs.
    // Kept on the gateway's own dialplan row ("gateway-inbound", dialplan_number = extension),
    // the description holding the three choices as JSON.
    protected static $gatewayInboundChoices = ["From User", "From Display", "To User", "Invite"];

    protected function gatewayInboundSettings($domainUuid, $numberAlias)
    {
        $settings = ["from_user" => "From Display", "from_display" => "From Display", "did_match" => "Invite"];
        $vDialplan = VDialplans::findFirst([
            "conditions" => "domain_uuid = :domain_uuid: AND dialplan_name = :dialplan_name: AND dialplan_number = :dialplan_number:",
            "bind" => ["domain_uuid" => $domainUuid, "dialplan_name" => "gateway-inbound", "dialplan_number" => strval($numberAlias)],
        ]);
        if ($vDialplan) {
            $saved = json_decode((string) $vDialplan->dialplan_description, true);
            foreach (array_keys($settings) as $key) {
                if (is_array($saved) && in_array($saved[$key] ?? null, self::$gatewayInboundChoices, true)) {
                    $settings[$key] = $saved[$key];
                }
            }
        }
        return $settings;
    }

    // Writes the gateway's inbound dialplan: a call the gateway brings in to a number that is not an
    // extension takes the caller's number and name from the chosen headers and goes to the public
    // dialplan (DIDs) under the chosen number. Returns false when the row could not be saved.
    protected function saveGatewayInbound($session, $numberAlias, $fromUser, $fromDisplay, $didMatch)
    {
        $vars = ["From User" => '${sip_from_user}', "From Display" => '${sip_from_display}', "To User" => '${sip_to_user}', "Invite" => '${destination_number}'];
        $current = $this->gatewayInboundSettings($session["domain_uuid"], $numberAlias);
        $fromUser = in_array($fromUser, self::$gatewayInboundChoices, true) ? $fromUser : $current["from_user"];
        $fromDisplay = in_array($fromDisplay, self::$gatewayInboundChoices, true) ? $fromDisplay : $current["from_display"];
        $didMatch = in_array($didMatch, self::$gatewayInboundChoices, true) ? $didMatch : $current["did_match"];
        $numberAlias = strval(intval($numberAlias));
        $vDialplan = VDialplans::findFirst([
            "conditions" => "domain_uuid = :domain_uuid: AND dialplan_name = :dialplan_name: AND dialplan_number = :dialplan_number:",
            "bind" => ["domain_uuid" => $session["domain_uuid"], "dialplan_name" => "gateway-inbound", "dialplan_number" => $numberAlias],
        ]);
        if (!$vDialplan) {
            $security = new \Phalcon\Security\Random();
            $vDialplan = new VDialplans();
            $vDialplan->dialplan_uuid = $security->uuid();
            $vDialplan->app_uuid = $vDialplan->dialplan_uuid;
            $vDialplan->domain_uuid = $session["domain_uuid"];
            $vDialplan->dialplan_name = "gateway-inbound";
            $vDialplan->dialplan_number = $numberAlias;
            $vDialplan->insert_date = date("Y-m-d H:i:s");
        }
        // Order 34: before the global ringq-gateway-inbound (35), which covers gateways never saved.
        $vDialplan->dialplan_context = $session["subdomain"];
        $vDialplan->dialplan_continue = "false";
        $vDialplan->dialplan_order = 34;
        $vDialplan->dialplan_enabled = "true";
        $vDialplan->dialplan_description = json_encode(["from_user" => $fromUser, "from_display" => $fromDisplay, "did_match" => $didMatch]);
        $vDialplan->dialplan_xml = '<extension name="gateway-inbound-'. $numberAlias .'" continue="false" uuid="'. $vDialplan->dialplan_uuid .'">
    <condition field="${gateway_inbound}" expression="^$"/>
    <condition field="${user_exists}" expression="^false$"/>
    <condition field="${sip_from_user}" expression="^'. $numberAlias .'$">
        <action application="set" data="gateway_inbound='. $numberAlias .'" inline="true"/>
        <action application="export" data="call_direction=inbound" inline="true"/>
        <action application="set" data="gateway_caller_number='. $vars[$fromUser] .'" inline="true"/>
        <action application="set" data="gateway_caller_name='. $vars[$fromDisplay] .'" inline="true"/>
        <action application="set" data="gateway_did='. $vars[$didMatch] .'" inline="true"/>
        <action application="set_profile_var" data="caller_id_number=${gateway_caller_number}"/>
        <action application="set_profile_var" data="caller_id_name=${gateway_caller_name}"/>
        <action application="set" data="effective_caller_id_number=${gateway_caller_number}"/>
        <action application="set" data="effective_caller_id_name=${gateway_caller_name}"/>
        <action application="set" data="sip_from_user=${gateway_caller_number}"/>
        <action application="set" data="sip_from_display=${gateway_caller_name}"/>
        <action application="set" data="caller_id_number=${gateway_caller_number}"/>
        <action application="set" data="caller_id_name=${gateway_caller_name}"/>
        <action application="transfer" data="${gateway_did} XML public"/>
    </condition>
</extension>';
        $saved = $vDialplan->save();
        $this->clearDialplanCache($session["subdomain"]);
        return $saved;
    }

    protected function deleteGatewayInbound($session, $numberAlias)
    {
        $vDialplan = VDialplans::findFirst([
            "conditions" => "domain_uuid = :domain_uuid: AND dialplan_name = :dialplan_name: AND dialplan_number = :dialplan_number:",
            "bind" => ["domain_uuid" => $session["domain_uuid"], "dialplan_name" => "gateway-inbound", "dialplan_number" => strval($numberAlias)],
        ]);
        if ($vDialplan) {
            $vDialplan->delete();
            $this->clearDialplanCache($session["subdomain"]);
        }
    }

    private function clearDialplanCache($subdomain)
    {
        foreach (["/var/cache/fusionpbx/dialplan.", "/var/cache/ringq/dialplan."] as $prefix) {
            if (file_exists($prefix . $subdomain)) {
                unlink($prefix . $subdomain);
            }
        }
    }
}
