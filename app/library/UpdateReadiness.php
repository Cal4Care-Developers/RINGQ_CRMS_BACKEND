<?php

/**
 * Is this server ready for calls again after a central update?
 *
 * The update paths report "Successfully updated" as soon as the files are in
 * place and `pm2 restart all` has been fired, while the node services, the
 * call services and jobs.sh's restart_jobs block are all still coming back.
 * `cli.php main updateready` runs this in the background after every
 * successful update and records the real outcome once, so "updated" can mean
 * "ready for calls".
 *
 * READ ONLY. Every shell command is a literal with a timeout: a hung pm2 or
 * console must fail a check, not hang the run (165's update hung on
 * `pm2 jlist` on 2026-10-11).
 *
 * Text people see says "RingQ Call Services", never the engine's name.
 */
class UpdateReadiness
{
    /** Node services a call depends on. Others (chat, meeting...) are reported but don't block. */
    const CALL_SERVICES = ['server', 'signal', 'status', 'attended', 'queue'];

    const LAST_KEY = 'update_ready_last';
    /** The run in progress, rewritten on every pass, for the web's "Getting ready for calls" checklist. */
    const PROGRESS_KEY = 'update_ready_progress';
    const WEBHOOK_KEY = 'update_ready_webhook';
    const PUSH_KEY_FILE = '/var/www/ringq/node/secret.json';

    /**
     * Record where the current run is: state "starting" (before the first pass), "checking" or
     * "done". Kept a day; the web reads it with GET accounts/security/8.
     */
    public static function saveProgress($redis, array $record)
    {
        try {
            $redis->setex(self::PROGRESS_KEY, 86400, json_encode($record + ['updatedAt' => date('c')]));
        } catch (\Throwable $e) {
            error_log("updateready progress: " . $e->getMessage());
        }
    }

