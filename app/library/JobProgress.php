<?php

namespace Library;

/**
 * Durable progress for long-running Time Machine jobs (restore, import).
 *
 * WHY A FILE AND NOT REDIS
 * ------------------------
 * A restore *replaces the entire Redis dataset* mid-job (MainTask::restoreCoreRedis
 * stops redis, copies the backup's .rdb over dump.rdb and starts it again). Anything
 * this job stored in Redis is destroyed while it is still running — which is exactly
 * how the old UI came to treat "the performRestore key is gone" as "the restore
 * finished", signing the operator out while media extraction and the FQDN remap were
 * still to come. Postgres is dropped and recreated in the same job, so it is no home
 * either. A file on disk survives both, and it survives the worker being killed —
 * which is what makes the watchdog in reap() possible at all.
 *
 * WHO WRITES AND WHO READS
 * ------------------------
 * The worker runs as root from cron; PHP-FPM reads as www-data. So the state dir is
 * group www-data and every file is 0640 root:www-data. The group is applied to the
 * TEMP file before the rename, because an atomic rename installs a NEW inode: a chgrp
 * performed once at creation is silently lost by the next write, and the reader then
 * gets a permission error that looks exactly like "no job has ever run".
 *
 * PHP 7.4 COMPATIBLE ON PURPOSE — the cron CLI on these hosts is 7.4 even where
 * PHP-FPM is 8.1. No union types, no constructor promotion, no match, no str_contains.
 *
 * See docs/PLAN-restore-progress.md.
 */
class JobProgress
{
    /** Bump when the document shape changes in a way an old client cannot read. */
    const SCHEMA = 1;

    const STATE_DIR = '/var/lib/ringq/jobs';
    const WEB_GROUP = 'www-data';

    /** A running job whose heartbeat is older than this AND whose pid is gone is dead. */
    const HEARTBEAT_STALE_SECONDS = 120;

    /** Disk writes are throttled to this interval unless the caller forces one. */
    const WRITE_INTERVAL_SECONDS = 0.5;

    const MAX_WARNINGS = 20;
    const MAX_MESSAGE = 2000;

    const STATE_IDLE       = 'idle';
    const STATE_QUEUED     = 'queued';
    const STATE_RUNNING    = 'running';
    const STATE_COMPLETED  = 'completed';
    const STATE_FAILED     = 'failed';
    const STATE_UNREADABLE = 'unreadable';

    /** @var string */
    private $job;
    /** @var string */
    private $dir;
    /** @var string */
    private $path;
    /** @var array */
    private $doc = [];
    /** @var array phase => weight */
    private $weights = [];
    /** @var float */
    private $lastWrite = 0.0;
    /**
     * ETA is clamped non-increasing so it cannot count upwards, which reads as the
     * job going backwards. reweight() lifts the clamp once, because a recalibration
     * against real file sizes is a legitimate reason for the estimate to grow.
     * @var bool
     */
    private $etaMayRise = true;

    /**
     * Ordered phase tables. The label is user-facing and is SERVER-SUPPLIED: the
     * client renders whatever arrives rather than keeping a parallel copy that can
     * drift. Weights are a cold-start guess only — reweight() replaces them with
     * shares derived from the real byte sizes as soon as the archive is open.
     */
    public static function phaseTable($job)
    {
        if ($job === 'import') {
            return [
                // NOTE: the browser owns the upload phase (real XHR progress) and
                // renders it as the first slice of one continuous bar. This document
                // describes the SERVER side only, and its percent is 0-100 of that.
                'stage'        => ['label' => 'Unpacking the archive',        'weight' => 20],
                'verify'       => ['label' => 'Checking compatibility',       'weight' => 5],
                'media_upload' => ['label' => 'Storing recordings & voicemails', 'weight' => 40],
                'repackage'    => ['label' => 'Packaging the backup',         'weight' => 30],
                'register'     => ['label' => 'Adding it to your backups',    'weight' => 5],
            ];
        }

        if ($job === 'backup') {
            return [
                'prepare'  => ['label' => 'Preparing',                          'weight' => 5],
                'redis'    => ['label' => 'Saving settings',                    'weight' => 5],
                'database' => ['label' => 'Copying the database',               'weight' => 30],
                'media'    => ['label' => 'Collecting recordings & voicemails', 'weight' => 25],
                'package'  => ['label' => 'Packaging the backup',               'weight' => 22],
                'upload'   => ['label' => 'Uploading to storage',               'weight' => 10],
                'register' => ['label' => 'Finishing up',                       'weight' => 3],
            ];
        }

        return [
            'acquire'  => ['label' => 'Fetching the backup',              'weight' => 10],
            'extract'  => ['label' => 'Opening the archive',              'weight' => 8],
            'database' => ['label' => 'Restoring database',               'weight' => 35],
            'redis'    => ['label' => 'Restoring settings',               'weight' => 5],
            'media'    => ['label' => 'Restoring recordings & voicemails', 'weight' => 30],
            'images'   => ['label' => 'Restoring images',                 'weight' => 2],
            'remap'    => ['label' => 'Adapting to this server',          'weight' => 5],
            'finalize' => ['label' => 'Finishing up',                     'weight' => 5],
        ];
    }

