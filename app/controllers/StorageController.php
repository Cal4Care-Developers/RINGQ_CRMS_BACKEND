<?php
declare(strict_types=1);

use Phalcon\Mvc\View;

require_once __DIR__ . '/../library/Storage.php';

/**
 * Storage Management — Advanced settings.
 *
 * Phase 1 (usage/cleanup): monitoring + manual cleanup.
 * Phase 2 (policy/savePolicy/simulate): per-tenant retention policies that the
 * daily cron (MainTask::storageRetentionAction) enforces automatically.
 *
 * All filesystem logic lives in Library\Storage so the controller and the cron
 * share one whitelist + path resolver + purge primitive. The UI never sends a
 * raw path — every request names a category KEY resolved through the whitelist,
 * so there is no path-traversal surface and only `deletable` categories act.
 *
 * Convention-routed: storage/<action> maps here automatically.
 */
class StorageController extends ControllerBase
{
    /** How long a usage snapshot is cached in Redis (keeps the modal snappy). */
    private const USAGE_CACHE_TTL = 60;

    /** Default retention age (days) offered per category until an admin saves one. */
    private function defaultDays(): array
    {
        return [
            "recordings" => 180,
            "voicemail"  => 180,
            "meet"        => 180,
            "meet_collaboration" => 30,
            "captures"    => 7,
            "chat_images" => 90,
            "chat_files"  => 90,
            "chat_media"  => 90,
            "logs"        => 30,
            "backups"     => 90,
            "cdr"         => 365,
        ];
    }

    private function lib(): \Library\Storage
    {
        return new \Library\Storage();
    }