    /** The run in progress, else the last finished one, else null. */
    public static function progress($redis)
    {
        foreach ([self::PROGRESS_KEY, self::LAST_KEY] as $key) {
            $raw = $redis->get($key);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                return self::resumeIfStalled($redis, $decoded + ['state' => 'done']);
            }
        }
        return null;
    }

    /**
     * The background check writes a pass every few seconds. If a run says it is still going but
     * hasn't written for 20 s, it was stopped (a service restart during the update can do that):
     * run one pass here instead, and finish the run at its deadline, so the checklist never hangs.
     */
    private static function resumeIfStalled($redis, array $run)
    {
        if (($run['state'] ?? 'done') === 'done') {
            return $run;
        }
        $updated = strtotime((string) ($run['updatedAt'] ?? '')) ?: 0;
        if (time() - $updated < 20) {
            return $run;
        }
        require_once __DIR__ . '/ServiceHealth.php';
        $result = self::check($redis, (string) ($run['toVersion'] ?? ''));
        $deadline = strtotime((string) ($run['deadline'] ?? '')) ?: 0;
        $finished = $result['ready'] || time() >= $deadline;
        $run = array_merge($run, [
            'state' => $finished ? 'done' : 'checking',
            'ready' => $result['ready'],
            'checks' => $result['checks'],
        ]);
        if ($finished) {
            $run['message'] = self::message($result, (string) ($run['toVersion'] ?? ''), ServiceHealth::hostname());
            $run['at'] = date('c');
            try {
                $redis->setex(self::LAST_KEY, 30 * 86400, json_encode($run));
            } catch (\Throwable $e) {
                error_log("updateready last: " . $e->getMessage());
            }
        }
        self::saveProgress($redis, $run);
        return $run;
    }

    /** Whether $latest is a later release than $current ("9.2.0.47" > "9.2.0.39.1"); false when either is unreadable. */
    public static function isNewerVersion($latest, $current)
    {
        $latest = trim((string) $latest);
        $current = trim((string) $current);
        if (!preg_match('/^[0-9]+(\.[0-9]+)*$/', $latest) || !preg_match('/^[0-9]+(\.[0-9]+)*$/', $current)) {
            return false;
        }
        return version_compare($latest, $current, '>');
    }

    /** Start the check in the background; never blocks or fails the update response. */
    public static function spawn($runId, $trigger, $fromVersion, $toVersion)
    {
        $args = array_map('escapeshellarg', [
            (string) $runId, (string) $trigger, (string) $fromVersion, (string) $toVersion,
        ]);
        $cmd = '/usr/bin/php /var/www/ringq/app/cli.php main updateready ' . implode(' ', $args);
        // A new session so it outlives the php-fpm worker that started it. Where php-fpm runs
        // under systemd (KillMode=control-group) a restart of php-fpm during the update still
        // stops it (165, 2026-10-12, two minutes in); progress() then carries the run on.
        @exec('setsid nohup ' . $cmd . ' > /dev/null 2>&1 < /dev/null &');
    }

    /** One pass over every check. */
    public static function check($redis, $toVersion)
    {
        $checks = [];

        $version = ServiceHealth::serverVersion($redis);
        $checks[] = self::row('version', 'Version',
            $toVersion === '' || $version === $toVersion,
            $version === null ? 'unknown' : $version);

        $pm2 = self::pm2States();
        if ($pm2 === null) {
            $checks[] = self::row('services', 'RingQ services', false, 'service list did not answer');
        } else {
            $down = [];
            foreach (self::CALL_SERVICES as $name) {
                if (($pm2[$name] ?? 'absent') !== 'online') {
                    $down[] = $name . ' ' . ($pm2[$name] ?? 'absent');
                }
            }
            $checks[] = self::row('services', 'RingQ services', !$down, $down ? implode(', ', $down) : 'all online');
        }

        $sofia = self::cli('sofia status');
        $internal = $sofia !== null && preg_match('/^\s*internal\s+profile\s+\S+\s+RUNNING/m', $sofia);
        $checks[] = self::row('call_services', 'RingQ Call Services', (bool) $internal,
            $sofia === null ? 'not answering' : ($internal ? 'phones can register' : 'phone profile not running'));

        $ringq = self::cli('module_exists mod_ringq');
        $checks[] = self::row('ring_module', 'Ring module', $ringq !== null && trim($ringq) === 'true',
            $ringq === null ? 'not answering' : trim($ringq));

        $jobsPending = (int) $redis->exists('restart_jobs') + (int) $redis->exists('restart_lock');
        $checks[] = self::row('jobs', 'Post-update jobs', $jobsPending === 0, $jobsPending ? 'still running' : 'finished');

        $checks[] = self::row('push', 'Mobile push key', is_file(self::PUSH_KEY_FILE),
            is_file(self::PUSH_KEY_FILE) ? 'present' : 'missing');

        // Trunks are reported, not required: a server may have none, and a
        // provider being down is not something the update can fix.
        $trunks = self::trunksDown($sofia);
        $checks[] = self::row('trunks', 'Trunks', true, $trunks ? 'not registered: ' . implode(', ', $trunks) : 'ok', false);

        $ready = true;
        foreach ($checks as $c) {
            if ($c['blocking'] && !$c['ok']) {
                $ready = false;
            }
        }
        return ['ready' => $ready, 'checks' => $checks];
    }

    /** The one message people see. */
    public static function message(array $result, $toVersion, $host)
    {
        $where = $host ? " on $host" : '';
        $v = $toVersion !== '' ? " to $toVersion" : '';
        if ($result['ready']) {
            $text = "RingQ update$v complete$where. Ready for calls.";
        } else {
            $failed = [];
            foreach ($result['checks'] as $c) {
                if ($c['blocking'] && !$c['ok']) {
                    $failed[] = $c['label'] . ': ' . $c['detail'];
                }
            }
            $text = "RingQ update$v finished$where but is NOT ready for calls. " . implode('; ', $failed) . '.';
        }
        foreach ($result['checks'] as $c) {
            if ($c['key'] === 'trunks' && $c['detail'] !== 'ok') {
                $text .= ' Trunks ' . $c['detail'] . '.';
            }
        }
        return $text;
    }

    private static function row($key, $label, $ok, $detail, $blocking = true)
    {
        return ['key' => $key, 'label' => $label, 'ok' => (bool) $ok, 'detail' => (string) $detail, 'blocking' => $blocking];
    }

    private static function cli($command)
    {
        $raw = @shell_exec('timeout 10 /usr/bin/fs_cli -x ' . escapeshellarg($command) . ' 2>/dev/null');
        return is_string($raw) && trim($raw) !== '' ? $raw : null;
    }

    /** name => pm2 status, or null when pm2 can't be read in time. */
    private static function pm2States()
    {
        foreach (ServiceHealth::PM2_CANDIDATES as $binary) {
            if (!is_link($binary) && !is_file($binary)) {
                continue;
            }
            $raw = @shell_exec('timeout 15 sudo -n -H ' . escapeshellarg($binary) . ' jlist 2>/dev/null');
            $decoded = is_string($raw) ? ServiceHealth::decodePm2List($raw) : null;
            if ($decoded === null) {
                continue;
            }
            $states = [];
            foreach (ServiceHealth::pm2ByName($decoded, time()) as $name => $p) {
                $states[$name] = $p['state'];
            }
            return $states;
        }
        return null;
    }

    private static function trunksDown($sofia)
    {
        if ($sofia === null) {
            return [];
        }
        $down = [];
        foreach (preg_split('/\R/', $sofia) as $line) {
            $f = preg_split('/\s+/', trim($line));
            if (count($f) >= 4 && $f[1] === 'gateway' && in_array(end($f), ['FAIL_WAIT', 'FAILED', 'UNREGED', 'TRYING'], true)) {
                $down[] = preg_replace('/^.*::/', '', $f[0]);
            }
        }
        return $down;
    }
}
