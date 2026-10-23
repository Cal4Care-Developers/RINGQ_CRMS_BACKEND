<?php
declare(strict_types=1);

use Phalcon\Mvc\View;
use Phalcon\Mvc\Dispatcher;
use Phalcon\Db\Column;

require_once __DIR__ . "/../library/HotelPbx.php";

/**
 * Boutique Hotel (Hotel PBX): the one PBX-wide guest QR code. See
 * library/HotelPbx.php for the model.
 *
 * Admin (Owner / Manager, signed in):
 *   GET  hotelpbx/settings  the QR token, on/off, rotation, hotel name
 *   POST hotelpbx/save      JSON {enabled, rotate: off|daily|weekly, hotelName}
 *   POST hotelpbx/rotate    a new token now; the old QR stops working
 *
 * Guests (no session):
 *   GET  hotelpbx/info?k=     what the sign-in form shows
 *   POST hotelpbx/join        {k, lastName, firstName, room, client: web|app}
 *   GET  hotelpbx/guest?g=    a browser guest's sign-in, still valid?
 *
 * This controller does its own checks instead of going through the ACL, so
 * it works on a server whose (encoded) services.php predates it. Every guest
 * refusal reads the same, so the form can't be used to learn which rooms are
 * occupied or by whom, and sign-in attempts are rate limited per address.
 */
class HotelpbxController extends ControllerBase
{
    private const OWNER_MODE = 7;
    private const MANAGER_MODE = 8;

    private const PUBLIC_ACTIONS = ["info", "join", "guest"];

    private const QR_REFUSAL = "This QR code is no longer valid. Please scan the hotel's current QR code.";
    private const JOIN_REFUSAL = "We couldn't find a checked-in guest with that name and room. Please check the details or contact the front desk.";

    /** Sign-in attempts per address per 10 minutes. */
    private const JOIN_LIMIT = 10;