    private function json($payload)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        return $this->response->setContent(json_encode($payload));
    }

    /** The logged-in admin's tenant uuid, or null if unauthenticated. */
    private function authDomainUuid(): ?string
    {
        $session = $this->session->get("auth");
        if (!$session || empty($session["domain_uuid"])) {
            return null;
        }
        return (string)$session["domain_uuid"];
    }

    /** Resolve domain_name for a uuid via raw SQL (avoids the ioncube model). */
    private function domainNameFor(string $domainUuid): ?string
    {
        try {
            $row = $this->db->fetchOne(
                "SELECT domain_name FROM v_domains WHERE domain_uuid = :uuid LIMIT 1",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ["uuid" => $domainUuid]
            );
            return $row ? (string)$row["domain_name"] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ---------------------------------------------------------------
    // Phase 1 — monitoring + manual cleanup
    // ---------------------------------------------------------------

    /** GET storage/usage — overall disk + per-category breakdown (?refresh=1 bypasses cache). */
    public function usageAction()
    {
        $domainUuid = $this->authDomainUuid();
        if (!$domainUuid) {
            return $this->json(["result" => "error", "message" => "Unauthorized."]);
        }
        @set_time_limit(0);
        $lib = $this->lib();

        $refresh = (string)$this->request->get("refresh", "string", "") === "1";
        $cacheKey = "storage:usage";
        if (!$refresh) {
            $cached = $this->redis->get($cacheKey);
            if ($cached) {
                $payload = json_decode($cached, true);
                if (is_array($payload)) {
                    $payload["cached"] = true;
                    return $this->json($payload);
                }
            }
        }

        $categories = [];
        foreach ($lib->categories() as $key => $meta) {
            $categories[] = $this->measureCategory($lib, $key, $meta, $domainUuid);
        }

        $remote = new \Library\RemoteStorage();
        $payload = [
            "result"        => "success",
            "disk"          => $lib->diskUsage(),
            "diskAlert"     => $this->diskAlert($domainUuid),
            "archiveTarget" => ["available" => $remote->available(), "type" => $remote->label()],
            "categories"    => $categories,
            "generatedAt"   => date("c"),
            "cached"        => false,
        ];
        $this->redis->setex($cacheKey, self::USAGE_CACHE_TTL, json_encode($payload));
        return $this->json($payload);
    }

    /** Surface the existing q_email_notifications disk-alert config (read-only). */
    private function diskAlert(string $domainUuid): array
    {
        $default = ["enabled" => false, "thresholdPct" => 80, "emails" => null];
        try {
            $row = $this->db->fetchOne(
                "SELECT disk_space, disk_space_percentage, emails
                   FROM q_email_notifications WHERE domain_uuid = :uuid LIMIT 1",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ["uuid" => $domainUuid]
            );
            if (!$row) {
                return $default;
            }
            return [
                "enabled"      => ((int)$row["disk_space"] === 1),
                "thresholdPct" => $row["disk_space_percentage"] !== null
                    ? (int)round((float)$row["disk_space_percentage"])
                    : 80,
                "emails"       => $row["emails"] ?? null,
            ];
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * POST storage/saveNotify — configure the disk-space email alert from the
     * Storage page (so admins don't have to hunt for Account → Notifications).
     * Writes ONLY the disk fields of q_email_notifications, preserving any
     * existing CPU/RAM/voicemail alert settings on the same row. The existing
     * MainTask::systemAction cron is what actually sends the alert.
     * Params: enabled (1|0), thresholdPct (1-100), emails (comma-separated).
     */
    public function saveNotifyAction()
    {
        $session = $this->session->get("auth");
        if (!$session || empty($session["domain_uuid"])) {
            return $this->json(["result" => "error", "message" => "Unauthorized."]);
        }
        $domainUuid = (string)$session["domain_uuid"];

        $enabled   = (string)$this->request->getPost("enabled", "string", "0") === "1" ? 1 : 0;
        $threshold = (int)$this->request->getPost("thresholdPct", "int", 80);
        if ($threshold < 1)   { $threshold = 1; }
        if ($threshold > 100) { $threshold = 100; }
        $emails = trim((string)$this->request->getPost("emails", "string", ""));

        // Raw SQL (not the ioncube QEmailNotifications model) — consistent with
        // the rest of this controller and avoids any model-side validation/hooks.
        // q_email_notifications has no unique key on domain_uuid, so SELECT then
        // INSERT/UPDATE. Only the disk fields + emails are written, preserving any
        // existing CPU/RAM/voicemail alert settings on the same row.
        $userUuid = $session["user_uuid"] ?? null;
        try {
            $existing = $this->db->fetchOne(
                "SELECT id FROM q_email_notifications WHERE domain_uuid = :uuid LIMIT 1",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ["uuid" => $domainUuid]
            );
            if ($existing) {
                $this->db->execute(
                    "UPDATE q_email_notifications
                        SET disk_space = :enabled, disk_space_percentage = :pct,
                            emails = :emails, update_date = now(), update_user = :usr
                      WHERE domain_uuid = :uuid",
                    ["enabled" => $enabled, "pct" => $threshold, "emails" => $emails,
                     "usr" => $userUuid, "uuid" => $domainUuid]
                );
            } else {
                // Seed the other alert types with defaults so NOT NULL columns are satisfied.
                $this->db->execute(
                    "INSERT INTO q_email_notifications
                        (domain_uuid, disk_space, disk_space_percentage, emails,
                         send_system_owner, voicemail_space, cpu, ram,
                         voicemail_space_percentage, cpu_percentage, ram_percentage, concurrent_limit,
                         insert_date, insert_user, update_date)
                     VALUES (:uuid, :enabled, :pct, :emails, 0, 0, 0, 0, 80, 80, 80, 0, now(), :usr, now())",
                    ["uuid" => $domainUuid, "enabled" => $enabled, "pct" => $threshold,
                     "emails" => $emails, "usr" => $userUuid]
                );
            }
        } catch (\Throwable $e) {
            return $this->json(["result" => "error", "message" => "Could not save: " . ControllerBase::safeErrorMessage($e)]);
        }

        $this->redis->del("storage:usage"); // banner reflects new state on next load
        return $this->json(["result" => "success"]);
    }

    private function measureCategory(\Library\Storage $lib, string $key, array $meta, string $domainUuid): array
    {
        $base = [
            "key"       => $key,
            "label"     => $meta["label"],
            "type"      => $meta["type"],
            "scope"     => $meta["scope"] ?? "global",
            "deletable" => (bool)$meta["deletable"],
            "note"      => $meta["note"] ?? "",
            "path"      => $meta["path"],
            "exists"    => true,
            "bytes"     => null,
            "files"     => null,
            "oldest"    => null,
        ];

        if ($meta["type"] === "db") {
            $base["bytes"] = $lib->cdrBytes();
            $base["path"]  = null;
            return array_merge($base, $lib->cdrStats($meta, $domainUuid));
        }
        if ($meta["type"] === "meet_collaboration") {
            $base["path"] = null;
            return array_merge($base, $lib->collaborationStats($domainUuid));
        }
        if ($meta["type"] === "remote") {
            $base["exists"] = false;
            return $base;
        }

        if (!empty($meta["sources"])) {
            $sources = $lib->sourcesFor($key, null);
            if (empty($sources)) {
                $base["exists"] = false;
                return $base;
            }
            $base["paths"] = array_column($sources, "path");
            return array_merge($base, $lib->measureSources($sources));
        }

        // Monitoring view = whole/global footprint (domain null), aggregated
        // across every directory the category maps to (chat subtypes span many).
        $paths = $lib->resolvePaths($key, null);
        if (empty($paths)) {
            $base["exists"] = false;
            return $base;
        }
        return array_merge($base, $lib->measurePaths($paths));
    }

    /**
     * POST storage/cleanup — manually delete data older than N days.
     * Tenant-scoped categories act on the caller's own /<domain_name> subdir so
     * a clean can never reach another tenant's data; global categories act on
     * the shared path. Params: category, olderThanDays, dryRun (1|0).
     */
    public function cleanupAction()
    {
        $domainUuid = $this->authDomainUuid();
        if (!$domainUuid) {
            return $this->json(["result" => "error", "message" => "Unauthorized."]);
        }
        @set_time_limit(0);
        $lib = $this->lib();

        $key     = (string)$this->request->getPost("category", "string", "");
        $days    = (int)$this->request->getPost("olderThanDays", "int", 0);
        $dry     = (string)$this->request->getPost("dryRun", "string", "1") !== "0";
        $archive = (string)$this->request->getPost("archive", "string", "0") === "1";
        $all     = (string)$this->request->getPost("all", "string", "0") === "1";

        $cats = $lib->categories();
        if (!isset($cats[$key])) {
            return $this->json(["result" => "error", "message" => "Unknown storage category."]);
        }
        $meta = $cats[$key];
        if (empty($meta["deletable"])) {
            return $this->json(["result" => "error", "message" => "This category is monitoring-only."]);
        }
        if ($days < 0 || $days > 3650) {
            return $this->json(["result" => "error", "message" => "Invalid retention age."]);
        }
        if (!$dry && ($meta["scope"] ?? "global") !== "tenant") {
            $refusal = $this->serverWideRefusal($this->session->get("auth"));
            if ($refusal !== null) {
                return $this->json(["result" => "error", "message" => $refusal]);
            }
        }

        $domainName =($meta["scope"] ?? "global") === "tenant" ? $this->domainNameFor($domainUuid) : null;
        $result = $lib->purge($key, $days, $dry, $domainName, $domainUuid, $archive, 0, $all);
        if (!$dry) {
            $this->redis->del("storage:usage");
        }

        return $this->json(array_merge([
            "result"        => "success",
            "category"      => $key,
            "label"         => $meta["label"],
            "olderThanDays" => $days,
            "all"           => $all,
            "dryRun"        => $dry,
        ], $result));
    }

    // ---------------------------------------------------------------
    // Phase 2 — retention policy
    // ---------------------------------------------------------------

    private function storedPolicies(string $domainUuid): array
    {
        $saved = [];
        try {
            $rows = $this->db->fetchAll(
                "SELECT category, scope, enabled, retention_days, archive, last_run_at, last_freed_bytes, last_deleted_files
                   FROM q_storage_retention WHERE domain_uuid = :uuid",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ["uuid" => $domainUuid]
            );
            foreach ($rows as $r) {
                $saved[$r["category"]] = $r;
            }
        } catch (\Throwable $e) {
            $saved = [];
        }
        return $saved;
    }

    /** GET storage/policy — per-category retention policy for the caller's tenant. */
    public function policyAction()
    {
        $domainUuid = $this->authDomainUuid();
        if (!$domainUuid) {
            return $this->json(["result" => "error", "message" => "Unauthorized."]);
        }
        $lib = $this->lib();
        $defaults = $this->defaultDays();

        // Tolerate the table not existing yet (fresh box, pre-migration).
        $saved = $this->storedPolicies($domainUuid);

        $policies = [];
        foreach ($lib->categories() as $key => $meta) {
            if (empty($meta["deletable"])) {
                continue; // only deletable categories can have a retention policy
            }
            $row = $saved[$key] ?? null;
            $policies[] = [
                "category"        => $key,
                "label"           => $meta["label"],
                "scope"           => $meta["scope"] ?? "global",
                "note"            => $meta["note"] ?? "",
                "enabled"         => $row ? ((int)$row["enabled"] === 1) : ($key === "meet_collaboration"),
                "retentionDays"   => $row ? (int)$row["retention_days"] : ($defaults[$key] ?? 90),
                "archive"         => $row ? ((int)($row["archive"] ?? 0) === 1) : false,
                "lastRunAt"       => $row["last_run_at"] ?? null,
                "lastFreedBytes"  => $row ? (int)($row["last_freed_bytes"] ?? 0) : null,
                "lastDeletedFiles"=> $row ? (int)($row["last_deleted_files"] ?? 0) : null,
            ];
        }

        return $this->json(["result" => "success", "policies" => $policies]);
    }

    /**
     * POST storage/savePolicy — upsert the caller tenant's retention policy.
     * Param `policies`: JSON array of {category, enabled, retentionDays}.
     */
    public function savePolicyAction()
    {
        $domainUuid = $this->authDomainUuid();
        if (!$domainUuid) {
            return $this->json(["result" => "error", "message" => "Unauthorized."]);
        }
        $lib = $this->lib();
        $cats = $lib->categories();

        $raw = (string)$this->request->getPost("policies", null, "");
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            return $this->json(["result" => "error", "message" => "Invalid policies payload."]);
        }

        $rows = [];
        foreach ($input as $item) {
            if (!is_array($item) || empty($item["category"])) {
                continue;
            }
            $key = (string)$item["category"];
            if (!isset($cats[$key]) || empty($cats[$key]["deletable"])) {
                continue; // ignore unknown / non-deletable categories
            }
            $days = (int)($item["retentionDays"] ?? 90);
            if ($days < 1) {
                $days = 1;
            }
            if ($days > 3650) {
                $days = 3650;
            }
            $rows[$key] = [
                "enabled" => !empty($item["enabled"]) ? 1 : 0,
                "days"    => $days,
                "scope"   => $cats[$key]["scope"] ?? "global",
                "archive" => !empty($item["archive"]) ? 1 : 0,
            ];
        }

        $changedGlobal = [];
        $stored = $this->storedPolicies($domainUuid);
        $defaults = $this->defaultDays();
        foreach ($rows as $key => $row) {
            if ($row["scope"] === "tenant") {
                continue;
            }
            $current = $stored[$key] ?? null;
            $was = [
                "enabled" => $current ? (int)$current["enabled"] : ($key === "meet_collaboration" ? 1 : 0),
                "days"    => $current ? (int)$current["retention_days"] : ($defaults[$key] ?? 90),
                "archive" => $current ? (int)($current["archive"] ?? 0) : 0,
            ];
            if ($was["enabled"] !== $row["enabled"] || $was["days"] !== $row["days"] || $was["archive"] !== $row["archive"]) {
                $changedGlobal[] = $cats[$key]["label"];
            }
        }
        if ($changedGlobal !== []) {
            $refusal = $this->serverWideRefusal($this->session->get("auth"));
            if ($refusal !== null) {
                return $this->json([
                    "result"  => "error",
                    "message" => $refusal . " Server-wide: " . implode(", ", $changedGlobal) . ".",
                ]);
            }
        }

        $saved = 0;
        foreach ($rows as $key => $row) {
            $enabled = $row["enabled"];
            $days = $row["days"];
            $scope = $row["scope"];
            $archive = $row["archive"];

            try {
                $this->db->execute(
                    "INSERT INTO q_storage_retention
                        (domain_uuid, category, scope, enabled, retention_days, archive, insert_date, update_date)
                     VALUES (:uuid, :category, :scope, :enabled, :days, :archive, now(), now())
                     ON CONFLICT (domain_uuid, category) DO UPDATE
                        SET enabled = EXCLUDED.enabled,
                            retention_days = EXCLUDED.retention_days,
                            scope = EXCLUDED.scope,
                            archive = EXCLUDED.archive,
                            update_date = now()",
                    [
                        "uuid"     => $domainUuid,
                        "category" => $key,
                        "scope"    => $scope,
                        "enabled"  => $enabled,
                        "days"     => $days,
                        "archive"  => $archive,
                    ]
                );
                $saved++;
            } catch (\Throwable $e) {
                return $this->json([
                    "result"  => "error",
                    "message" => "Could not save policy: " . ControllerBase::safeErrorMessage($e),
                ]);
            }
        }

        return $this->json(["result" => "success", "saved" => $saved]);
    }

    /**
     * GET storage/simulate — read-only preview of what the next retention run
     * WOULD remove for one category, scoped to the caller's tenant. Never deletes.
     * Params: category, olderThanDays.
     */
    public function simulateAction()
    {
        $domainUuid = $this->authDomainUuid();
        if (!$domainUuid) {
            return $this->json(["result" => "error", "message" => "Unauthorized."]);
        }
        @set_time_limit(0);
        $lib = $this->lib();

        $key  = (string)$this->request->get("category", "string", "");
        $days = (int)$this->request->get("olderThanDays", "int", 0);
        $all  = (string)$this->request->get("all", "string", "0") === "1";

        $cats = $lib->categories();
        if (!isset($cats[$key]) || empty($cats[$key]["deletable"])) {
            return $this->json(["result" => "error", "message" => "Category cannot be cleaned up."]);
        }
        if ($days < 0 || $days > 3650) {
            return $this->json(["result" => "error", "message" => "Invalid retention age."]);
        }

        $meta = $cats[$key];
        $domainName = ($meta["scope"] ?? "global") === "tenant" ? $this->domainNameFor($domainUuid) : null;
        $result = $lib->purge($key, $days, true, $domainName, $domainUuid, false, 0, $all); // dry run
        return $this->json(array_merge([
            "result"        => "success",
            "category"      => $key,
            "scope"         => $meta["scope"] ?? "global",
            "olderThanDays" => $days,
            "all"           => $all,
        ], $result));
    }
}
