<?php
/**
 * serviceJob.php — host service supervision.
 *
 * Lifted verbatim out of worker.php. The daemon still owns the SCHEDULE (every 30
 * seconds, at its call site in the loop); this file owns the work.
 *
 * Nothing here came from MainTask.php: the service concern has never had any code
 * there. The scattered systemctl/pm2 calls in MainTask's checkCertsAction,
 * autoupdateAction and the restore path were deliberately left where they are —
 * pulling them behind one abstraction is a separate change, and backup would be
 * its first real consumer.
 *
 * The sibling implementation is app/scripts/service_checker.sh, invoked from
 * app/scripts/jobs.sh. The two lists are not shared; if a service is added here it
 * should be added there too.
 *
 * Declares functions only. A top-level statement in this file would execute while
 * the worker is still wiring itself up, before $di and $redis exist.
 */

function serviceCheckerServices(): array {
    return [
        'freeswitch',
        'nginx',
        'php7.4-fpm',
        'php8.1-fpm',
        'calldetailrecord.timer',
        'postgresql',
        'redis-server',
        'memcached',
        'cron',
        'fail2ban',
    ];
}

function serviceCheckerUnits(array $services, string $postgresEtc = '/etc/postgresql'): array {
    $clusters = [];
    foreach (glob(rtrim($postgresEtc, '/') . '/*/*/start.conf') ?: [] as $conf) {
        $mode = '';
        foreach (preg_split('/\R/', (string) @file_get_contents($conf)) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                $mode = $line;
                break;
            }
        }
        $cluster = basename(dirname($conf));
        $version = basename(dirname(dirname($conf)));
        if ($mode === 'auto' && preg_match('/^[A-Za-z0-9._-]+$/', $version . $cluster)) {
            $clusters[] = 'postgresql@' . $version . '-' . $cluster;
        }
    }
    sort($clusters);
    $units = [];
    foreach ($services as $service) {
        if ($service === 'postgresql' && $clusters) {
            array_push($units, ...$clusters);
        } else {
            $units[] = $service;
        }
    }
    return $units;
}

function handleServiceChecker(?callable $read = null, ?callable $run = null, string $postgresEtc = '/etc/postgresql') {
    $read = $read ?? function (string $cmd): string { return trim((string) shell_exec($cmd)); };
    $run = $run ?? function (string $cmd): void { exec($cmd); };

    foreach (serviceCheckerUnits(serviceCheckerServices(), $postgresEtc) as $service) {
        $unit = escapeshellarg($service);
        if ($read("systemctl show -p LoadState --value $unit 2>/dev/null") !== 'loaded') {
            continue;
        }
        $status = $read("systemctl is-active $unit 2>/dev/null");
        if (in_array($status, ['active', 'activating', 'reloading'], true)) {
            continue;
        }
        $time = date('Y-m-d H:i:s');
        echo "[$time] [WARN] Service '$service' is $status. Attempting restart...\n";

        if (strpos($service, 'postgresql@') !== 0) {
            $run("sudo -n systemctl enable $unit > /dev/null 2>&1 &");
        }
        $run("sudo -n systemctl start $unit > /dev/null 2>&1 &");
    }
}
