<?php

namespace Library;

use Phalcon\Di\Injectable;

require_once __DIR__ . '/RemoteStorage.php';

/**
 * Shared storage logic used by both StorageController (UI) and
 * MainTask::storageRetentionAction (daily cron).
 *
 * Owns the category whitelist, per-tenant path resolution, disk/dir
 * measurement, and the age-based purge primitives. The UI never sends a raw
 * path — every operation names a category KEY which resolves here, so there is
 * no path-traversal surface. Only `deletable` categories can be purged; live
 * system logs are never removed (rotated artefacts only).
 *
 * See docs/PLAN-storage-retention.md and docs/PLAN-storage-management.md.
 */
class Storage extends Injectable
{
    /** Hard ceiling on any single shell walk so a huge tree can't hang PHP. */
    const CMD_TIMEOUT = 25;

    const ALL_MIN_AGE_MINUTES = 10;

    const BACKUP_DIR = "/var/www/ringq/app/migrations";

    /**
     * Meet data roots.
     *
     * Declared once because they are read from two places each — the category
     * table below and the collaboration retention walk in
     * purgeMeetCollaboration(). They drifted apart exactly that way: the
     * category pointed at /var/www/ringq/public/meet_recordings, the retention
     * walk at /var/lib/ringq/meet-recordings, and the category was measuring a
     * directory the current stack never writes to.
     *
     * MEET_RECORDING_ROOT is where the Meet v1 stack (LiveKit Egress) writes:
     * see ops/meet/runtime/ringq-meet-local, the Egress compose volumes, and
     * MEET_RECORDING_DIR in meet-control-plane.env. The old
     * /var/www/ringq/public/meet_recordings belonged to the retired :8445 PHP
     * Meet (MeetController::...) and is no longer written to — its ACL entry
     * was removed, so nothing can reach that upload path any more.
     */
    const MEET_RECORDING_ROOT     = "/var/lib/ringq/meet-recordings";
    const MEET_COLLABORATION_ROOT = "/var/lib/ringq/meet-collaboration";

    /**
     * Category whitelist. `scope`:
     *   tenant → data partitioned per domain (path gets a /<domain_name> segment)
     *   global → server-wide; configured once under the system owner
     *
     * `type`: dir | logs | db | remote
     */
    public function categories(): array
    {
        return [
            "recordings" => [
                "label" => "Call Recordings", "type" => "dir", "scope" => "tenant",
                "path" => "/var/lib/freeswitch/recordings", "deletable" => true,
                "note" => "Per-extension call recordings.",
            ],
            "voicemail" => [
                "label" => "Voicemails", "type" => "dir", "scope" => "tenant",
                "path" => "/var/lib/freeswitch/storage/voicemail/default", "deletable" => true,
                "note" => "Voice messages left by callers.",
            ],
            "meet" => [
                "label" => "Meet Recordings", "type" => "dir", "scope" => "global",
                "path" => self::MEET_RECORDING_ROOT, "deletable" => true,
                "note" => "RingQ Meet session recordings.",
            ],
            "meet_collaboration" => [
                "label" => "Meet Collaboration", "type" => "meet_collaboration", "scope" => "tenant",
                "path" => self::MEET_COLLABORATION_ROOT, "deletable" => true,
                "note" => "Private retained Meet chat attachments, messages, and whiteboards. Complete ended meetings are removed as one tenant-scoped unit.",
            ],
            "captures" => [
                "label" => "Packet Captures", "type" => "dir", "scope" => "global",
                "path" => "/var/pcap", "deletable" => true,
                "sources" => [
                    ["path" => "/var/pcap", "pattern" => null, "maxDepth" => null],
                    ["path" => "/var/call-pcaps", "pattern" => "*.pcap*", "maxDepth" => null],
                    ["path" => "/tmp", "pattern" => "*.pcap*", "maxDepth" => 1],
                ],
                "note" => "SIP/network capture files: the Capture tool (/var/pcap), per-call SIP traces (/var/call-pcaps) and capture files left in /tmp (only *.pcap / *.pcapng at the top of /tmp).",
            ],
            // Chat uploads are split by media type. They live in per-source
            // subfolders (wp_img, fb_image, line_file, telegram_image, …), so we
            // match by glob — new sources following the naming convention are
            // picked up automatically.
            "chat_images" => [
                "label" => "Chat Images", "type" => "dir", "scope" => "global",
                "base" => "/var/www/webapp/chat_file_upload",
                "globs" => ["*_img", "*_image"],
                "path" => "/var/www/webapp/chat_file_upload",
                "deletable" => true,
                "note" => "Chat image attachments (all sources).",
            ],
            "chat_files" => [
                "label" => "Chat Files", "type" => "dir", "scope" => "global",
                "base" => "/var/www/webapp/chat_file_upload",
                "globs" => ["*_file", "*_files"],
                "path" => "/var/www/webapp/chat_file_upload",
                "deletable" => true,
                "note" => "Chat document/file attachments (all sources).",
            ],
            "chat_media" => [
                "label" => "Chat Media", "type" => "dir", "scope" => "global",
                "base" => "/var/www/webapp/chat_file_upload",
                "globs" => ["*_vid", "*_video", "*_audio", "*_media"],
                "path" => "/var/www/webapp/chat_file_upload",
                "deletable" => true,
                "note" => "Chat audio/video attachments (all sources).",
            ],
            "logs" => [
                "label" => "System Logs", "type" => "logs", "scope" => "global",
                "path" => "/var/log", "deletable" => true,
                "note" => "Cleanup removes rotated logs only (*.gz, *.1 …); live logs are kept.",
            ],
            "mail" => [
                "label" => "System Mail", "type" => "dir", "scope" => "global",
                "path" => "/var/mail", "deletable" => false,
                "note" => "Local mail spool. Scheduled tasks deliver their output here; it grows without limit when a task keeps failing. Shown for monitoring only.",
            ],
            "cdr" => [
                "label" => "Call Logs / CDRs", "type" => "db", "scope" => "tenant",
                "path" => null, "deletable" => true,
                "tables" => ["q_ringq_cdr", "q_ringq_cdr2"],
                "note" => "Call detail records in the database. Cleanup permanently deletes this tenant's call history older than the chosen age (used by Reports); frees little disk.",
            ],
            "backups" => [
                "label" => "Backups", "type" => "dir", "scope" => "global",
                "path" => "/var/www/ringq/app/migrations",
                "filePattern" => "*.zip",
                "deletable" => true,
                "note" => "Local Disk backups (.zip) under app/migrations. Cleanup only removes .zip archives — migration files are never touched. Off-box copies (S3/FTP/SFTP) aren't counted.",
            ],
        ];
    }