    public function __construct($job, $dir = null)
    {
        $this->job  = in_array($job, ['import', 'backup'], true) ? $job : 'restore';
        $this->dir  = $dir !== null ? rtrim($dir, '/') : self::STATE_DIR;
        $this->path = $this->dir . '/' . $this->job . '.json';

        $table = self::phaseTable($this->job);
        foreach ($table as $phase => $meta) {
            $this->weights[$phase] = (float) $meta['weight'];
        }
    }

    public function path()
    {
        return $this->path;
    }

    public function lockPath()
    {
        return $this->dir . '/' . $this->job . '.lock';
    }

    /**
     * True when the state directory is usable. Every mutator no-ops when it is not,
     * so a host whose /var/lib is not yet prepared still runs the job — it just
     * cannot report fine-grained progress. Losing the progress bar is acceptable;
     * refusing to restore because a status file could not be created is not.
     */
    public function available()
    {
        return $this->ensureDir();
    }

    /* ------------------------------------------------------------------ *
     * Lifecycle
     * ------------------------------------------------------------------ */

    /**
     * Open a new job document. Called BEFORE anything touches Redis or Postgres,
     * so the record of "a job started" cannot be destroyed by the job itself.
     *
     * @return string the job id (also returned to the client so it can correlate
     *                its polling across the Redis wipe and the session flush)
     */
    public function begin(array $subject, $jobId = null, $state = self::STATE_RUNNING)
    {
        $now = time();
        if ($jobId === null) {
            $jobId = substr($this->job, 0, 1) . '-' . date('Ymd-His', $now) . '-'
                . substr(bin2hex(random_bytes(3)), 0, 6);
        }

        $table = self::phaseTable($this->job);
        $first = key($table);

        $this->doc = [
            'schema'        => self::SCHEMA,
            'job'           => $this->job,
            'jobId'         => $jobId,
            'state'         => $state,
            'subject'       => $subject,
            'phase'         => $first,
            'phaseIndex'    => 0,
            'phaseCount'    => count($table),
            'phaseLabel'    => $table[$first]['label'],
            'phaseDetail'   => '',
            'phaseFraction' => null,
            'percent'       => 0,
            'startedAt'     => self::stamp($now),
            'updatedAt'     => self::stamp($now),
            'etaSeconds'    => null,
            'pid'           => function_exists('getmypid') ? getmypid() : null,
            'warnings'      => [],
            'failure'       => null,
            'finishedAt'    => null,
        ];
        $this->write(true);

        return $jobId;
    }

    /** Adopt the document already on disk (worker picking up what the web tier queued). */
    public function resume()
    {
        $doc = self::readRaw($this->path);
        if (!is_array($doc) || !isset($doc['jobId'])) {
            return false;
        }
        $this->doc          = $doc;
        $this->doc['pid']   = function_exists('getmypid') ? getmypid() : null;
        $this->doc['state'] = self::STATE_RUNNING;
        $this->write(true);

        return true;
    }

    /** Enter a phase. Always forces a write — a phase change is what the reader waits for. */
    public function phase($phase, $detail = '')
    {
        if (!$this->doc) {
            return;
        }
        $table = self::phaseTable($this->job);
        if (!isset($table[$phase])) {
            return;
        }

        // Everything before this phase is now finished, whatever fraction it reached.
        $this->doc['phase']         = $phase;
        $this->doc['phaseIndex']    = array_search($phase, array_keys($table), true);
        $this->doc['phaseCount']    = count($table);
        $this->doc['phaseLabel']    = $table[$phase]['label'];
        $this->doc['phaseDetail']   = (string) $detail;
        $this->doc['phaseFraction'] = null;
        $this->recompute();
        $this->write(true);
    }

