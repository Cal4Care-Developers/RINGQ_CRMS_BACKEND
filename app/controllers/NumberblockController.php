<?php
declare(strict_types=1);

use Phalcon\Mvc\View;
use Phalcon\Db\Column;

require_once __DIR__ . "/../library/NumberBlock.php";
require_once __DIR__ . "/../library/CustomRoles.php";

/**
 * Numberblock — Global Settings > Security > Number blocking.
 *
 * GET  numberblock/list   — the company's blocked numbers.
 * POST numberblock/save   — add or edit one entry. JSON {id?, number, type, direction, description, enabled}.
 * POST numberblock/delete — remove entries. JSON {ids: []}.
 * POST numberblock/check  — would this entry be accepted? JSON {number, type, id?}. Saves nothing.
 *
 * Admins only (Owner, Manager, or a Custom role with Security). Every save is
 * checked here, not only in the web form: an entry that matches an extension,
 * IVR, queue, ring group, conference, system number or a DID on the server is
 * refused with a message naming what it matches. See library/NumberBlock.php.
 */
class NumberblockController extends ControllerBase
{
    private const PLAIN_USER_MODE = 10;
    private const MAX_ENTRIES = 5000;

    private function json($payload, int $status = 200)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        if ($status !== 200) {
            $this->response->setStatusCode($status);
        }
        return $this->response->setContent(json_encode($payload));
    }

    /**
     * Same lookup as ControllerBase::permissionModeOf, kept here so this file
     * works on a server whose (encoded) ControllerBase predates that helper.
     */
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
                "bind"       => ["domain_uuid" => (string) ($session["domain_uuid"] ?? "")],
                "bindTypes"  => [Column::BIND_PARAM_STR],
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
            error_log("numberblock: permission lookup failed: " . $e->getMessage());
        }
        return self::PLAIN_USER_MODE;
    }

    /** The signed-in admin's domain_uuid, or a response to send back. */
    private function admin()
    {
        // CORS preflight carries no session: answer it, so the browser sends the real request
        if ($this->request->isOptions()) {
            return $this->json(["result" => "success"]);
        }
        $session = $this->session->get("auth");
        if (!$session || empty($session["domain_uuid"])) {
            return $this->json(["result" => "error", "message" => "Unauthorized."], 401);
        }
        $mode = $this->modeOf($session);
        $allowed = $mode !== self::PLAIN_USER_MODE;
        if ($allowed && CustomRoles::isCustomMode($mode)) {
            $roles = CustomRoles::load($this->db, $this->redis, (string) $session["domain_uuid"]);
            $allowed = in_array("gs.security", $roles[CustomRoles::roleForMode($mode)]["permissions"] ?? [], true);
        }
        if (!$allowed) {
            return $this->json(["result" => "error", "message" => "Only an admin can manage blocked numbers. (Code NB4031)"], 403);
        }
        try {
            NumberBlock::ensureTable($this->db);
        } catch (\Throwable $e) {
            error_log("numberblock: table: " . $e->getMessage());
            return $this->json(["result" => "error", "message" => "Sorry, the blocked numbers list is not available on this server. (Code NB5001)"]);
        }
        return [(string) $session["domain_uuid"], (string) ($session["user_uuid"] ?? "")];
    }

    private function body(): array
    {
        $body = json_decode((string) $this->request->getRawBody(), true);
        return is_array($body) ? $body : [];
    }

    private function entries(string $domainUuid): array
    {
        $rows = $this->db->fetchAll(
            "SELECT number_block_uuid, block_number, block_type, block_direction, description, enabled::text AS enabled,
                    block_count, last_blocked_at, insert_date
               FROM q_number_blocks WHERE domain_uuid = :d ORDER BY insert_date DESC, block_number",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ["d" => $domainUuid]
        );
        $system = NumberBlock::systemNumbers($this->db, $domainUuid);
        $out = [];
        foreach ($rows as $r) {
            // a number added to the system after it was blocked is shown, so the admin can remove it
            $conflict = NumberBlock::findConflict($system, (string) $r["block_number"], (string) $r["block_type"]);
            $out[] = [
                "id"            => trim((string) $r["number_block_uuid"]),
                "number"        => (string) $r["block_number"],
                "type"          => (string) $r["block_type"],
                "direction"     => (string) $r["block_direction"],
                "description"   => (string) $r["description"],
                "enabled"       => in_array(strtolower((string) $r["enabled"]), ["true", "t", "1"], true),
                "count"         => (int) $r["block_count"],
                "lastBlockedAt" => $r["last_blocked_at"] === null ? null : (string) $r["last_blocked_at"],
                "conflict"      => $conflict === null ? null : $conflict[1],
            ];
        }
        return $out;
    }

    /**
     * Validate one entry for $domainUuid. Returns [clean number, error or null].
     * $id is the entry being edited (skipped in the duplicate check).
     */
    private function validate(string $domainUuid, string $number, string $type, string $id = ""): array
    {
        if (!in_array($type, NumberBlock::TYPES, true)) {
            return ["", "Choose Exact number or Starts with. (Code NB4002)"];
        }
        [$clean, $error] = NumberBlock::validateShape($number, $type);
        if ($error !== null) {
            return ["", $error . " (Code NB4003)"];
        }
        $conflict = NumberBlock::findConflict(NumberBlock::systemNumbers($this->db, $domainUuid), $clean, $type);
        if ($conflict !== null) {
            return ["", NumberBlock::conflictMessage($clean, $type, $conflict) . " (Code NB4090)"];
        }
        $existing = $this->db->fetchAll(
            "SELECT number_block_uuid, block_number FROM q_number_blocks WHERE domain_uuid = :d AND block_type = :t",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ["d" => $domainUuid, "t" => $type]
        );
        foreach ($existing as $row) {
            if (trim((string) $row["number_block_uuid"]) === $id) {
                continue;
            }
            $same = $type === "prefix"
                ? NumberBlock::digits((string) $row["block_number"]) === NumberBlock::digits($clean)
                : NumberBlock::canonical((string) $row["block_number"]) === NumberBlock::canonical($clean);
            if ($same) {
                return ["", $clean . " is already on the blocked list (" . $row["block_number"] . "). (Code NB4091)"];
            }
        }
        return [$clean, null];
    }

    /** GET numberblock/list */
    public function listAction()
    {
        $admin = $this->admin();
        if (!is_array($admin)) {
            return $admin;
        }
        try {
            return $this->json(["result" => "success", "entries" => $this->entries($admin[0])]);
        } catch (\Throwable $e) {
            error_log("numberblock/list: " . $e->getMessage());
            return $this->json(["result" => "error", "message" => "Sorry, the blocked numbers could not be loaded. (Code NB5002)"]);
        }
    }

    /** POST numberblock/check */
    public function checkAction()
    {
        $admin = $this->admin();
        if (!is_array($admin)) {
            return $admin;
        }
        $b = $this->body();
        try {
            [$clean, $error] = $this->validate($admin[0], (string) ($b["number"] ?? ""), (string) ($b["type"] ?? "number"), (string) ($b["id"] ?? ""));
        } catch (\Throwable $e) {
            error_log("numberblock/check: " . $e->getMessage());
            return $this->json(["result" => "error", "message" => "Sorry, the number could not be checked. (Code NB5003)"]);
        }
        if ($error !== null) {
            return $this->json(["result" => "error", "message" => $error]);
        }
        return $this->json(["result" => "success", "number" => $clean]);
    }

    /** POST numberblock/save */
    public function saveAction()
    {
        $admin = $this->admin();
        if (!is_array($admin)) {
            return $admin;
        }
        if (!$this->request->isPost()) {
            return $this->json(["result" => "error", "message" => "Sorry, this request must be a POST. (Code NB4050)"]);
        }
        [$domainUuid, $userUuid] = $admin;
        $b = $this->body();
        $id = trim((string) ($b["id"] ?? ""));
        $type = (string) ($b["type"] ?? "number");
        $direction = (string) ($b["direction"] ?? "inbound");
        if (!in_array($direction, NumberBlock::DIRECTIONS, true)) {
            return $this->json(["result" => "error", "message" => "Choose Inbound, Outbound or Both. (Code NB4004)"]);
        }
        $description = trim(strip_tags((string) ($b["description"] ?? "")));
        if (mb_strlen($description) > NumberBlock::DESCRIPTION_MAX) {
            return $this->json(["result" => "error", "message" => "The description is too long (at most " . NumberBlock::DESCRIPTION_MAX . " characters). (Code NB4005)"]);
        }
        $enabled = !array_key_exists("enabled", $b) || filter_var($b["enabled"], FILTER_VALIDATE_BOOLEAN);

        try {
            if ($id !== "") {
                $found = $this->db->fetchOne(
                    "SELECT number_block_uuid FROM q_number_blocks WHERE number_block_uuid = :id AND domain_uuid = :d",
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    ["id" => $id, "d" => $domainUuid]
                );
                if (!$found) {
                    return $this->json(["result" => "error", "message" => "That blocked number no longer exists. (Code NB4040)"]);
                }
            } else {
                $count = $this->db->fetchOne("SELECT count(*) AS n FROM q_number_blocks WHERE domain_uuid = :d", \Phalcon\Db\Enum::FETCH_ASSOC, ["d" => $domainUuid]);
                if ((int) ($count["n"] ?? 0) >= self::MAX_ENTRIES) {
                    return $this->json(["result" => "error", "message" => "The blocked list is full (" . self::MAX_ENTRIES . " entries). (Code NB4006)"]);
                }
            }
            [$clean, $error] = $this->validate($domainUuid, (string) ($b["number"] ?? ""), $type, $id);
            if ($error !== null) {
                return $this->json(["result" => "error", "message" => $error]);
            }
            $bind = [
                "d" => $domainUuid, "n" => $clean, "t" => $type, "dir" => $direction,
                "desc" => $description, "en" => $enabled ? "true" : "false",
            ];
            if ($id !== "") {
                $bind["id"] = $id;
                $this->db->execute(
                    "UPDATE q_number_blocks SET block_number = :n, block_type = :t, block_direction = :dir, description = :desc,
                            enabled = CAST(:en AS boolean), update_date = now()
                      WHERE number_block_uuid = :id AND domain_uuid = :d",
                    $bind
                );
            } else {
                $bind["id"] = (new \Phalcon\Security\Random())->uuid();
                $bind["u"] = $userUuid !== "" ? $userUuid : null;
                $this->db->execute(
                    "INSERT INTO q_number_blocks (number_block_uuid, domain_uuid, block_number, block_type, block_direction, description, enabled, insert_user, insert_date)
                     VALUES (:id, :d, :n, :t, :dir, :desc, CAST(:en AS boolean), :u, now())",
                    $bind
                );
            }
            return $this->json([
                "result"  => "success",
                "message" => $id !== "" ? "Blocked number updated." : $clean . " is now blocked.",
                "entries" => $this->entries($domainUuid),
            ]);
        } catch (\Throwable $e) {
            error_log("numberblock/save: " . $e->getMessage());
            return $this->json(["result" => "error", "message" => "Sorry, the blocked number could not be saved. (Code NB5004)"]);
        }
    }

    /** POST numberblock/delete */
    public function deleteAction()
    {
        $admin = $this->admin();
        if (!is_array($admin)) {
            return $admin;
        }
        if (!$this->request->isPost()) {
            return $this->json(["result" => "error", "message" => "Sorry, this request must be a POST. (Code NB4050)"]);
        }
        $ids = $this->body()["ids"] ?? [];
        if (!is_array($ids) || !$ids) {
            return $this->json(["result" => "error", "message" => "Choose the numbers to remove. (Code NB4007)"]);
        }
        try {
            foreach (array_slice($ids, 0, self::MAX_ENTRIES) as $id) {
                $this->db->execute(
                    "DELETE FROM q_number_blocks WHERE number_block_uuid = :id AND domain_uuid = :d",
                    ["id" => trim((string) $id), "d" => $admin[0]]
                );
            }
            return $this->json(["result" => "success", "message" => "Removed from the blocked list.", "entries" => $this->entries($admin[0])]);
        } catch (\Throwable $e) {
            error_log("numberblock/delete: " . $e->getMessage());
            return $this->json(["result" => "error", "message" => "Sorry, the numbers could not be removed. (Code NB5005)"]);
        }
    }
}