    /**
     * Resolve the filesystem path for a category. For tenant-scoped categories a
     * /<domain_name> segment is appended so a purge can never reach across to
     * another tenant's data. Returns null for db/remote categories or unknown keys.
     */
    public function pathFor(string $key, ?string $domainName = null): ?string
    {
        $cats = $this->categories();
        if (!isset($cats[$key])) {
            return null;
        }
        $meta = $cats[$key];
        $base = $meta["path"];
        if ($base === null) {
            return null;
        }
        if (($meta["scope"] ?? "global") === "tenant" && $domainName) {
            return rtrim($base, "/") . "/" . $domainName;
        }
        return $base;
    }

    /**
     * Every existing directory a category maps to. Glob-based categories (chat
     * subtypes) expand their patterns under `base`; everything else resolves to
     * its single (optionally per-tenant) path. Only directories that exist are
     * returned.
     */
    public function resolvePaths(string $key, ?string $domainName = null): array
    {
        $cats = $this->categories();
        if (!isset($cats[$key])) {
            return [];
        }
        $meta = $cats[$key];
        if (!empty($meta["globs"]) && !empty($meta["base"])) {
            $found = [];
            foreach ($meta["globs"] as $pattern) {
                foreach (glob(rtrim($meta["base"], "/") . "/" . $pattern, GLOB_ONLYDIR) ?: [] as $dir) {
                    $found[$dir] = true; // dedupe overlapping patterns
                }
            }
            return array_keys($found);
        }
        $p = $this->pathFor($key, $domainName);
        return ($p && @is_dir($p)) ? [$p] : [];
    }

    public function sourcesFor(string $key, ?string $domainName = null): array
    {
        $cats = $this->categories();
        if (!isset($cats[$key])) {
            return [];
        }
        $meta = $cats[$key];
        if (!empty($meta["sources"]) && is_array($meta["sources"])) {
            $out = [];
            foreach ($meta["sources"] as $source) {
                $path = (string) ($source["path"] ?? "");
                if ($path !== "" && @is_dir($path)) {
                    $out[] = [
                        "path" => $path,
                        "pattern" => isset($source["pattern"]) ? (string) $source["pattern"] : null,
                        "maxDepth" => isset($source["maxDepth"]) ? (int) $source["maxDepth"] : null,
                    ];
                }
            }
            return $out;
        }
        $pattern = $meta["filePattern"] ?? null;
        return array_map(function ($path) use ($pattern) {
            return ["path" => $path, "pattern" => $pattern, "maxDepth" => null];
        }, $this->resolvePaths($key, $domainName));
    }

    public function measureSources(array $sources): array
    {
        $bytes = 0;
        $files = 0;
        $oldestEpoch = null;
        foreach ($sources as $source) {
            if (($source["pattern"] ?? null) === null && ($source["maxDepth"] ?? null) === null) {
                $s = $this->dirStats((string) $source["path"]);
                if ($s["bytes"] !== null) { $bytes += $s["bytes"]; }
                if ($s["files"] !== null) { $files += $s["files"]; }
                $e = $s["oldest"] !== null ? strtotime($s["oldest"]) : false;
            } else {
                $expr = $this->findExpr((string) $source["path"], $source["pattern"] ?? null, $source["maxDepth"] ?? null);
                $m = $this->matchStats($expr);
                $bytes += $m["bytes"];
                $files += $m["files"];
                [$out] = $this->run("timeout " . self::CMD_TIMEOUT . " bash -c "
                    . escapeshellarg($expr . " -printf '%T@\\n' 2>/dev/null | sort -n | head -1"));
                $e = isset($out[0]) && is_numeric(trim($out[0])) ? (int) trim($out[0]) : false;
            }
            if ($e && ($oldestEpoch === null || $e < $oldestEpoch)) {
                $oldestEpoch = $e;
            }
        }
        return [
            "bytes"  => $bytes,
            "files"  => $files,
            "oldest" => $oldestEpoch !== null ? date("c", $oldestEpoch) : null,
        ];
    }

    private function findExpr(string $path, ?string $namePattern, ?int $maxDepth): string
    {
        return "find " . escapeshellarg($path)
            . ($maxDepth !== null ? " -maxdepth " . max(1, $maxDepth) : "")
            . " -type f"
            . ($namePattern ? " -name " . escapeshellarg($namePattern) : "")
            . " ";
    }

    // ---------------------------------------------------------------
    // Measurement
    // ---------------------------------------------------------------