    /**
     * Report progress inside the current phase.
     *
     * @param float|null $fraction 0..1, or NULL when this phase cannot count itself.
     *        NULL is a real answer and must survive to the client as null: the UI
     *        then shows the phase label with an indeterminate bar. It must never be
     *        coerced to 0, because a bar pinned at zero reads as stuck.
     */
    public function fraction($fraction, $detail = null)
    {
        if (!$this->doc) {
            return;
        }
        if ($fraction !== null) {
            $fraction = (float) $fraction;
            if ($fraction < 0) { $fraction = 0.0; }
            if ($fraction > 1) { $fraction = 1.0; }
        }
        $this->doc['phaseFraction'] = $fraction;
        if ($detail !== null) {
            $this->doc['phaseDetail'] = (string) $detail;
        }
        $this->recompute();
        $this->write();
    }

    /** Convenience for "N of M" counters. */
    public function count($done, $total, $noun = 'items')
    {
        if ($total > 0) {
            $this->fraction($done / $total, number_format($done) . ' of ' . number_format($total) . ' ' . $noun);
            return;
        }
        $this->fraction(null, number_format($done) . ' ' . $noun);
    }

    /** Touch updatedAt so the watchdog can tell a slow job from a dead one. */
    public function heartbeat()
    {
        if (!$this->doc) {
            return;
        }
        $this->write();
    }

    /**
     * Force the current state to disk, bypassing the write throttle. Use at a point
     * where the next update may be a long time coming — otherwise the throttle can
     * drop the final update of a phase and the reader sits on a stale fraction.
     */
    public function flush()
    {
        $this->write(true);
    }

    /**
     * A non-fatal problem. A restore that finishes without its voicemails is a
     * success the operator still has to be told about — today those failures are
     * swallowed by restoreCoreMedia and nobody ever learns.
     */
    public function warn($message, $phase = null)
    {
        if (!$this->doc) {
            return;
        }
        $warnings = isset($this->doc['warnings']) && is_array($this->doc['warnings'])
            ? $this->doc['warnings'] : [];
        if (count($warnings) < self::MAX_WARNINGS) {
            $warnings[] = [
                'phase'   => $phase !== null ? $phase : (isset($this->doc['phase']) ? $this->doc['phase'] : ''),
                'message' => self::clip($message),
                'at'      => self::stamp(time()),
            ];
            $this->doc['warnings'] = $warnings;
        }
        $this->write(true);
    }

    /** Terminal success. Written BEFORE any teardown, so the client can read it. */
    public function complete(array $summary = [])
    {
        if (!$this->doc) {
            return;
        }
        $this->doc['state']         = self::STATE_COMPLETED;
        $this->doc['percent']       = 100;
        $this->doc['phaseFraction'] = 1.0;
        $this->doc['etaSeconds']    = 0;
        $this->doc['finishedAt']    = self::stamp(time());
        if ($summary) {
            $this->doc['summary'] = $summary;
        }
        $this->write(true);
    }

    /**
     * Terminal failure, naming the phase it stopped in. Without this a failed
     * restore is indistinguishable from a successful one — the old code deleted its
     * Redis key in a `finally` and let the exception fly, so the UI announced
     * success and signed the operator out.
     */
    public function fail($code, $message, $phase = null)
    {
        if (!$this->doc) {
            return;
        }
        $this->doc['state']      = self::STATE_FAILED;
        $this->doc['finishedAt'] = self::stamp(time());
        $this->doc['failure']    = [
            'phase'   => $phase !== null ? $phase : (isset($this->doc['phase']) ? $this->doc['phase'] : ''),
            'code'    => (string) $code,
            'message' => self::clip($message),
        ];
        $this->write(true);
    }

    /**
     * Replace the cold-start weights with shares derived from real byte sizes, so a
     * 600 MB recordings archive is not given the same slice as a 2 MB images zip.
     * Phases absent from $bytes keep their table weight scaled into the remainder.
     */
    public function reweight(array $bytes)
    {
        $total = 0.0;
        foreach ($bytes as $phase => $n) {
            if (isset($this->weights[$phase]) && $n > 0) {
                $total += (float) $n;
            }
        }
        if ($total <= 0) {
            return;
        }

        // Sized phases share the weight they held between them; unsized phases keep
        // theirs untouched. Total weight is therefore preserved and percent cannot
        // jump merely because we learned a file size.
        $sizedWeight = 0.0;
        foreach ($bytes as $phase => $n) {
            if (isset($this->weights[$phase]) && $n > 0) {
                $sizedWeight += $this->weights[$phase];
            }
        }
        foreach ($bytes as $phase => $n) {
            if (isset($this->weights[$phase]) && $n > 0) {
                $this->weights[$phase] = $sizedWeight * ((float) $n / $total);
            }
        }

        $this->etaMayRise = true;
        $this->recompute();
        $this->write(true);
    }