    public function beforeExecuteRoute(Dispatcher $dispatcher)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $this->response->setHeader("Cache-Control", "no-store");
        if ($this->request->isOptions()) {
            $this->response->setStatusCode(204, "No Content");
            $this->response->send();
            exit;
        }
        if (in_array(strtolower($dispatcher->getActionName()), self::PUBLIC_ACTIONS, true)) {
            return true;
        }
        $session = $this->session->has("auth") ? $this->session->get("auth") : null;
        if (!$session || ($session["access"] ?? "") !== "registered" || empty($session["domain_uuid"])) {
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Unauthorized. (Code HP4010)"]));
            $this->response->send();
            exit;
        }
        if (strtolower((string) $this->request->getHeader("Sec-Fetch-Site")) === "cross-site") {
            $this->response->setStatusCode(403, "Forbidden");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Sorry, this request must come from the RingQ app. (Code HP4032)"]));
            $this->response->send();
            exit;
        }
        $mode = $this->modeOf($session);
        if ($mode !== self::OWNER_MODE && $mode !== self::MANAGER_MODE) {
            $this->response->setStatusCode(403, "Forbidden");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Sorry, you do not have permission to do this. (Code HP4030)"]));
            $this->response->send();
            exit;
        }
        return true;
    }

    private function json(array $payload)
    {
        return $this->response->setContent(json_encode($payload));
    }

    /** Same lookup as ControllerBase::permissionModeOf, kept here for servers whose encoded base predates it. */
    private function modeOf($session): int
    {
        $extension = (string) ($session["extension"] ?? "");
        $subdomain = (string) ($session["subdomain"] ?? "");
        try {
            $mode = $this->redis->get($extension . "-" . $subdomain . "-permission");
            if ($mode !== null && $mode !== false && $mode !== "") {
                return intval($mode);
            }
            $modes = QCompanyModes::findFirst([
                "conditions" => "domain_uuid = :domain_uuid:",
                "bind" => ["domain_uuid" => (string) ($session["domain_uuid"] ?? "")],
                "bindTypes" => [Column::BIND_PARAM_STR],
            ]);
            if ($modes) {
                for ($i = 1; $i <= 6; $i++) {
                    $list = array_map("trim", explode(",", (string) $modes->{"permission" . $i}));
                    if ($extension !== "" && in_array($extension, $list, true)) {
                        return $i + 6;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("hotelpbx: permission lookup failed: " . $e->getMessage());
        }
        return 10;
    }

    private function safely(string $code, callable $body)
    {
        try {
            return $body();
        } catch (\Throwable $e) {
            error_log("HotelpbxController error ({$code}): " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
            return $this->json(["result" => "error", "message" => "Something went wrong. Please try again. (Code {$code})"]);
        }
    }

    private function domain(string $domainUuid)
    {
        return VDomains::findFirst([
            "conditions" => "domain_uuid = :d:",
            "bind" => ["d" => $domainUuid],
            "bindTypes" => [Column::BIND_PARAM_STR],
        ]);
    }

    private function hotelName(array $settings, string $domainUuid): string
    {
        if ($settings["hotelName"] !== "") {
            return $settings["hotelName"];
        }
        $qCompany = QCompanies::findFirst([
            "conditions" => "domain_uuid = :d:",
            "bind" => ["d" => $domainUuid],
            "bindTypes" => [Column::BIND_PARAM_STR],
        ]);
        return $qCompany ? trim((string) $qCompany->company_name) : "";
    }

    // ---------------------------------------------------------------- admin

    public function settingsAction()
    {
        return $this->safely("HP1000", function () {
            $session = $this->session->get("auth");
            $settings = HotelPbx::settings($this->db, (string) $session["domain_uuid"]);
            return $this->json(["result" => "success", "content" => $settings + [
                "defaultHotelName" => $this->hotelName(["hotelName" => ""], (string) $session["domain_uuid"]),
            ]]);
        });
    }

    public function saveAction()
    {
        return $this->safely("HP1001", function () {
            if (!$this->request->isPost()) {
                return $this->json(["result" => "error", "message" => "Sorry, this request must be a POST. (Code HP4050)"]);
            }
            $session = $this->session->get("auth");
            $body = (array) $this->request->getJsonRawBody(true);
            $rotate = (string) ($body["rotate"] ?? "off");
            if (!isset(HotelPbx::ROTATIONS[$rotate])) {
                return $this->json(["result" => "error", "message" => "Please choose how often the QR code changes. (Code HP4001)"]);
            }
            $hotelName = trim(strip_tags((string) ($body["hotelName"] ?? "")));
            if (mb_strlen($hotelName) > 120) {
                return $this->json(["result" => "error", "message" => "The hotel name can be up to 120 characters. (Code HP4002)"]);
            }
            HotelPbx::save($this->db, (string) $session["domain_uuid"], !empty($body["enabled"]), $rotate, $hotelName);
            return $this->json(["result" => "success", "message" => "Boutique Hotel settings saved.", "content" => HotelPbx::settings($this->db, (string) $session["domain_uuid"])]);
        });
    }

    public function rotateAction()
    {
        return $this->safely("HP1002", function () {
            if (!$this->request->isPost()) {
                return $this->json(["result" => "error", "message" => "Sorry, this request must be a POST. (Code HP4050)"]);
            }
            $session = $this->session->get("auth");
            HotelPbx::settings($this->db, (string) $session["domain_uuid"]);
            HotelPbx::rotate($this->db, (string) $session["domain_uuid"]);
            return $this->json(["result" => "success", "message" => "New QR code created. The old one no longer works.", "content" => HotelPbx::settings($this->db, (string) $session["domain_uuid"])]);
        });
    }

    // --------------------------------------------------------------- guests

    public function infoAction()
    {
        return $this->safely("HP1010", function () {
            $domainUuid = HotelPbx::domainForToken($this->db, (string) $this->request->getQuery("k", "string", ""));
            if ($domainUuid === null) {
                return $this->json(["result" => "error", "code" => "qr_invalid", "message" => self::QR_REFUSAL]);
            }
            $settings = HotelPbx::settings($this->db, $domainUuid);
            return $this->json(["result" => "success", "content" => ["hotelName" => $this->hotelName($settings, $domainUuid)]]);
        });
    }

    public function joinAction()
    {
        return $this->safely("HP1011", function () {
            if (!$this->request->isPost()) {
                return $this->json(["result" => "error", "message" => self::JOIN_REFUSAL]);
            }
            $body = (array) $this->request->getJsonRawBody(true);
            if (!$body) {
                $body = $this->request->getPost();
            }
            $ip = (string) $this->request->getClientAddress();
            $limitKey = "hotelpbx-join:" . $ip;
            $attempts = intval($this->redis->incr($limitKey));
            if ($attempts === 1) {
                $this->redis->expire($limitKey, 600);
            }
            if ($attempts > self::JOIN_LIMIT) {
                return $this->json(["result" => "error", "code" => "too_many", "message" => "Too many attempts. Please wait a few minutes and try again, or contact the front desk."]);
            }

            $domainUuid = HotelPbx::domainForToken($this->db, (string) ($body["k"] ?? ""));
            if ($domainUuid === null) {
                return $this->json(["result" => "error", "code" => "qr_invalid", "message" => self::QR_REFUSAL]);
            }
            $lastName = trim((string) ($body["lastName"] ?? ""));
            $room = trim((string) ($body["room"] ?? ""));
            if ($lastName === "" || $room === "" || mb_strlen($lastName) > 255 || strlen($room) > 64) {
                return $this->json(["result" => "error", "message" => "Please enter your last name and room number."]);
            }

            $vExtension = $this->roomExtension($domainUuid, $room);
            $vDomain = $this->domain($domainUuid);
            if (!$vExtension || !$vDomain || (string) $vExtension->enabled === "false") {
                return $this->json(["result" => "error", "message" => self::JOIN_REFUSAL]);
            }
            $subdomain = (string) $vExtension->accountcode;
            if ((string) $this->redis->get($vExtension->extension . "-" . $subdomain . "-chameleon") !== HotelPbx::ROOM_MODE) {
                return $this->json(["result" => "error", "message" => self::JOIN_REFUSAL]);
            }
            $checkin = QHotelCheckins::findFirst([
                "conditions" => "domain_uuid = :d: AND extension_uuid = :e:",
                "bind" => ["d" => $domainUuid, "e" => (string) $vExtension->extension_uuid],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
            ]);
            if (!$checkin || !hash_equals(self::fold((string) $checkin->guest_lastname), self::fold($lastName))) {
                return $this->json(["result" => "error", "message" => self::JOIN_REFUSAL]);
            }
            $link = VExtensionUsers::findFirst([
                "conditions" => "extension_uuid = :e:",
                "bind" => ["e" => (string) $vExtension->extension_uuid],
                "bindTypes" => [Column::BIND_PARAM_STR],
            ]);
            $vUser = $link ? VUsers::findFirst([
                "conditions" => "user_uuid = :u:",
                "bind" => ["u" => (string) $link->user_uuid],
                "bindTypes" => [Column::BIND_PARAM_STR],
            ]) : null;
            if (!$vUser) {
                return $this->json(["result" => "error", "message" => "This room isn't set up for guest sign-in yet. Please contact the front desk. (Code HP4040)"]);
            }
            $this->redis->del($limitKey);
            $settings = HotelPbx::settings($this->db, $domainUuid);
            $guestName = trim((string) $checkin->guest_firstname . " " . (string) $checkin->guest_lastname);

            if (($body["client"] ?? "web") === "app") {
                return $this->joinApp($vUser, $vExtension, $vDomain, $domainUuid, $guestName, $settings);
            }

            $guestToken = bin2hex(random_bytes(24));
            $this->redis->hmset("hotelguest:" . $guestToken, [
                "domain_uuid" => $domainUuid,
                "extension_uuid" => (string) $vExtension->extension_uuid,
                "checkin_uuid" => (string) $checkin->hotel_checkin_uuid,
            ]);
            $this->redis->expire("hotelguest:" . $guestToken, HotelPbx::GUEST_TTL);
            $this->redis->sadd("hotelguest-room:" . $vExtension->extension_uuid, $guestToken);
            $this->redis->expire("hotelguest-room:" . $vExtension->extension_uuid, HotelPbx::GUEST_TTL);
            error_log("[hotel-pbx] browser guest signed in to room " . $vExtension->extension . "@" . $vDomain->domain_name);

            return $this->json(["result" => "success", "content" => [
                "guestToken" => $guestToken,
                "hotelName" => $this->hotelName($settings, $domainUuid),
                "guestName" => $guestName,
                "room" => $this->roomLabel($subdomain, $vExtension, $domainUuid),
                "sipUser" => (string) $vUser->username,
                "sipPassword" => (string) $vExtension->password,
                "sipDomain" => (string) $vDomain->domain_name,
                "hotelParameters" => HotelPbx::hotelParameters($this->redis, $domainUuid, $subdomain),
                "defaultsReady" => HotelPbx::defaultsReady($domainUuid),
            ]]);
        });
    }

    /** The RingQ app: an ordinary app session for the room, as register/extension gives a QR sign-in. */
    private function joinApp($vUser, $vExtension, $vDomain, string $domainUuid, string $guestName, array $settings)
    {
        if ($this->redis->exists("disableapp:" . $vExtension->extension . ":" . $vDomain->domain_name)) {
            return $this->json(["result" => "error", "message" => "Sorry, app sign-in is turned off for this room. Please contact the front desk. (Code HP4031)"]);
        }
        $userUuid = (string) $vUser->user_uuid;
        $accessToken = bin2hex(random_bytes(32));
        $qToken = new QTokens();
        $qToken->user_uuid = $userUuid;
        $qToken->domain_uuid = $domainUuid;
        $qToken->access_token = $accessToken;
        $qToken->insert_date = date("Y-m-d H:i:s");
        $qToken->user_agent = $this->filter->sanitize($this->request->getUserAgent(), "string");
        $qToken->type = 1;
        if (!$qToken->save()) {
            return $this->json(["result" => "error", "message" => "An error has occurred. (Code HP1012)"]);
        }
        $expire = time() + 86400 * 360;
        $this->cookies->set("RINGQKIE", $userUuid, $expire, "/", true, "." . $this->config->application->publicUrl, true, ["samesite" => "Lax"]);
        $this->cookies->set("RINGQENS", $accessToken, $expire, "/", true, "." . $this->config->application->publicUrl, true, ["samesite" => "Lax"]);

        $qUser = QUsers::findFirst([
            "conditions" => "user_uuid = :u:",
            "bind" => ["u" => $userUuid],
            "bindTypes" => [Column::BIND_PARAM_STR],
        ]);
        $vUser->user_status = "Available";
        $vUser->update();
        $walltoken = $this->security->hash($userUuid);
        $this->redis->sadd("wallboard:" . $vExtension->accountcode, $walltoken);
        $content = [
            "username" => $vUser->username,
            "email" => $vUser->user_email,
            "image" => $qUser ? $qUser->image : "",
            "extension" => $vExtension->extension,
            "subdomain" => $vExtension->accountcode,
            "domain" => $this->config->application->publicUrl,
            "domain_uuid" => $domainUuid,
            "sip_uuid" => $vExtension->password,
            "user_uuid" => $userUuid,
            "walltoken" => $walltoken,
            "access" => "registered",
        ];
        $this->session->set("auth", $content + ["mobile_session" => $accessToken]);
        if (method_exists($this, "claimMobileApp")) {
            $this->claimMobileApp((string) $vExtension->extension, [(string) $vDomain->domain_name, (string) $vExtension->accountcode], $domainUuid, $userUuid, $accessToken);
        }
        error_log("[hotel-pbx] app guest signed in to room " . $vExtension->extension . "@" . $vDomain->domain_name);
        return $this->json(["result" => "success", "message" => "Login success.", "content" => $content + [
            "hotelName" => $this->hotelName($settings, $domainUuid),
            "guestName" => $guestName,
        ]]);
    }

    public function guestAction()
    {
        return $this->safely("HP1013", function () {
            $token = (string) $this->request->getQuery("g", "string", "");
            $guest = preg_match('/^[0-9a-f]{48}$/', $token) ? (array) $this->redis->hgetall("hotelguest:" . $token) : [];
            $checkin = !empty($guest["checkin_uuid"]) ? QHotelCheckins::findFirst([
                "conditions" => "hotel_checkin_uuid = :c: AND extension_uuid = :e:",
                "bind" => ["c" => (string) $guest["checkin_uuid"], "e" => (string) $guest["extension_uuid"]],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
            ]) : null;
            if (!$checkin) {
                if ($token !== "") {
                    $this->redis->del("hotelguest:" . $token);
                }
                return $this->json(["result" => "error", "code" => "signed_out", "message" => "You've been checked out of this room. Thank you for staying with us."]);
            }
            $vExtension = VExtensions::findFirst([
                "conditions" => "extension_uuid = :e:",
                "bind" => ["e" => (string) $guest["extension_uuid"]],
                "bindTypes" => [Column::BIND_PARAM_STR],
            ]);
            if (!$vExtension) {
                return $this->json(["result" => "error", "code" => "signed_out", "message" => "This room is no longer available."]);
            }
            return $this->json(["result" => "success", "content" => [
                "room" => $this->roomLabel((string) $vExtension->accountcode, $vExtension, (string) $guest["domain_uuid"]),
                "guestName" => trim((string) $checkin->guest_firstname . " " . (string) $checkin->guest_lastname),
                "hotelParameters" => HotelPbx::hotelParameters($this->redis, (string) $guest["domain_uuid"], (string) $vExtension->accountcode),
                "defaultsReady" => HotelPbx::defaultsReady((string) $guest["domain_uuid"]),
            ]]);
        });
    }

    /**
     * The room label guests see - the extension number, or the room's SIP ID
     * (q_call_behaviors.force_ping) when the domain's Hotel PBX "Room number
     * shows" switch says sipuid (HotelController::roomNumberModeAction).
     * Falls back to the extension when no SIP ID is set, so a room never
     * shows blank.
     */
    private function roomLabel(string $subdomain, $vExtension, string $domainUuid): string
    {
        if ($this->redis->get("landing:" . $subdomain . "-hotel-room-number-mode") === "sipuid") {
            try {
                $behavior = QCallBehaviors::findFirst([
                    "conditions" => "domain_uuid = :d: AND extension_uuid = :e:",
                    "bind" => ["d" => $domainUuid, "e" => (string) $vExtension->extension_uuid],
                    "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                ]);
                if ($behavior && (string) $behavior->force_ping !== "") {
                    return (string) $behavior->force_ping;
                }
            } catch (\Throwable $e) {
                error_log("HotelpbxController roomLabel SIP ID lookup failed: " . $e->getMessage());
            }
        }
        return (string) $vExtension->extension;
    }

    /** A room by its extension number or its SIP ID (the Extensions page's "SIP ID", q_call_behaviors.force_ping). */
    private function roomExtension(string $domainUuid, string $room)
    {
        if (ctype_digit($room)) {
            $vExtension = VExtensions::findFirst([
                "conditions" => "domain_uuid = :d: AND extension = :x:",
                "bind" => ["d" => $domainUuid, "x" => $room],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
            ]);
            if ($vExtension) {
                return $vExtension;
            }
        }
        $behavior = QCallBehaviors::findFirst([
            "conditions" => "domain_uuid = :d: AND force_ping = :x:",
            "bind" => ["d" => $domainUuid, "x" => $room],
            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
        ]);
        if (!$behavior || empty($behavior->extension_uuid)) {
            return null;
        }
        return VExtensions::findFirst([
            "conditions" => "domain_uuid = :d: AND extension_uuid = :e:",
            "bind" => ["d" => $domainUuid, "e" => (string) $behavior->extension_uuid],
            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
        ]);
    }

    /** Names compare case- and accent-insensitively, ignoring extra spaces. */
    private static function fold(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name));
        if (function_exists("transliterator_transliterate")) {
            $t = transliterator_transliterate("Any-Latin; Latin-ASCII", $name);
            if (is_string($t)) {
                $name = $t;
            }
        }
        return mb_strtolower($name);
    }
}