    public function diskUsage(): array
    {
        [$out, $code] = $this->run("df -B1 -P / 2>/dev/null");
        $disk = ["total" => null, "used" => null, "free" => null, "pct" => null, "mount" => "/"];
        if ($code === 0 && isset($out[1])) {
            $cols = preg_split('/\s+/', trim($out[1]));
            if (count($cols) >= 6) {
                $disk["total"] = (int)$cols[1];
                $disk["used"]  = (int)$cols[2];
                $disk["free"]  = (int)$cols[3];
                $disk["pct"]   = (int)rtrim($cols[4], "%");
                $disk["mount"] = $cols[5];
            }
        }
        return $disk;
    }

    /** Bytes + file count + oldest mtime for a directory, each bounded by a timeout. */
    public function dirStats(string $path): array
    {
        $arg = escapeshellarg($path);

        [$out] = $this->run("timeout " . self::CMD_TIMEOUT . " du -sb $arg 2>/dev/null");
        $bytes = isset($out[0]) ? (int)preg_split('/\s+/', trim($out[0]))[0] : null;

        [$out] = $this->run("timeout " . self::CMD_TIMEOUT . " bash -c " .
            escapeshellarg("find $arg -type f 2>/dev/null | wc -l"));
        $files = isset($out[0]) ? (int)trim($out[0]) : null;

        $oldest = null;
        [$out] = $this->run("timeout " . self::CMD_TIMEOUT . " bash -c " .
            escapeshellarg("find $arg -type f -printf '%T@\\n' 2>/dev/null | sort -n | head -1"));
        if (isset($out[0]) && is_numeric(trim($out[0]))) {
            $oldest = date("c", (int)trim($out[0]));
        }

        return ["bytes" => $bytes, "files" => $files, "oldest" => $oldest];
    }

    /** Aggregate dirStats across several directories (sum sizes/counts, earliest oldest). */
    public function measurePaths(array $paths): array
    {
        $bytes = 0;
        $files = 0;
        $oldestEpoch = null;
        foreach ($paths as $p) {
            $s = $this->dirStats($p);
            if ($s["bytes"] !== null) { $bytes += $s["bytes"]; }
            if ($s["files"] !== null) { $files += $s["files"]; }
            if ($s["oldest"] !== null) {
                $e = strtotime($s["oldest"]);
                if ($e && ($oldestEpoch === null || $e < $oldestEpoch)) { $oldestEpoch = $e; }
            }
        }
        return [
            "bytes"  => $bytes,
            "files"  => $files,
            "oldest" => $oldestEpoch !== null ? date("c", $oldestEpoch) : null,
        ];
    }

    /** Total on-disk size of the CDR tables, null on error. */
    public function cdrStats(array $meta, string $domainUuid): array
    {
        $rows = 0;
        $oldest = null;
        try {
            foreach ($meta["tables"] ?? [] as $table) {
                if (!preg_match('/^[a-z0-9_]+$/', (string)$table)) {
                    continue;
                }
                $exists = $this->db->fetchOne("SELECT to_regclass(:t) AS t", \Phalcon\Db\Enum::FETCH_ASSOC, ["t" => $table]);
                if (empty($exists["t"])) {
                    continue;
                }
                $row = $this->db->fetchOne(
                    "SELECT count(*) AS c, min(start_epoch) AS o FROM {$table} WHERE domain_uuid = :uuid",
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    ["uuid" => $domainUuid]
                );
                $rows += (int)($row["c"] ?? 0);
                $o = isset($row["o"]) ? (int)$row["o"] : 0;
                if ($o > 0 && ($oldest === null || $o < $oldest)) {
                    $oldest = $o;
                }
            }
        } catch (\Throwable $e) {
            return ["rows" => null, "oldest" => null];
        }
        return ["rows" => $rows, "oldest" => $oldest !== null ? date("c", $oldest) : null];
    }

