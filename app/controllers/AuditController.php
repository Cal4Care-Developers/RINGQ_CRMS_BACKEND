<?php
declare(strict_types=1);

use Phalcon\Mvc\View;

// Self-sufficient: don't rely on ControllerBase having loaded AuditMap (an
// encoded/older base class won't have), same pattern as StorageController.
require_once __DIR__ . '/../library/AuditMap.php';

/**
 * Audit Logs — Advanced settings viewer.
 *
 * Read-only endpoints over the audit_logs table that
 * ControllerBase::afterExecuteRoute fills. Rows are always scoped to the
 * logged-in admin's domain_uuid. Convention-routed: audit/<action>.
 */
class AuditController extends ControllerBase
{
    private const MAX_LIMIT = 100;

    /** Selectable "keep last N logs" retention caps. */
    private const RETENTION_OPTIONS = [5000, 10000, 15000, 20000, 30000, 50000];
    private const RETENTION_DEFAULT = 10000;

    private function json($payload)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        return $this->response->setContent(json_encode($payload));
    }

    /**
     * GET audit/list — paginated, filterable log listing.
     * Query params: page, limit, feature, action, user, from, to, search.
     */
    public function listAction()
    {
        $session = $this->session->get("auth");
        if (!$session || empty($session["domain_uuid"])) {
            return $this->json(["result" => "error", "message" => "Unauthorized."]);
        }

        $page  = max(1, (int)$this->request->get("page", "int", 1));
        $limit = (int)$this->request->get("limit", "int", 50);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        list($where, $bind) = $this->buildFilters((string)$session["domain_uuid"]);

        try {
            $totalRow = $this->db->fetchOne(
                "SELECT COUNT(*) AS total FROM audit_logs WHERE " . $where,
                \Phalcon\Db\Enum::FETCH_ASSOC,
                $bind
            );
            $total = (int)($totalRow["total"] ?? 0);

            $offset = ($page - 1) * $limit;
            $rows = $this->db->fetchAll(
                "SELECT id, occurred_at, user_uuid, username, user_fullname, user_email,
                        extension, ip_address, user_agent, feature, action, entity,
                        result, metadata
                   FROM audit_logs
                  WHERE " . $where . "
                  ORDER BY occurred_at DESC, id DESC
                  LIMIT " . $limit . " OFFSET " . $offset,
                \Phalcon\Db\Enum::FETCH_ASSOC,
                $bind
            );
            foreach ($rows as &$row) {
                if (isset($row["metadata"]) && is_string($row["metadata"])) {
                    $decoded = json_decode($row["metadata"], true);
                    $row["metadata"] = is_array($decoded) ? $decoded : null;
                }
            }
            unset($row);

            return $this->json([
                "result" => "success",
                "total"  => $total,
                "page"   => $page,
                "pages"  => $limit > 0 ? (int)ceil($total / $limit) : 0,
                "limit"  => $limit,
                "data"   => $rows,
            ]);
        } catch (\Throwable $e) {
            // Most likely the migration has not reached this box yet.
            error_log("audit list: " . $e->getMessage());
            return $this->json([
                "result" => "success",
                "total"  => 0,
                "page"   => 1,
                "pages"  => 0,
                "limit"  => $limit,
                "data"   => [],
            ]);
        }
    }

    /**
     * GET audit/meetList — paginated RingQ Meet moderation audit trail.
     * Query params: page, limit, action, meeting.
     *
     * Lives on AuditController rather than MeetApiController on purpose. The
     * /meetApi/* routes are matched by nginx with EXACT locations
     * (ringq-meet-nginx-retrofit.py), so an endpoint there needs three things —
     * controller action, ACL entry, and its own nginx location — and missing
     * any one returns a 404 indistinguishable from a failed deployment.
     * /audit/* is not in that retrofit at all: it falls through to the default
     * location and reaches PHP normally, so this needs controller + ACL only.
     */
    public function meetListAction()
    {
        $session = $this->session->get("auth");
        if (!$session || empty($session["domain_uuid"])) {
            return $this->json(["result" => "error", "message" => "Unauthorized."]);
        }

        $page  = max(1, (int)$this->request->get("page", "int", 1));
        $limit = (int)$this->request->get("limit", "int", 50);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        // Scope on the SESSION's domain, never a request parameter: binding a
        // caller-supplied uuid here would make this a cross-tenant read.
        $where = "e.domain_uuid = :domain_uuid";
        $bind  = ["domain_uuid" => (string)$session["domain_uuid"]];

        $action = trim((string)$this->request->get("action", "string", ""));
        if ($action !== "") {
            $where .= " AND e.action = :action";
            $bind["action"] = $action;
        }
        $meeting = trim((string)$this->request->get("meeting", "string", ""));
        if ($meeting !== "") {
            $where .= " AND e.meeting_id = :meeting_id";
            $bind["meeting_id"] = $meeting;
        }

        try {
            $totalRow = $this->db->fetchOne(
                "SELECT COUNT(*) AS total FROM meet_audit_events e WHERE " . $where,
                \Phalcon\Db\Enum::FETCH_ASSOC,
                $bind
            );
            $total  = (int)($totalRow["total"] ?? 0);
            $offset = ($page - 1) * $limit;

            // actor_key / target_ref are opaque participant refs. They are
            // resolved with scalar subqueries rather than LEFT JOINs on
            // purpose: meet_participants has no unique constraint on
            // (meeting_id, participant_ref), so a join could multiply one audit
            // row into several. A subquery cannot.
            //
            // Not every actor is a participant — routes.js also writes the
            // literal "system" and the host's ownerKey — so an unresolved key
            // is normal and falls back to the raw value in the UI.
            $rows = $this->db->fetchAll(
                "SELECT e.id, e.created_at, e.action, e.actor_key, e.target_ref,
                        e.details, e.meeting_id,
                        m.title AS meeting_title,
                        m.slug  AS meeting_slug,
                        (SELECT p.display_name FROM meet_participants p
                          WHERE p.meeting_id = e.meeting_id
                            AND p.participant_ref = e.actor_key
                          LIMIT 1) AS actor_name,
                        (SELECT p.display_name FROM meet_participants p
                          WHERE p.meeting_id = e.meeting_id
                            AND p.participant_ref = e.target_ref
                          LIMIT 1) AS target_name
                   FROM meet_audit_events e
                   LEFT JOIN meet_meetings m ON m.id = e.meeting_id
                  WHERE " . $where . "
                  ORDER BY e.created_at DESC, e.id DESC
                  LIMIT " . $limit . " OFFSET " . $offset,
                \Phalcon\Db\Enum::FETCH_ASSOC,
                $bind
            );
            foreach ($rows as &$row) {
                if (isset($row["details"]) && is_string($row["details"])) {
                    $decoded = json_decode($row["details"], true);
                    $row["details"] = is_array($decoded) ? $decoded : null;
                }
            }
            unset($row);

            return $this->json([
                "result" => "success",
                "total"  => $total,
                "page"   => $page,
                "pages"  => $limit > 0 ? (int)ceil($total / $limit) : 0,
                "limit"  => $limit,
                "data"   => $rows,
            ]);
        } catch (\Throwable $e) {
            // Unlike audit_logs, an absent meet_audit_events is actionable, so
            // say what to run. Deliberately HTTP 200 with result=error: the
            // client's _decodeJson collapses any status >= 400 into a generic
            // "Server error (HTTP nnn)" and never reads the body, so a 503
            // would hide the one sentence worth showing.
            error_log("audit meetList: " . $e->getMessage());
            return $this->json([
                "result"  => "error",
                "message" => "Meet audit history is unavailable. Run: php cli.php meetSchema up",
            ]);
        }
    }

    /** GET audit/actions — distinct Meet audit actions for the filter dropdown. */
    public function meetActionsAction()
    {
        $session = $this->session->get("auth");
        if (!$session || empty($session["domain_uuid"])) {
            return $this->json(["result" => "error", "message" => "Unauthorized."]);
        }
        try {
            $rows = $this->db->fetchAll(
                "SELECT DISTINCT action FROM meet_audit_events
                  WHERE domain_uuid = :domain_uuid
                  ORDER BY action ASC",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ["domain_uuid" => (string)$session["domain_uuid"]]
            );
            return $this->json([
                "result" => "success",
                "data"   => array_values(array_map(static fn($r) => (string)$r["action"], $rows)),
            ]);
        } catch (\Throwable $e) {
            // A missing filter list must not break the page; the list is a
            // convenience and meetList already reports the real problem.
            error_log("audit meetActions: " . $e->getMessage());
            return $this->json(["result" => "success", "data" => []]);
        }
    }

    /** GET audit/features — feature keys for the filter dropdown. */
    public function featuresAction()
    {
        $session = $this->session->get("auth");
        if (!$session || empty($session["domain_uuid"])) {
            return $this->json(["result" => "error", "message" => "Unauthorized."]);
        }

        $features = AuditMap::features();
        try {
            $rows = $this->db->fetchAll(
                "SELECT DISTINCT feature FROM audit_logs WHERE domain_uuid = :domain_uuid",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ["domain_uuid" => (string)$session["domain_uuid"]]
            );
            foreach ($rows as $row) {
                $features[] = (string)$row["feature"];
            }
        } catch (\Throwable $e) {
            // table missing — the static map alone is fine
        }
        $features = array_values(array_unique($features));
        sort($features);

        return $this->json(["result" => "success", "data" => $features]);
    }

    /** GET audit/settings — current retention cap + selectable options. */
    public function settingsAction()
    {
        $session = $this->session->get("auth");
        if (!$session || empty($session["domain_uuid"])) {
            return $this->json(["result" => "error", "message" => "Unauthorized."]);
        }

        $maxLogs = self::RETENTION_DEFAULT;
        try {
            $row = $this->db->fetchOne(
                "SELECT max_logs FROM audit_settings WHERE domain_uuid = :d",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ["d" => (string)$session["domain_uuid"]]
            );
            if ($row && (int)$row["max_logs"] > 0) {
                $maxLogs = (int)$row["max_logs"];
            }
        } catch (\Throwable $e) {
            // table missing — report the default
        }

        return $this->json([
            "result"  => "success",
            "maxLogs" => $maxLogs,
            "options" => self::RETENTION_OPTIONS,
        ]);
    }

    /**
     * POST audit/saveSettings {max_logs} — persist the retention cap and trim
     * immediately so lowering the cap takes effect without waiting for the
     * next audit write.
     */
    public function saveSettingsAction()
    {
        $session = $this->session->get("auth");
        if (!$session || empty($session["domain_uuid"])) {
            return $this->json(["result" => "error", "message" => "Unauthorized."]);
        }

        $data = null;
        try {
            $data = $this->request->getJsonRawBody(true);
        } catch (\Throwable $e) {
            // fall through to form fields
        }
        $maxLogs = (int)($data["max_logs"] ?? $this->request->getPost("max_logs", "int", 0));
        if (!in_array($maxLogs, self::RETENTION_OPTIONS, true)) {
            return $this->json([
                "result"  => "error",
                "message" => "Invalid retention value.",
                "options" => self::RETENTION_OPTIONS,
            ]);
        }

        $domainUuid = (string)$session["domain_uuid"];
        try {
            $this->db->execute(
                "INSERT INTO audit_settings (domain_uuid, max_logs, update_date, update_user)
                 VALUES (:d, :m, now(), :u)
                 ON CONFLICT (domain_uuid) DO UPDATE
                    SET max_logs = EXCLUDED.max_logs,
                        update_date = now(),
                        update_user = EXCLUDED.update_user",
                [
                    "d" => $domainUuid,
                    "m" => $maxLogs,
                    "u" => $session["user_uuid"] ?? null,
                ]
            );
            $this->enforceAuditRetention($domainUuid);
        } catch (\Throwable $e) {
            error_log("audit saveSettings: " . $e->getMessage());
            return $this->json(["result" => "error", "message" => "Failed to save retention setting."]);
        }

        return $this->json([
            "result"  => "success",
            "message" => "Retention set to " . number_format($maxLogs) . " logs.",
            "maxLogs" => $maxLogs,
        ]);
    }

    /**
     * GET audit/download — export a log as a plain text file.
     *  - ?id=N  → that single entry, formatted as a labelled detail block.
     *  - else   → the filtered set (same params as listAction).
     */
    public function downloadAction()
    {
        $session = $this->session->get("auth");
        if (!$session || empty($session["domain_uuid"])) {
            return $this->json(["result" => "error", "message" => "Unauthorized."]);
        }

        $id = (int)$this->request->get("id", "int", 0);
        if ($id > 0) {
            return $this->downloadSingle($id, $session);
        }

        list($where, $bind) = $this->buildFilters((string)$session["domain_uuid"]);

        $rows = [];
        try {
            $rows = $this->db->fetchAll(
                "SELECT occurred_at, username, user_fullname, user_email, extension,
                        ip_address, feature, action, entity, result, metadata
                   FROM audit_logs
                  WHERE " . $where . "
                  ORDER BY occurred_at ASC, id ASC
                  LIMIT 50000",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                $bind
            );
        } catch (\Throwable $e) {
            error_log("audit download: " . $e->getMessage());
        }

        $filters = [];
        foreach (["feature", "action_filter", "user", "from", "to", "search"] as $key) {
            $value = trim((string)$this->request->get($key, "string", ""));
            if ($value !== "") {
                $filters[] = $key . "=" . $value;
            }
        }

        $exportedBy = trim(($session["firstname"] ?? "") . " " . ($session["lastname"] ?? ""));
        $exportedBy = $exportedBy !== "" ? $exportedBy : (string)($session["username"] ?? "unknown");

        $lines = [];
        $lines[] = "RingQ Audit Logs";
        $lines[] = "Exported: " . date("Y-m-d H:i:s") . " by " . $exportedBy
            . (!empty($session["email"]) ? " (" . $session["email"] . ")" : "");
        $lines[] = "Domain:   " . (string)($session["subdomain"] ?? $session["domain_uuid"]);
        $lines[] = "Filters:  " . ($filters ? implode("; ", $filters) : "none");
        $lines[] = "Entries:  " . count($rows);
        $lines[] = str_repeat("=", 100);
        $lines[] = "";

        foreach ($rows as $row) {
            $who = trim((string)($row["user_fullname"] ?? ""));
            if ($who === "") {
                $who = (string)($row["username"] ?? "unknown");
            }
            $whoDetail = [];
            if (!empty($row["user_email"])) {
                $whoDetail[] = $row["user_email"];
            }
            if (!empty($row["extension"])) {
                $whoDetail[] = "ext " . $row["extension"];
            }

            $line = "[" . substr((string)$row["occurred_at"], 0, 19) . "] "
                . $row["feature"] . "/" . $row["action"]
                . " by " . $who
                . ($whoDetail ? " (" . implode(", ", $whoDetail) . ")" : "")
                . (!empty($row["ip_address"]) ? " from " . $row["ip_address"] : "");
            if (!empty($row["entity"])) {
                $line .= " - target: " . $row["entity"];
            }
            $line .= " - result: " . $row["result"];
            $lines[] = $line;

            if (!empty($row["metadata"])) {
                $lines[] = "    details: " . $row["metadata"];
            }
        }
        $lines[] = "";
        $lines[] = "-- end of export --";

        return $this->serveText("audit-logs-" . date("Ymd-His") . ".txt", $lines);
    }

    /** Export one entry as a labelled detail block (the "download this log" action). */
    private function downloadSingle(int $id, $session)
    {
        $row = null;
        try {
            $row = $this->db->fetchOne(
                "SELECT id, occurred_at, username, user_fullname, user_email, extension,
                        ip_address, user_agent, feature, action, entity, result, metadata
                   FROM audit_logs
                  WHERE id = :id AND domain_uuid = :domain_uuid",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ["id" => $id, "domain_uuid" => (string)$session["domain_uuid"]]
            );
        } catch (\Throwable $e) {
            error_log("audit download single: " . $e->getMessage());
        }
        if (!$row) {
            return $this->json(["result" => "error", "message" => "Log entry not found."]);
        }

        $who = trim((string)($row["user_fullname"] ?? ""));
        if ($who === "") {
            $who = (string)($row["username"] ?? "unknown");
        }

        $exportedBy = trim(($session["firstname"] ?? "") . " " . ($session["lastname"] ?? ""));
        $exportedBy = $exportedBy !== "" ? $exportedBy : (string)($session["username"] ?? "unknown");

        $lines = [];
        $lines[] = "RingQ Audit Log Entry #" . $row["id"];
        $lines[] = "Exported: " . date("Y-m-d H:i:s") . " by " . $exportedBy
            . (!empty($session["email"]) ? " (" . $session["email"] . ")" : "");
        $lines[] = str_repeat("=", 60);
        $lines[] = "";

        $field = function ($label, $value) use (&$lines) {
            $lines[] = str_pad($label . ":", 12) . (($value === null || $value === "") ? "-" : $value);
        };
        $field("Time", substr((string)$row["occurred_at"], 0, 19));
        $field("User", $who);
        $field("Username", $row["username"]);
        $field("Email", $row["user_email"]);
        $field("Extension", $row["extension"]);
        $field("Feature", $row["feature"]);
        $field("Action", $row["action"]);
        $field("Target", $row["entity"]);
        $field("Result", $row["result"]);
        $field("IP address", $row["ip_address"]);
        $field("User agent", $row["user_agent"]);

        if (!empty($row["metadata"])) {
            $lines[] = "";
            $lines[] = "Details:";
            $decoded = json_decode((string)$row["metadata"], true);
            $pretty = is_array($decoded)
                ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : (string)$row["metadata"];
            foreach (explode("\n", (string)$pretty) as $l) {
                $lines[] = "  " . $l;
            }
        }
        $lines[] = "";

        return $this->serveText("audit-log-" . $row["id"] . ".txt", $lines);
    }

    /** Send an array of lines as a downloadable text file. */
    private function serveText(string $filename, array $lines)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "text/plain; charset=utf-8");
        $this->response->setHeader("Content-Disposition", "attachment; filename=\"" . $filename . "\"");
        return $this->response->setContent(implode("\n", $lines) . "\n");
    }

    /** Shared WHERE builder for listAction / downloadAction (same params). */
    private function buildFilters(string $domainUuid): array
    {
        $conditions = ["domain_uuid = :domain_uuid"];
        $bind = ["domain_uuid" => $domainUuid];

        $feature = trim((string)$this->request->get("feature", "string", ""));
        if ($feature !== "") {
            $conditions[] = "feature = :feature";
            $bind["feature"] = $feature;
        }
        $action = trim((string)$this->request->get("action_filter", "string", ""));
        if ($action !== "") {
            $conditions[] = "action = :action_filter";
            $bind["action_filter"] = $action;
        }
        $user = trim((string)$this->request->get("user", "string", ""));
        if ($user !== "") {
            $conditions[] = "(username ILIKE :user_like OR user_fullname ILIKE :user_like OR user_email ILIKE :user_like)";
            $bind["user_like"] = "%" . $user . "%";
        }
        $from = trim((string)$this->request->get("from", "string", ""));
        if ($from !== "" && $this->isDate($from)) {
            $conditions[] = "occurred_at >= CAST(:from_date AS date)";
            $bind["from_date"] = $from;
        }
        $to = trim((string)$this->request->get("to", "string", ""));
        if ($to !== "" && $this->isDate($to)) {
            $conditions[] = "occurred_at < CAST(:to_date AS date) + interval '1 day'";
            $bind["to_date"] = $to;
        }
        $search = trim((string)$this->request->get("search", "string", ""));
        if ($search !== "") {
            $conditions[] = "(feature ILIKE :search OR action ILIKE :search OR entity ILIKE :search"
                . " OR username ILIKE :search OR user_fullname ILIKE :search OR user_email ILIKE :search"
                . " OR ip_address ILIKE :search OR metadata::text ILIKE :search)";
            $bind["search"] = "%" . $search . "%";
        }

        return [implode(" AND ", $conditions), $bind];
    }

    private function isDate(string $value): bool
    {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }
}