    public function document()
    {
        return $this->doc;
    }

    /* ------------------------------------------------------------------ *
     * Reading (web tier)
     * ------------------------------------------------------------------ */

    /**
     * Read a job document for the API. Applies the watchdog, so a job whose worker
     * died is reported as failed rather than spinning forever.
     *
     * Distinguishes three absences that used to look identical:
     *   idle       — no job has run
     *   unreadable — a file exists but cannot be read or parsed (a permission
     *                regression must NOT be reported as "nothing has started")
     *   failed     — the worker died (watchdog verdict)
     */
    public static function read($job, $dir = null)
    {
        $progress = new self($job, $dir);
        $path     = $progress->path();

        if (!file_exists($path)) {
            return ['schema' => self::SCHEMA, 'job' => $progress->job, 'state' => self::STATE_IDLE];
        }

        $doc = self::readRaw($path);
        if (!is_array($doc) || !isset($doc['state'])) {
            return [
                'schema' => self::SCHEMA,
                'job'    => $progress->job,
                'state'  => self::STATE_UNREADABLE,
                'failure' => [
                    'phase'   => '',
                    'code'    => 'status_unreadable',
                    'message' => 'Job status file exists but could not be read.',
                ],
            ];
        }

        $reaped = self::reap($doc);
        if ($reaped !== null) {
            $progress->doc = $reaped;
            $progress->write(true);
            return $reaped;
        }

        return $doc;
    }

    /**
     * Watchdog. Returns the rewritten document when the job is dead, else null.
     *
     * BOTH conditions are required. A stale heartbeat alone would kill a healthy
     * pg_restore that simply stopped printing; a missing pid alone is ambiguous
     * during the container recreate a Docker PBX performs on every update.
     */
    public static function reap(array $doc)
    {
        if (!isset($doc['state']) || $doc['state'] !== self::STATE_RUNNING) {
            return null;
        }
        $updated = isset($doc['updatedAt']) ? strtotime($doc['updatedAt']) : 0;
        if ($updated <= 0 || (time() - $updated) < self::HEARTBEAT_STALE_SECONDS) {
            return null;
        }
        $pid = isset($doc['pid']) ? (int) $doc['pid'] : 0;
        if ($pid > 0 && self::pidAlive($pid)) {
            return null;
        }

        $doc['state']      = self::STATE_FAILED;
        $doc['finishedAt'] = self::stamp(time());
        $doc['failure']    = [
            'phase'   => isset($doc['phase']) ? $doc['phase'] : '',
            'code'    => 'worker_died',
            'message' => 'The job stopped unexpectedly and is no longer running on the server. '
                . 'Nothing further will happen until it is started again.',
        ];

        return $doc;
    }