    public function cdrBytes(): ?int
    {
        try {
            $row = $this->db->fetchOne(
                "SELECT COALESCE(pg_total_relation_size(to_regclass('q_ringq_cdr')), 0)
                       + COALESCE(pg_total_relation_size(to_regclass('q_ringq_cdr2')), 0) AS bytes"
            );
            return $row ? (int)$row["bytes"] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function collaborationStats(string $domainUuid): array
    {
        try {
            $row = $this->db->fetchOne(
                "SELECT COALESCE(sum(bytes), 0) AS bytes, count(*) AS files, min(created_at) AS oldest
                   FROM (
                     SELECT bytes, created_at FROM public.meet_attachments
                      WHERE domain_uuid = :uuid AND status IN ('ready', 'attached')
                     UNION ALL
                     SELECT bytes, created_at FROM public.meet_file_pages
                      WHERE domain_uuid = :uuid
                   ) collaboration_files",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ["uuid" => $domainUuid]
            );
            return [
                "bytes" => (int)($row["bytes"] ?? 0),
                "files" => (int)($row["files"] ?? 0),
                "oldest" => $row["oldest"] ?? null,
            ];
        } catch (\Throwable $e) {
            return ["bytes" => null, "files" => null, "oldest" => null];
        }
    }

    /**
     * Delete CDR rows older than $days for ONE tenant. Tenant-scoped by
     * domain_uuid so a delete can never reach another tenant's call history;
     * with no $domainUuid nothing is deleted. Uses start_epoch (unix seconds,
     * the column Reports filters on). Table names come from the whitelist, never
     * user input; the date/tenant filters are bound parameters. Reports counts,
     * not bytes (deleting rows doesn't shrink the table without VACUUM).
     */
    private function purgeCdr(array $meta, int $days, bool $dryRun, ?string $domainUuid, ?RemoteStorage $remote = null): array
    {
        if (!$domainUuid) {
            return ["deletedFiles" => 0, "freedBytes" => 0, "remaining" => 0, "rows" => 0, "message" => "No tenant context."];
        }
        $cutoff = time() - ($days * 86400);
        $rows = 0;
        $failed = 0;
        foreach (($meta["tables"] ?? []) as $table) {
            // Whitelisted identifiers only; skip tables that don't exist.
            if (!preg_match('/^[a-z0-9_]+$/i', $table)) {
                continue;
            }
            try {
                $reg = $this->db->fetchOne(
                    "SELECT to_regclass(:t) AS t",
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    ["t" => "public." . $table]
                );
                if (!$reg || $reg["t"] === null) {
                    continue;
                }
                if ($dryRun) {
                    $c = $this->db->fetchOne(
                        "SELECT count(*) AS c FROM {$table} WHERE domain_uuid = :uuid AND start_epoch < :cutoff",
                        \Phalcon\Db\Enum::FETCH_ASSOC,
                        ["uuid" => $domainUuid, "cutoff" => $cutoff]
                    );
                    $rows += $c ? (int)$c["c"] : 0;
                    continue;
                }

                // Archive: export the matching rows to a CSV file, upload it, and
                // only DELETE if the upload verified. If archiving fails, keep the
                // rows (skip the delete for this table).
                if ($remote) {
                    if (!$this->archiveCdrTable($table, $domainUuid, $cutoff, $remote)) {
                        $failed++;
                        continue;
                    }
                }

                $this->db->execute(
                    "DELETE FROM {$table} WHERE domain_uuid = :uuid AND start_epoch < :cutoff",
                    ["uuid" => $domainUuid, "cutoff" => $cutoff]
                );
                $rows += (int)$this->db->affectedRows();
            } catch (\Throwable $e) {
                // skip a table that errors (missing column/table); others continue
            }
        }
        $verb = $remote ? "archived + deleted" : ($dryRun ? "match" : "deleted");
        return [
            "deletedFiles" => $rows,   // UI treats this as the item count
            "freedBytes"   => 0,       // row deletes don't reclaim file space without VACUUM
            "remaining"    => $failed,
            "rows"         => $rows,
            "message"      => "{$rows} record(s) {$verb}" . ($failed > 0 ? ", {$failed} table(s) skipped (archive failed)" : "") . ".",
        ];
    }

    /**
     * Export a CDR table's matching rows to a temp CSV and upload it to the
     * remote. Rows are fetched in PHP (CDR volume is small) and written as CSV.
     * Returns true only when the upload verified. Temp file is always cleaned up.
     */
    private function archiveCdrTable(string $table, string $domainUuid, int $cutoff, RemoteStorage $remote): bool
    {
        $tmp = null;
        try {
            $rows = $this->db->fetchAll(
                "SELECT * FROM {$table} WHERE domain_uuid = :uuid AND start_epoch < :cutoff",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ["uuid" => $domainUuid, "cutoff" => $cutoff]
            );
            if (empty($rows)) {
                return true; // nothing to archive → let the (no-op) delete proceed
            }
            $tmp = tempnam(sys_get_temp_dir(), "cdr_arch_");
            $fh = @fopen($tmp, "w");
            if (!$fh) {
                return false;
            }
            fputcsv($fh, array_keys($rows[0]));
            foreach ($rows as $r) {
                fputcsv($fh, $r);
            }
            fclose($fh);
            $stamp = date("Ymd_His");
            $rel = "cdr/{$domainUuid}/{$table}_{$stamp}.csv";
            return $remote->upload($tmp, $rel);
        } catch (\Throwable $e) {
            return false;
        } finally {
            if ($tmp && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** Collaboration is retained per complete ended meeting, never by a cross-tenant directory walk. */
    private function purgeMeetCollaboration(int $days, bool $dryRun, ?string $domainUuid, ?RemoteStorage $remote = null): array
    {
        if (!$domainUuid) {
            return ["deletedFiles" => 0, "freedBytes" => 0, "remaining" => 0, "message" => "No tenant context."];
        }
        $root = self::MEET_COLLABORATION_ROOT;
        $recordingRoot = self::MEET_RECORDING_ROOT;
        $cutoff = date("c", time() - ($days * 86400));
        try {
            $meetings = $this->db->fetchAll(
                "SELECT id FROM public.meet_meetings
                  WHERE domain_uuid = :uuid AND status = 'ended' AND ended_at IS NOT NULL AND ended_at < :cutoff
                  ORDER BY ended_at ASC",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ["uuid" => $domainUuid, "cutoff" => $cutoff]
            );
        } catch (\Throwable $e) {
            return ["deletedFiles" => 0, "freedBytes" => 0, "remaining" => 1, "message" => "Collaboration schema is unavailable."];
        }

        $deletedMeetings = 0;
        $deletedFiles = 0;
        $freedBytes = 0;
        $failed = 0;
        foreach ($meetings as $meeting) {
            $meetingId = strtolower((string)$meeting["id"]);
            try {
                $export = $this->collaborationExport($domainUuid, $meetingId);
                $paths = [];
                $meetingBytes = 0;
                foreach ($export["attachments"] as $attachment) {
                    $file = (string)($attachment["storage_path"] ?? "");
                    if ($file === "") { continue; }
                    if (!$this->validCollaborationPath($root, $meetingId, $file)) {
                        throw new \RuntimeException("Unsafe attachment path");
                    }
                    if (is_file($file)) {
                        $paths[] = $file;
                        $meetingBytes += (int)filesize($file);
                    }
                }
                foreach (($export["meet_file_pages"] ?? []) as $page) {
                    $file = (string)($page["storage_path"] ?? "");
                    if ($file === "") { continue; }
                    if (!$this->validCollaborationPath($root, $meetingId, $file)) {
                        throw new \RuntimeException("Unsafe preview path");
                    }
                    if (is_file($file)) {
                        $paths[] = $file;
                        $meetingBytes += (int)filesize($file);
                    }
                }
                foreach (($export["meet_recordings"] ?? []) as $recording) {
                    $file = (string)($recording["storage_path"] ?? "");
                    if ($file === "") { continue; }
                    if (!$this->validRecordingPath($recordingRoot, $meetingId, $file)) {
                        throw new \RuntimeException("Unsafe recording path");
                    }
                    if (is_file($file)) {
                        $paths[] = $file;
                        $meetingBytes += (int)filesize($file);
                    }
                }
                $paths = array_values(array_unique($paths));
                $meetingDirs = array_values(array_unique(array_filter(array_map(
                    fn(string $file): ?string => $this->collaborationMeetingDir($root, $meetingId, $file),
                    $paths
                ))));
                $recordingDirs = array_values(array_unique(array_filter(array_map(
                    fn(string $file): ?string => $this->collaborationMeetingDir($recordingRoot, $meetingId, $file),
                    $paths
                ))));
                if (count($meetingDirs) > 1 || count($recordingDirs) > 1 ||
                    count($meetingDirs) + count($recordingDirs) > 2) {
                    throw new \RuntimeException("Collaboration paths cross tenant boundaries");
                }
                if ($dryRun) {
                    $deletedMeetings++;
                    $deletedFiles += count($paths);
                    $freedBytes += $meetingBytes;
                    continue;
                }
                if ($remote && !$this->archiveCollaborationMeeting($domainUuid, $meetingId, $export, $paths, $remote)) {
                    $failed++;
                    continue;
                }

                $stagedDirs = [];
                foreach (array_merge($meetingDirs, $recordingDirs) as $meetingDir) {
                    if ($meetingDir && is_dir($meetingDir)) {
                        $stagedDir = dirname($meetingDir) . "/.purge-" . $meetingId . "-" . bin2hex(random_bytes(4));
                        if (!@rename($meetingDir, $stagedDir)) {
                            foreach ($stagedDirs as $pair) { @rename($pair[1], $pair[0]); }
                            throw new \RuntimeException("Could not stage collaboration files");
                        }
                        $stagedDirs[] = [$meetingDir, $stagedDir];
                    }
                }
                try {
                    $this->db->begin();
                    foreach (["meet_capture_actions", "meet_feedback", "meet_summaries", "meet_transcripts", "meet_capture_states", "meet_recordings", "meet_file_presentation_actions", "meet_file_pages", "meet_file_presentations", "meet_file_assets", "meet_whiteboard_checkpoints", "meet_whiteboard_batches", "meet_whiteboard_snapshots", "meet_whiteboard_boards", "meet_attachments", "meet_chat_messages"] as $table) {
                        $this->db->execute(
                            "DELETE FROM public.{$table} WHERE domain_uuid = :uuid AND meeting_id = :meeting",
                            ["uuid" => $domainUuid, "meeting" => $meetingId]
                        );
                    }
                    $this->db->commit();
                } catch (\Throwable $e) {
                    $this->db->rollback();
                    foreach ($stagedDirs as $pair) { if (is_dir($pair[1])) { @rename($pair[1], $pair[0]); } }
                    throw $e;
                }
                foreach ($stagedDirs as $pair) { if (is_dir($pair[1])) { $this->removeTree($pair[1]); } }
                $deletedMeetings++;
                $deletedFiles += count($paths);
                $freedBytes += $meetingBytes;
            } catch (\Throwable $e) {
                $failed++;
            }
        }
        return [
            "deletedFiles" => $deletedFiles,
            "freedBytes" => $dryRun ? $freedBytes : $freedBytes,
            "remaining" => $failed,
            "meetings" => $deletedMeetings,
            "message" => $dryRun
                ? $deletedMeetings . " ended meeting(s) match."
                : $deletedMeetings . " ended meeting collaboration unit(s) removed" . ($failed ? "; {$failed} kept." : "."),
        ];
    }

    private function collaborationExport(string $domainUuid, string $meetingId): array
    {
        $tables = ["meet_chat_messages", "meet_attachments", "meet_whiteboard_boards", "meet_whiteboard_batches", "meet_whiteboard_checkpoints", "meet_file_assets", "meet_file_pages", "meet_file_presentations", "meet_file_presentation_actions", "meet_recordings", "meet_transcripts", "meet_summaries", "meet_feedback", "meet_capture_states", "meet_capture_actions"];
        $result = ["manifestVersion" => 3, "domainUuid" => $domainUuid, "meetingId" => $meetingId, "exportedAt" => date("c")];
        foreach ($tables as $table) {
            $key = $table === "meet_attachments" ? "attachments" : $table;
            $result[$key] = $this->db->fetchAll(
                "SELECT * FROM public.{$table} WHERE domain_uuid = :uuid AND meeting_id = :meeting",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ["uuid" => $domainUuid, "meeting" => $meetingId]
            );
        }
        return $result;
    }

    private function validCollaborationPath(string $root, string $meetingId, string $file): bool
    {
        $quotedRoot = preg_quote(rtrim($root, "/"), "#");
        $quotedMeeting = preg_quote($meetingId, "#");
        return (bool)preg_match("#^{$quotedRoot}/LKRQ[a-f0-9]{12}/{$quotedMeeting}/#D", $file);
    }

    private function validRecordingPath(string $root, string $meetingId, string $file): bool
    {
        return $this->validCollaborationPath($root, $meetingId, $file);
    }

    /** Resolve exactly the tenant/meeting unit; never infer it with dirname depth. */
    private function collaborationMeetingDir(string $root, string $meetingId, string $file): ?string
    {
        $quotedRoot = preg_quote(rtrim($root, "/"), "#");
        $quotedMeeting = preg_quote($meetingId, "#");
        if (!preg_match("#^({$quotedRoot}/LKRQ[a-f0-9]{12}/{$quotedMeeting})/#D", $file, $match)) {
            return null;
        }
        return $match[1];
    }

    private function archiveCollaborationMeeting(string $domainUuid, string $meetingId, array $export, array $paths, RemoteStorage $remote): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), "meet_collab_");
        if (!$tmp) { return false; }
        try {
            if (@file_put_contents($tmp, json_encode($export, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false) { return false; }
            $base = "meet_collaboration/{$domainUuid}/{$meetingId}";
            if (!$remote->upload($tmp, $base . "/manifest.json")) { return false; }
            foreach ($paths as $file) {
                if (!preg_match("#/meet-(collaboration|recordings)/LKRQ[a-f0-9]{12}/" . preg_quote($meetingId, "#") . "/(.+)$#D", $file, $match)) { return false; }
                $kind = $match[1] === "recordings" ? "recordings" : "collaboration";
                if (!$remote->upload($file, $base . "/files/" . $kind . "/" . $match[2])) { return false; }
            }
            return true;
        } finally {
            @unlink($tmp);
        }
    }

    private function removeTree(string $dir): void
    {
        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === "." || $item === "..") { continue; }
            $path = $dir . "/" . $item;
            if (is_dir($path) && !is_link($path)) { $this->removeTree($path); }
            else { @unlink($path); }
        }
        @rmdir($dir);
    }

    // ---------------------------------------------------------------
    // Purge
    // ---------------------------------------------------------------

    /**
     * Delete data older than $days for a category, resolved to the caller's
     * tenant where applicable. Logs only remove rotated artefacts; other dir
     * categories remove any file by age, aggregated across every directory the
     * category maps to (chat subtypes span several dirs). dryRun counts only.
     */
    public function purge(string $key, int $days, bool $dryRun, ?string $domainName = null, ?string $domainUuid = null, bool $archive = false, int $maxFiles = 0, bool $all = false): array
    {
        $cats = $this->categories();
        if (!isset($cats[$key])) {
            return ["deletedFiles" => 0, "freedBytes" => 0, "remaining" => 0, "message" => "Unknown category."];
        }

        if ($all && $key === "backups" && $this->backupJobActive()) {
            return [
                "deletedFiles" => 0, "freedBytes" => 0, "remaining" => 0, "skipped" => true,
                "message" => "A backup, restore or import is running. Try again when it finishes.",
            ];
        }

        // Archive mode (real run only): upload each file to the Time Machine and
        // delete locally only on verified success. If no remote target is
        // configured, skip entirely — never delete when we cannot archive.
        $remote = null;
        if ($archive && !$dryRun) {
            $remote = new RemoteStorage();
            if (!$remote->available()) {
                return [
                    "deletedFiles" => 0, "freedBytes" => 0, "remaining" => 0, "skipped" => true,
                    "message" => "Archive target not configured — nothing removed.",
                ];
            }
        }

        if (($cats[$key]["type"] ?? "") === "logs") {
            return $this->purgeLogs($cats[$key]["path"], $days, $dryRun, $remote, $all);
        }
        if (($cats[$key]["type"] ?? "") === "db") {
            return $this->purgeCdr($cats[$key], $all ? 0 : $days, $dryRun, $domainUuid, $remote);
        }
        if (($cats[$key]["type"] ?? "") === "meet_collaboration") {
            return $this->purgeMeetCollaboration($all ? 0 : $days, $dryRun, $domainUuid, $remote);
        }

        $sources = $this->sourcesFor($key, $domainName);
        if (empty($sources)) {
            return ["deletedFiles" => 0, "freedBytes" => 0, "remaining" => 0, "message" => "Nothing to clean."];
        }

        // Archive branch: per-file upload → verify → delete, across all dirs.
        if ($remote) {
            $arch = 0;
            $freed = 0;
            $failed = 0;
            foreach ($sources as $source) {
                $budget = $maxFiles > 0 ? ($maxFiles - $arch) : 0;
                if ($maxFiles > 0 && $budget <= 0) {
                    break;
                }
                $r = $this->archiveDir($source["path"], $days, $source["pattern"], $key, $remote, $budget, $all, $source["maxDepth"]);
                $arch   += $r["archivedFiles"];
                $freed  += $r["freedBytes"];
                $failed += $r["failed"];
            }
            return [
                "deletedFiles" => $arch,
                "freedBytes"   => $freed,
                "remaining"    => $failed,
                "archived"     => true,
                "listEntriesRemoved" => $key === "backups" ? $this->pruneBackupList() : 0,
                "message"      => $arch . " file(s) archived to " . $remote->label()
                    . ($failed > 0 ? ", {$failed} failed (kept)." : "."),
            ];
        }

        $deleted = 0;
        $freed = 0;
        $remaining = 0;
        foreach ($sources as $source) {
            $r = $this->purgeDir($source["path"], $days, $dryRun, $source["pattern"], $all, $source["maxDepth"]);
            $deleted   += $r["deletedFiles"];
            $freed     += $r["freedBytes"];
            $remaining += $r["remaining"] ?? 0;
        }
        return [
            "deletedFiles" => $deleted,
            "freedBytes"   => $freed,
            "remaining"    => $remaining,
            "listEntriesRemoved" => ($key === "backups" && !$dryRun) ? $this->pruneBackupList() : 0,
            "message"      => $dryRun
                ? $deleted . " file(s) match."
                : ($remaining > 0
                    ? $remaining . " file(s) could not be removed (permissions)."
                    : "Cleanup complete."),
        ];
    }

    /**
     * Archive matched files to the remote then delete each locally on verified
     * upload. Files that fail to upload (or delete) are kept and counted as
     * failed. $maxFiles > 0 caps how many are processed (0 = unlimited).
     */
    private function archiveDir(string $path, int $days, ?string $namePattern, string $categoryKey, RemoteStorage $remote, int $maxFiles = 0, bool $all = false, ?int $maxDepth = null): array
    {
        if (!@is_dir($path)) {
            return ["archivedFiles" => 0, "freedBytes" => 0, "failed" => 0];
        }
        $age = $this->ageClause($days, $all);
        [$out] = $this->run("timeout " . self::CMD_TIMEOUT . " " . $this->findExpr($path, $namePattern, $maxDepth) . $age . "2>/dev/null");

        $archived = 0;
        $freed = 0;
        $failed = 0;
        foreach ($out as $file) {
            $file = trim($file);
            if ($file === "" || !@is_file($file)) {
                continue;
            }
            if ($maxFiles > 0 && $archived >= $maxFiles) {
                break;
            }
            $size = (int)@filesize($file);
            // Remote path preserves category + origin so archives are traceable
            // and never collide across categories/tenants.
            $rel = $categoryKey . "/" . ltrim($file, "/");
            if ($remote->upload($file, $rel)) {
                $gone = @unlink($file);
                if (!$gone) {
                    $this->run("sudo -n rm -f " . escapeshellarg($file) . " 2>/dev/null");
                    $gone = !@is_file($file);
                }
                if ($gone) {
                    $archived++;
                    $freed += $size;
                } else {
                    $failed++; // uploaded but local delete failed (permissions)
                }
            } else {
                $failed++; // upload failed — keep the local file, retry next run
            }
        }
        return ["archivedFiles" => $archived, "freedBytes" => $freed, "failed" => $failed];
    }

    private function purgeDir(string $path, int $days, bool $dryRun, ?string $namePattern = null, bool $all = false, ?int $maxDepth = null): array
    {
        if (!@is_dir($path)) {
            return ["deletedFiles" => 0, "freedBytes" => 0, "remaining" => 0, "message" => "Directory does not exist."];
        }
        $age = $this->ageClause($days, $all);
        $expr = $this->findExpr($path, $namePattern, $maxDepth) . ltrim($age);

        $before = $this->matchStats($expr);
        if ($dryRun) {
            return [
                "deletedFiles" => $before["files"],
                "freedBytes"   => $before["bytes"],
                "remaining"    => $before["files"],
                "message"      => $before["files"] . " file(s) match.",
            ];
        }

        $this->run("timeout " . self::CMD_TIMEOUT . " sudo -n " . $expr . "-delete 2>/dev/null; "
            . "timeout " . self::CMD_TIMEOUT . " " . $expr . "-delete 2>/dev/null");
        $after = $this->matchStats($expr);

        return [
            "deletedFiles" => max(0, $before["files"] - $after["files"]),
            "freedBytes"   => max(0, $before["bytes"] - $after["bytes"]),
            "remaining"    => $after["files"],
            "message"      => $after["files"] > 0
                ? $after["files"] . " file(s) could not be removed (permissions)."
                : "Cleanup complete.",
        ];
    }

    /**
     * /var/log cleanup (Moderate policy): removes ALL rotated artefacts
     * (compressed/numbered rotations — not age-gated, since the big recent
     * syslog.1 is the main thing worth reclaiming) and vacuums journald down to
     * the retention age. The LIVE logs (syslog, auth.log …) are never touched —
     * they are append-only and owned/rotated by the system.
     *
     * Needs root to act on /var/log + journald: the daily cron runs as root and
     * reclaims fully; the manual UI is best-effort by the web user's privileges
     * (we try `sudo -n` then fall back to a plain call). Freed bytes are measured
     * before/after, so the reported total is always honest.
     */
    private function purgeLogs(string $path, int $days, bool $dryRun, ?RemoteStorage $remote = null, bool $all = false): array
    {
        $vacuumAge = $all ? "1s" : $days . "d";
        if (!@is_dir($path)) {
            return ["deletedFiles" => 0, "freedBytes" => 0, "remaining" => 0, "message" => "Directory does not exist."];
        }
        $arg = escapeshellarg($path);
        $matchPred = "\\( -name '*.gz' -o -name '*.xz' -o -name '*.old' -o -regex '.*\\.[0-9]+' \\)";
        $expr = "find $arg -type f $matchPred"; // all rotated, no -mtime

        $rotated      = $this->matchStats($expr);
        $journalBytes = $this->journalUsage();

        if ($dryRun) {
            // Headline = firm rotated total. journald is reported separately
            // because --vacuum-time only frees the portion older than $days,
            // which we can't predict precisely without running it.
            return [
                "deletedFiles" => $rotated["files"],
                "freedBytes"   => $rotated["bytes"],
                "remaining"    => $rotated["files"],
                "message"      => $rotated["files"] . " rotated file(s)"
                    . ($journalBytes > 0 ? "; journald (~" . $this->human($journalBytes) . ") vacuumed " . ($all ? "down to the active journal" : "older than {$days}d") : "")
                    . ". Live logs are kept.",
            ];
        }

        // Archive branch: upload each rotated file then remove it (root-owned logs
        // may need sudo rm). journald is vacuumed but not archived; live logs kept.
        if ($remote) {
            [$out] = $this->run("timeout " . self::CMD_TIMEOUT . " " . $expr . " 2>/dev/null");
            $arch = 0;
            $freed = 0;
            $failed = 0;
            foreach ($out as $file) {
                $file = trim($file);
                if ($file === "" || !@is_file($file)) {
                    continue;
                }
                $size = (int)@filesize($file);
                if ($remote->upload($file, "logs/" . ltrim($file, "/"))) {
                    $gone = @unlink($file);
                    if (!$gone) {
                        $this->run("sudo -n rm -f " . escapeshellarg($file) . " 2>/dev/null");
                        $gone = !@is_file($file);
                    }
                    if ($gone) {
                        $arch++;
                        $freed += $size;
                    } else {
                        $failed++;
                    }
                } else {
                    $failed++;
                }
            }
            $vac = "journalctl --vacuum-time=" . $vacuumAge;
            $this->run("sudo -n " . $vac . " 2>/dev/null || " . $vac . " 2>/dev/null");
            return [
                "deletedFiles" => $arch,
                "freedBytes"   => $freed,
                "remaining"    => $failed,
                "archived"     => true,
                "message"      => $arch . " rotated log(s) archived to " . $remote->label()
                    . ($failed > 0 ? ", {$failed} failed (kept)" : "") . "; journald vacuumed.",
            ];
        }

        // 1) Remove all rotated artefacts (sudo for the web user; plain for root cron).
        $this->run("timeout " . self::CMD_TIMEOUT . " sudo -n " . $expr . " -delete 2>/dev/null; "
            . "timeout " . self::CMD_TIMEOUT . " " . $expr . " -delete 2>/dev/null");
        // 2) Vacuum journald to the retention age.
        $vac = "journalctl --vacuum-time=" . $vacuumAge;
        $this->run("sudo -n " . $vac . " 2>/dev/null || " . $vac . " 2>/dev/null");

        $rotatedAfter = $this->matchStats($expr);
        $journalAfter = $this->journalUsage();
        $freed = max(0, $rotated["bytes"] - $rotatedAfter["bytes"])
               + max(0, $journalBytes - $journalAfter);

        return [
            "deletedFiles" => max(0, $rotated["files"] - $rotatedAfter["files"]),
            "freedBytes"   => $freed,
            "remaining"    => $rotatedAfter["files"],
            "message"      => $rotatedAfter["files"] > 0
                ? $rotatedAfter["files"] . " rotated file(s) could not be removed (needs root)."
                : "Rotated logs cleared and journald vacuumed.",
        ];
    }

    private function ageClause(int $days, bool $all): string
    {
        return $all
            ? " -mmin +" . self::ALL_MIN_AGE_MINUTES . " "
            : " -mtime +" . $days . " ";
    }

    private function redisOrNull()
    {
        try {
            $di = $this->getDI();
            return ($di && $di->has("redis")) ? $di->getShared("redis") : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function backupJobActive(): bool
    {
        $redis = $this->redisOrNull();
        if (!$redis) {
            return true;
        }
        try {
            foreach (["performBackup", "lock:backup_task", "performRestore", "lock:restore_task", "performImport"] as $marker) {
                if ($redis->get($marker)) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            return true;
        }
    }

    public function localBackupZip(array $entry): ?string
    {
        $type = $entry["type"] ?? "Local Disk";
        if ($type !== "Local Disk") {
            return null;
        }
        $name = (string)($entry["name"] ?? "");
        $date = (string)($entry["datetime"] ?? "");
        if ($name === "" || $date === "" || strpbrk($name . $date, "/\\\0") !== false) {
            return null;
        }
        return self::BACKUP_DIR . "/" . $name . "_" . $date . ".zip";
    }

    public function pruneBackupList(): int
    {
        $redis = $this->redisOrNull();
        if (!$redis) {
            return 0;
        }
        $removed = 0;
        try {
            foreach ((array)$redis->lrange("backups", 0, -1) as $item) {
                $entry = json_decode((string)$item, true);
                if (!is_array($entry)) {
                    continue;
                }
                $zip = $this->localBackupZip($entry);
                if ($zip === null || @is_file($zip)) {
                    continue;
                }
                $removed += (int)$redis->lrem("backups", 1, $item);
            }
        } catch (\Throwable $e) {
            return $removed;
        }
        return $removed;
    }

    /** Human-readable byte size for log messages. */
    private function human(int $bytes): string
    {
        if ($bytes <= 0) {
            return "0 B";
        }
        $units = ["B", "KB", "MB", "GB", "TB"];
        $i = (int)floor(log($bytes, 1024));
        $i = max(0, min($i, count($units) - 1));
        return round($bytes / pow(1024, $i), 1) . " " . $units[$i];
    }

    /** Current on-disk size of the systemd journal (persistent or volatile), 0 if none. */
    private function journalUsage(): int
    {
        foreach (["/var/log/journal", "/run/log/journal"] as $jp) {
            if (@is_dir($jp)) {
                [$out] = $this->run("timeout " . self::CMD_TIMEOUT . " du -sb " . escapeshellarg($jp) . " 2>/dev/null");
                if (isset($out[0])) {
                    return (int)preg_split('/\s+/', trim($out[0]))[0];
                }
            }
        }
        return 0;
    }

    /** Run a `find …` predicate and return {files, bytes} for the matches. */
    public function matchStats(string $findExpr): array
    {
        [$out] = $this->run("timeout " . self::CMD_TIMEOUT . " bash -c " .
            escapeshellarg($findExpr . " -printf '%s\\n' 2>/dev/null"));
        $files = 0;
        $bytes = 0;
        foreach ($out as $line) {
            $line = trim($line);
            if ($line === "") {
                continue;
            }
            $files++;
            $bytes += (int)$line;
        }
        return ["files" => $files, "bytes" => $bytes];
    }

    /** exec() wrapper → [output lines[], exit code]. */
    private function run(string $cmd): array
    {
        $out  = [];
        $code = 0;
        @exec($cmd, $out, $code);
        return [$out, $code];
    }
}
