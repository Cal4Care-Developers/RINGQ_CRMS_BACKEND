<?php
//   php update-health.php <since-epoch>   one JSON line: cron (cli.php) errors from the cron mail spool,
//                                         PHP fatal errors and ACL refusals from the web logs, all since <since-epoch>
// Read as root (the spool and the php-fpm logs are root-only). Always exits 0.

function uhSystemZone(): string
{
    $zone = trim((string) @file_get_contents(getenv('RINGQ_TIMEZONE_FILE') ?: '/etc/timezone'));
    if ($zone === '') {
        $link = (string) @readlink(getenv('RINGQ_LOCALTIME') ?: '/etc/localtime');
        if (preg_match('#zoneinfo/(.+)$#', $link, $m)) {
            $zone = $m[1];
        }
    }
    return in_array($zone, timezone_identifiers_list(), true) ? $zone : 'UTC';
}

function uhTail(string $file, int $bytes): array
{
    $size = @filesize($file);
    $handle = @fopen($file, 'rb');
    if ($handle === false || $size === false) {
        return [];
    }
    if ($size > $bytes) {
        fseek($handle, $size - $bytes);
        fgets($handle);
    }
    $lines = [];
    while (($line = fgets($handle)) !== false) {
        $lines[] = rtrim($line, "\r\n");
    }
    fclose($handle);
    return $lines;
}

function uhMask(string $line): string
{
    $line = preg_replace('/(password|passwd|pwd)([=:]\s*)[^\s;,"\']+/i', '$1$2***', $line);
    return mb_substr($line, 0, 300);
}

function uhCron(array $files, int $since): array
{
    $count = 0;
    $last = [];
    foreach ($files as $file) {
        $at = 0;
        foreach (uhTail($file, 8000000) as $line) {
            if (preg_match('/^Date:\s*(.+)$/', $line, $m)) {
                $at = (int) strtotime($m[1]);
                continue;
            }
            if ($at >= $since && preg_match('/PHP (Fatal|Parse) error|SQLSTATE\[|Uncaught /', $line)) {
                $count++;
                $last[] = uhMask(trim($line));
            }
        }
    }
    return ['errors' => $count, 'last' => array_slice($last, -3)];
}

function uhLineTime(string $line): int
{
    if (preg_match('#^(\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2})#', $line, $m)) {
        return (int) strtotime(str_replace('/', '-', $m[1]));
    }
    if (preg_match('#^\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2})#', $line, $m)) {
        return (int) strtotime($m[1]);
    }
    return 0;
}

function uhWeb(array $files, int $since): array
{
    $fatal = 0;
    $last = [];
    $refused = [];
    foreach ($files as $file) {
        foreach (uhTail($file, 4000000) as $line) {
            $at = uhLineTime($line);
            if ($at < $since) {
                continue;
            }
            if (preg_match('/PHP (Fatal|Parse) error/', $line)) {
                $fatal++;
                $last[] = uhMask(trim($line));
            }
            if (preg_match('#\[acl\] refused (registered|nonregistered) ([A-Za-z0-9_]+/[A-Za-z0-9_]+)#', $line, $m)) {
                $key = $m[2] . ' (' . ($m[1] === 'registered' ? 'signed in' : 'signed out') . ')';
                $refused[$key] = ($refused[$key] ?? 0) + 1;
            }
        }
    }
    arsort($refused);
    return ['fatal' => $fatal, 'last' => array_slice($last, -3), 'aclRefused' => array_slice($refused, 0, 10, true)];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    date_default_timezone_set(uhSystemZone());
    $since = (int) ($argv[1] ?? 0);
    if ($since <= 0) {
        $since = time() - 86400;
    }
    $mail = glob((getenv('RINGQ_MAIL_DIR') ?: '/var/mail') . '/*') ?: [];
    $web = getenv('RINGQ_WEB_LOGS') !== false
        ? array_filter(explode(':', (string) getenv('RINGQ_WEB_LOGS')))
        : array_merge(['/var/log/nginx/error.log'], glob('/var/log/php*-fpm.log') ?: []);
    echo json_encode(['ok' => true, 'zone' => date_default_timezone_get(), 'since' => $since, 'cron' => uhCron($mail, $since), 'web' => uhWeb($web, $since)]), "\n";
}