    /**
     * /proc is checked FIRST and posix_kill only as a fallback, because
     * posix_kill($rootPid, 0) from www-data fails with EPERM — the process exists
     * and we are told "no". Trusting that would reap a perfectly healthy restore.
     * When neither mechanism is available the answer is "alive", because a false
     * "dead" destroys a running job's status and a false "alive" only delays a
     * verdict.
     */
    public static function pidAlive($pid)
    {
        if (is_dir('/proc')) {
            return file_exists('/proc/' . (int) $pid);
        }
        if (function_exists('posix_kill') && function_exists('posix_get_last_error')) {
            if (@posix_kill((int) $pid, 0)) {
                return true;
            }
            // EPERM (1) means it exists but belongs to someone else.
            return posix_get_last_error() === 1;
        }

        return true;
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    private function recompute()
    {
        $table = self::phaseTable($this->job);
        $keys  = array_keys($table);
        $index = isset($this->doc['phaseIndex']) ? (int) $this->doc['phaseIndex'] : 0;

        $total = 0.0;
        foreach ($this->weights as $w) {
            $total += $w;
        }
        if ($total <= 0) {
            return;
        }

        $done = 0.0;
        for ($i = 0; $i < $index; $i++) {
            $done += isset($this->weights[$keys[$i]]) ? $this->weights[$keys[$i]] : 0.0;
        }
        $current  = isset($this->weights[$keys[$index]]) ? $this->weights[$keys[$index]] : 0.0;
        $fraction = $this->doc['phaseFraction'];
        if ($fraction !== null) {
            $done += $current * (float) $fraction;
        }

        $percent = (int) floor(($done / $total) * 100);
        if ($percent > 99) {
            $percent = 99; // 100 belongs to complete() alone.
        }
        // Monotonic: a re-weight or a phase that reports a smaller fraction than its
        // predecessor must never drag the bar backwards.
        if (!isset($this->doc['percent']) || $percent > $this->doc['percent']) {
            $this->doc['percent'] = $percent;
        }

        $this->recomputeEta();
    }

    private function recomputeEta()
    {
        $percent = isset($this->doc['percent']) ? (int) $this->doc['percent'] : 0;
        $started = isset($this->doc['startedAt']) ? strtotime($this->doc['startedAt']) : 0;
        if ($percent < 5 || $started <= 0) {
            $this->doc['etaSeconds'] = null;
            return;
        }
        $elapsed = time() - $started;
        if ($elapsed <= 0) {
            return;
        }
        $eta = (int) round(($elapsed / $percent) * (100 - $percent));
        if ($eta < 5) {
            $eta = 5; // never claim "done" while still running
        }

        $previous = isset($this->doc['etaSeconds']) ? $this->doc['etaSeconds'] : null;
        if ($previous !== null && $eta > $previous && !$this->etaMayRise) {
            return; // clamped non-increasing
        }
        $this->etaMayRise       = false;
        $this->doc['etaSeconds'] = $eta;
    }

    private function write($force = false)
    {
        if (!$this->doc) {
            return;
        }
        $now = microtime(true);
        if (!$force && ($now - $this->lastWrite) < self::WRITE_INTERVAL_SECONDS) {
            return;
        }
        if (!$this->ensureDir()) {
            return;
        }

        $this->doc['updatedAt'] = self::stamp(time());
        $json = json_encode($this->doc);
        if ($json === false) {
            return;
        }

        $tmp = $this->path . '.tmp.' . (function_exists('getmypid') ? getmypid() : mt_rand());
        if (@file_put_contents($tmp, $json) === false) {
            return;
        }
        // Mode and group are applied to the TEMP file, so the document is readable
        // by the web tier from the instant it becomes visible. Doing it after the
        // rename leaves a window in which the reader gets a permission error, and
        // doing it only at creation loses the group on the very next write.
        $this->share($tmp);
        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            return;
        }
        $this->lastWrite = $now;
    }

    /** chmod 0640 + chgrp www-data, silent where the group does not exist. */
    private function share($path)
    {
        @chmod($path, 0640);
        if (function_exists('chgrp')) {
            @chgrp($path, self::WEB_GROUP);
        }
    }

    /**
     * 0770, NOT 0750 — and the difference is not cosmetic.
     *
     * BOTH tiers write here: the worker (root) publishes progress, and the web tier
     * (www-data) writes the `queued` document the instant an operator asks for a
     * job. At 0750 the group gets r-x, so PHP-FPM could READ documents but never
     * create one: the queued state silently vanished, the client was told the job
     * was `idle`, and it stopped polling two seconds before the worker began — the
     * import ran to completion with nobody watching and the bar sat at 40% forever.
     * An existing 0750 directory is repaired in place, so a host that took the
     * earlier build heals on the worker's next tick.
     */
    private function ensureDir()
    {
        if (is_dir($this->dir)) {
            // The MODE is inspected, never is_writable(): root passes is_writable()
            // whatever the mode says, so the only user able to repair the directory
            // is the one that can never notice it needs repairing. Same trap as
            // testing -x as root and concluding a service user can execute.
            $perms = @fileperms($this->dir);
            if ($perms !== false && ($perms & 0060) !== 0060) {
                @chmod($this->dir, 0770);
                if (function_exists('chgrp')) {
                    @chgrp($this->dir, self::WEB_GROUP);
                }
            }
            return is_writable($this->dir);
        }
        if (!@mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
            return false;
        }
        @chmod($this->dir, 0770);
        if (function_exists('chgrp')) {
            @chgrp($this->dir, self::WEB_GROUP);
        }

        return is_writable($this->dir);
    }

    private static function readRaw($path)
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $doc = json_decode($raw, true);

        return is_array($doc) ? $doc : null;
    }

    private static function stamp($time)
    {
        return gmdate('Y-m-d\TH:i:s\Z', $time);
    }

    private static function clip($message)
    {
        $message = trim((string) $message);
        if (strlen($message) > self::MAX_MESSAGE) {
            $message = substr($message, 0, self::MAX_MESSAGE) . '…';
        }

        return $message;
    }
}
