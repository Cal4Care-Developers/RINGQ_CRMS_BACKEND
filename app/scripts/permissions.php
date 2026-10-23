<?php
// permissions.php status [area,...] | fix <id> [<id>...] | rollback <undo-id>
// Runs as root (sudo from the app). Checks and repairs only the entries of the rule list below; never a free path.

const PERM_LOG = '/var/log/ringq-permissions.log';
const PERM_UNDO_DIR = '/var/lib/ringq/permission-undo';
const PERM_LOCK = '/run/ringq-permissions.lock';
const PERM_TREE_CAP = 5000;
const PERM_LOG_TAIL = 1048576;

function permRules(): array
{
    $file = getenv('RINGQ_PERM_RULES');
    if ($file !== false && $file !== '' && is_file($file)) {
        $rules = json_decode((string) file_get_contents($file), true);
        return is_array($rules) ? $rules : [];
    }
    return [
        ['id' => 'etc-ringq', 'label' => 'RingQ settings folder', 'kind' => 'traverse', 'paths' => ['/etc/ringq'], 'user' => 'www-data', 'group' => 'www-data',
            'why' => 'FreeSWITCH (www-data) reads /etc/ringq/config.conf. Without it every SIP profile fails to load and nothing registers.'],
        ['id' => 'etc-ringq-config', 'label' => 'RingQ settings file', 'kind' => 'readable', 'paths' => ['/etc/ringq/config.conf'], 'user' => 'www-data', 'optional' => true,
            'why' => 'FreeSWITCH reads it for its database connection.'],
        ['id' => 'etc-ringq-secrets', 'label' => 'RingQ secret files', 'kind' => 'secret', 'paths' => ['/etc/ringq/*.env', '/etc/ringq/local-installation-id', '/etc/ringq/*.key'], 'owner' => 'root', 'max' => 0600, 'optional' => true,
            'why' => 'They hold the meeting service passwords and keys; nobody but root may read them.'],
        ['id' => 'etc-ringq-env', 'label' => 'RingQ environment file', 'kind' => 'secret', 'paths' => ['/etc/ringq/.env'], 'owner' => 'root', 'group' => 'www-data', 'max' => 0640, 'optional' => true,
            'why' => 'It holds the database password and the TURN secret. Root and the app (www-data, which reads the TURN ports from it) may read it, nobody else.'],
        ['id' => 'etc-ringq-env-app', 'label' => 'RingQ environment file for the app', 'kind' => 'readable', 'paths' => ['/etc/ringq/.env'], 'user' => 'www-data', 'group' => 'www-data', 'optional' => true,
            'why' => 'The Ports page reads the TURN ports from it.'],
        ['id' => 'etc-ringq-pki', 'label' => 'Meet internal certificates', 'kind' => 'secret', 'paths' => ['/etc/ringq/pki'], 'owner' => 'root', 'max' => 0750, 'optional' => true,
            'why' => 'Private keys of the meeting service.'],
        ['id' => 'jobs-progress', 'label' => 'Backup and restore progress folder', 'kind' => 'dir', 'paths' => ['/var/lib/ringq/jobs'], 'owner' => 'root', 'group' => 'www-data', 'mode' => 0770, 'create' => true,
            'why' => 'The worker (root) and the app (www-data) both write progress here; without it a backup, restore or import looks stuck.'],
        ['id' => 'meet-state', 'label' => 'Meet setup status folder', 'kind' => 'traverse', 'paths' => ['/var/lib/ringq-meet'], 'user' => 'www-data', 'group' => 'www-data', 'optional' => true,
            'when' => ['/var/lib/ringq-meet/local-progress.json', '/var/lib/ringq-meet/local-result.json'],
            'why' => 'The app reads the meeting setup status here.'],
        ['id' => 'meet-state-files', 'label' => 'Meet setup status files', 'kind' => 'readable', 'paths' => ['/var/lib/ringq-meet/local-progress.json', '/var/lib/ringq-meet/local-result.json'], 'user' => 'www-data', 'optional' => true,
            'why' => 'Unreadable, the Meet window shows "status unreadable".'],
        ['id' => 'fax-spool', 'label' => 'Fax spool', 'kind' => 'traverse', 'paths' => ['/var/spool/fax'], 'user' => 'www-data', 'group' => 'www-data', 'optional' => true,
            'why' => 'The fax folders below must be reachable.'],
        ['id' => 'fax-folders', 'label' => 'Fax folders', 'kind' => 'dir', 'paths' => ['/var/spool/fax/outbound_pdf', '/var/spool/fax/outbound_tiff', '/var/spool/fax/inbound_tiff', '/var/spool/fax/inbox'], 'owner' => 'www-data', 'group' => 'www-data', 'mode' => 0755, 'create' => true,
            'why' => 'The app and FreeSWITCH write faxes here; otherwise sending fails and received faxes are lost.'],
        ['id' => 'csv-exports', 'label' => 'CSV export folders', 'kind' => 'dir', 'paths' => ['/var/www/ringq/public/extension_csv', '/var/www/ringq/public/did_number_csv', '/var/www/ringq/public/outbound_rules_csv', '/var/www/ringq/public/trunk_csv'], 'owner' => 'www-data', 'group' => 'www-data', 'mode' => 0755, 'create' => true,
            'why' => 'The app writes CSV exports and nginx serves them; otherwise the download fails or returns 403.'],
        ['id' => 'recordings', 'label' => 'Call recordings', 'kind' => 'tree', 'paths' => ['/var/lib/freeswitch/recordings'], 'owner' => 'www-data', 'group' => 'www-data', 'optional' => true,
            'why' => 'FreeSWITCH writes recordings and the app reads, backs up and deletes them.'],
        ['id' => 'voicemail', 'label' => 'Voicemail', 'kind' => 'tree', 'paths' => ['/var/lib/freeswitch/storage/voicemail'], 'owner' => 'www-data', 'group' => 'www-data', 'optional' => true,
            'why' => 'FreeSWITCH writes voicemail and the app plays and backs it up.'],
        ['id' => 'avatars', 'label' => 'Profile pictures', 'kind' => 'tree', 'paths' => ['/var/www/ringq/public/avr'], 'owner' => 'www-data', 'group' => 'www-data', 'optional' => true,
            'why' => 'The app saves profile pictures here.'],
        ['id' => 'uploads', 'label' => 'Chat and meeting uploads', 'kind' => 'tree', 'paths' => ['/var/www/ringq/public/meet_chat/attachments', '/var/www/ringq/public/meet_whiteboards', '/var/www/ringq/public/meet_recordings', '/var/www/webapp/chat_file_upload', '/var/www/webapp/passes'], 'owner' => 'www-data', 'group' => 'www-data', 'optional' => true,
            'why' => 'The app saves chat files, meeting files and wallet passes here.'],
        ['id' => 'migrations', 'label' => 'Backup and import staging', 'kind' => 'tree', 'paths' => ['/var/www/ringq/app/migrations'], 'owner' => 'www-data', 'group' => 'www-data', 'optional' => true,
            'why' => 'Backups and imports are unpacked here by the app.'],
        ['id' => 'pcap', 'label' => 'Packet capture folder', 'kind' => 'dir', 'paths' => ['/var/pcap'], 'owner' => 'www-data', 'group' => 'www-data', 'mode' => 0755, 'optional' => true,
            'why' => 'SIP traces started from the app are written here.'],
        ['id' => 'web-roots', 'label' => 'Web app files', 'kind' => 'webroot', 'paths' => ['/var/www/webapp'], 'optional' => true,
            'why' => 'nginx (www-data) serves them; a file it cannot read answers 403 and breaks the page.'],
        ['id' => 'fs-tls', 'label' => 'FreeSWITCH TLS bundle', 'kind' => 'readable', 'paths' => ['/etc/freeswitch/tls/all.pem'], 'user' => 'www-data', 'optional' => true,
            'why' => 'FreeSWITCH (www-data) loads it for SIP over TLS and the web phone (WSS 7443).'],
        ['id' => 'scripts', 'label' => 'RingQ scripts', 'kind' => 'exec', 'paths' => ['/var/www/ringq/app/scripts/startup.sh', '/var/www/ringq/app/scripts/update_version.sh', '/var/www/ringq/app/scripts/sipguard.sh', '/var/www/ringq/app/scripts/jobs.sh', '/var/www/ringq/app/scripts/service_checker.sh', '/var/www/ringq/app/scripts/fs-inventory.sh', '/var/www/ringq/app/scripts/pbx-setup.sh', '/var/www/ringq/app/scripts/ringq-meet-reconcile', '/var/www/ringq/app/scripts/ringq-precompress-web', '/var/www/ringq/app/scripts/ringq-meet-nginx-retrofit.py'], 'optional' => true,
            'why' => 'Cron and updates run these directly (not through bash), so without the run permission they silently do nothing; the update transport can drop it.'],
        ['id' => 'sudoers', 'label' => 'sudo rules', 'kind' => 'secret', 'paths' => ['/etc/sudoers.d/*'], 'owner' => 'root', 'max' => 0440, 'optional' => true,
            'why' => 'sudo ignores a rule file anyone else can write, and the app runs its repairs through sudo.'],
    ];
}

function permRoot(): string
{
    $root = getenv('RINGQ_PERM_ROOT');
    return $root === false ? '' : rtrim($root, '/');
}

function permPaths(array $rule): array
{
    $out = [];
    foreach ((array) ($rule['paths'] ?? []) as $p) {
        $full = permRoot() . $p;
        if (strpbrk($p, '*?[') !== false) {
            foreach (glob($full) ?: [] as $g) {
                $out[] = $g;
            }
        } else {
            $out[] = $full;
        }
    }
    return array_values(array_unique($out));
}

function permShow(string $path): string
{
    $root = permRoot();
    return $root !== '' && strpos($path, $root) === 0 ? substr($path, strlen($root)) : $path;
}

function permUser(string $name): ?array
{
    static $cache = [];
    if (array_key_exists($name, $cache)) {
        return $cache[$name];
    }
    $pw = function_exists('posix_getpwnam') ? posix_getpwnam($name) : false;
    if (!$pw) {
        return $cache[$name] = null;
    }
    $groups = [(int) $pw['gid']];
    $ids = trim((string) @shell_exec('id -G ' . escapeshellarg($name) . ' 2>/dev/null'));
    foreach (preg_split('/\s+/', $ids) ?: [] as $g) {
        if ($g !== '' && ctype_digit($g)) {
            $groups[] = (int) $g;
        }
    }
    return $cache[$name] = ['name' => $name, 'uid' => (int) $pw['uid'], 'gid' => (int) $pw['gid'], 'groups' => array_values(array_unique($groups))];
}

function permGid(string $name): ?int
{
    $gr = function_exists('posix_getgrnam') ? posix_getgrnam($name) : false;
    return $gr ? (int) $gr['gid'] : null;
}

function permNameOfUid(int $uid): string
{
    $pw = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;
    return $pw ? (string) $pw['name'] : (string) $uid;
}

function permNameOfGid(int $gid): string
{
    $gr = function_exists('posix_getgrgid') ? posix_getgrgid($gid) : false;
    return $gr ? (string) $gr['name'] : (string) $gid;
}

function permStat(string $path): ?array
{
    clearstatcache(true, $path);
    $s = @lstat($path);
    if ($s === false) {
        return null;
    }
    return ['uid' => (int) $s['uid'], 'gid' => (int) $s['gid'], 'mode' => (int) $s['mode'] & 07777, 'type' => (int) $s['mode'] & 0170000];
}

function permDescribe(?array $st): string
{
    if ($st === null) {
        return 'missing';
    }
    return permNameOfUid($st['uid']) . ':' . permNameOfGid($st['gid']) . ' ' . sprintf('%04o', $st['mode']);
}

function permBits(array $st, array $user, string $want): bool
{
    if ($user['uid'] === 0) {
        return true;
    }
    $map = ['r' => 4, 'w' => 2, 'x' => 1];
    $bit = $map[$want];
    if ($st['uid'] === $user['uid']) {
        return (bool) (($st['mode'] >> 6) & $bit);
    }
    if (in_array($st['gid'], $user['groups'], true)) {
        return (bool) (($st['mode'] >> 3) & $bit);
    }
    return (bool) ($st['mode'] & $bit);
}

function permCanReach(string $path, array $user): ?string
{
    $root = permRoot();
    $rel = $root !== '' && strpos($path, $root . '/') === 0 ? substr($path, strlen($root)) : $path;
    $parts = explode('/', trim($rel, '/'));
    array_pop($parts);
    $cur = $root;
    foreach ($parts as $part) {
        $cur .= '/' . $part;
        $st = @stat($cur);
        if ($st === false) {
            return permShow($cur) . ' is missing';
        }
        if (!permBits(['uid' => (int) $st['uid'], 'gid' => (int) $st['gid'], 'mode' => (int) $st['mode'] & 07777], $user, 'x')) {
            return $user['name'] . ' cannot pass through ' . permShow($cur);
        }
    }
    return null;
}

function permProbe(string $user, string $test, string $path): ?bool
{
    if (getenv('RINGQ_PERM_ROOT') !== false || !function_exists('posix_geteuid') || posix_geteuid() !== 0 || !is_executable('/usr/sbin/runuser')) {
        return null;
    }
    $rc = 1;
    @exec('/usr/sbin/runuser -u ' . escapeshellarg($user) . ' -- /usr/bin/test ' . $test . ' ' . escapeshellarg($path) . ' 2>/dev/null', $o, $rc);
    return $rc === 0;
}

function permAction(string $op, string $path, $value, ?array $before): array
{
    return ['op' => $op, 'path' => $path, 'value' => $value, 'before' => $before];
}

function permCheckRule(array $rule): array
{
    $kind = (string) $rule['kind'];
    $problems = [];
    $actions = [];
    $paths = permPaths($rule);
    if (isset($rule['when']) && permPaths(['paths' => $rule['when']]) !== [] && count(array_filter(permPaths(['paths' => $rule['when']]), 'file_exists')) === 0) {
        $paths = [];
    }
    $seen = 0;
    foreach ($paths as $path) {
        $st = permStat($path);
        if ($st === null) {
            if (!empty($rule['create']) && $kind === 'dir') {
                $problems[] = permShow($path) . ' is missing.';
                $actions[] = permAction('mkdir', $path, (int) $rule['mode'], null);
                $owner = permUser((string) $rule['owner']);
                if ($owner) {
                    $actions[] = permAction('chown', $path, $owner['uid'], null);
                }
                if (!empty($rule['group']) && permGid((string) $rule['group']) !== null) {
                    $actions[] = permAction('chgrp', $path, permGid((string) $rule['group']), null);
                }
                $actions[] = permAction('chmod', $path, (int) $rule['mode'], null);
            }
            continue;
        }
        $seen++;
        if ($st['type'] === 0120000) {
            continue;
        }
        if ($kind === 'dir') {
            $owner = permUser((string) $rule['owner']);
            $gid = isset($rule['group']) ? permGid((string) $rule['group']) : null;
            if ($owner && $st['uid'] !== $owner['uid']) {
                $problems[] = permShow($path) . ' is owned by ' . permNameOfUid($st['uid']) . ', should be ' . $rule['owner'] . '.';
                $actions[] = permAction('chown', $path, $owner['uid'], $st);
            }
            if ($gid !== null && $st['gid'] !== $gid) {
                $problems[] = permShow($path) . ' has group ' . permNameOfGid($st['gid']) . ', should be ' . $rule['group'] . '.';
                $actions[] = permAction('chgrp', $path, $gid, $st);
            }
            if ($st['mode'] !== (int) $rule['mode']) {
                $problems[] = permShow($path) . ' has mode ' . sprintf('%04o', $st['mode']) . ', should be ' . sprintf('%04o', $rule['mode']) . '.';
                $actions[] = permAction('chmod', $path, (int) $rule['mode'], $st);
            }
        } elseif ($kind === 'traverse' || $kind === 'readable') {
            $user = permUser((string) $rule['user']);
            if (!$user) {
                continue;
            }
            $want = $kind === 'traverse' ? 'x' : 'r';
            $blocked = permCanReach($path, $user);
            if ($blocked !== null) {
                $problems[] = $blocked . ', so it cannot reach ' . permShow($path) . '. Fix the folder entries above it first.';
                continue;
            }
            $probe = permProbe($user['name'], $want === 'x' ? '-x' : '-r', $path);
            $ok = $probe !== null ? $probe : permBits($st, $user, $want);
            if (!$ok) {
                $problems[] = $user['name'] . ' cannot ' . ($want === 'x' ? 'pass through ' : 'read ') . permShow($path) . ' (' . permDescribe($st) . ').';
                $gid = permGid((string) ($rule['group'] ?? $rule['user']));
                if ($gid === null) {
                    $gid = $user['gid'];
                }
                if ($st['uid'] !== $user['uid'] && $st['gid'] !== $gid && !in_array($st['gid'], $user['groups'], true)) {
                    $actions[] = permAction('chgrp', $path, $gid, $st);
                }
                $add = $want === 'x' ? 050 : 040;
                if ($st['uid'] === $user['uid']) {
                    $add = $want === 'x' ? 0500 : 0400;
                }
                $actions[] = permAction('chmod', $path, $st['mode'] | $add, $st);
            }
        } elseif ($kind === 'secret') {
            $owner = permUser((string) $rule['owner']);
            $max = (int) $rule['max'];
            if ($owner && $st['uid'] !== $owner['uid']) {
                $problems[] = permShow($path) . ' is owned by ' . permNameOfUid($st['uid']) . ', should be ' . $rule['owner'] . '.';
                $actions[] = permAction('chown', $path, $owner['uid'], $st);
            }
            $gid = isset($rule['group']) ? permGid((string) $rule['group']) : null;
            if ($gid !== null && $st['gid'] !== $gid) {
                $problems[] = permShow($path) . ' has group ' . permNameOfGid($st['gid']) . ', should be ' . $rule['group'] . '.';
                $actions[] = permAction('chgrp', $path, $gid, $st);
            }
            if (($st['mode'] & ~$max & 0777) !== 0) {
                $problems[] = permShow($path) . ' has mode ' . sprintf('%04o', $st['mode']) . ', more open than ' . sprintf('%04o', $max) . '.';
                $actions[] = permAction('chmod', $path, $st['mode'] & $max, $st);
            }
        } elseif ($kind === 'exec') {
            if ($st['type'] === 0100000 && ($st['mode'] & 0100) === 0) {
                $problems[] = permShow($path) . ' cannot be run (' . sprintf('%04o', $st['mode']) . ').';
                $actions[] = permAction('chmod', $path, $st['mode'] | 0755, $st);
            }
        } elseif ($kind === 'tree' || $kind === 'webroot') {
            $found = permScanTree($path, $rule, $problems, $actions);
            if ($found >= PERM_TREE_CAP) {
                $problems[] = 'More than ' . PERM_TREE_CAP . ' entries under ' . permShow($path) . ' need fixing; one repair fixes the first ' . PERM_TREE_CAP . ', run it again for the rest.';
            }
        }
    }
    if ($seen === 0 && empty($rule['optional']) && empty($rule['create']) && $paths !== []) {
        $problems[] = 'Not found: ' . implode(', ', array_map('permShow', $paths)) . '.';
    }
    return ['problems' => $problems, 'actions' => $actions];
}

function permScanTree(string $top, array $rule, array &$problems, array &$actions): int
{
    $owner = isset($rule['owner']) ? permUser((string) $rule['owner']) : null;
    $gid = isset($rule['group']) ? permGid((string) $rule['group']) : null;
    $count = 0;
    $wrongOwner = 0;
    $unreadable = 0;
    $stack = [$top];
    while ($stack !== [] && $count < PERM_TREE_CAP) {
        $path = array_pop($stack);
        $st = permStat($path);
        if ($st === null || $st['type'] === 0120000) {
            continue;
        }
        $isDir = $st['type'] === 0040000;
        $fix = false;
        if ($rule['kind'] === 'tree') {
            $usable = $owner && permBits($st, $owner, 'r') && (!$isDir || (permBits($st, $owner, 'w') && permBits($st, $owner, 'x')));
            if ($owner && !$usable) {
                $fix = true;
                if ($st['uid'] !== $owner['uid']) {
                    $actions[] = permAction('chown', $path, $owner['uid'], $st);
                    $wrongOwner++;
                }
                if ($gid !== null && $st['gid'] !== $gid) {
                    $actions[] = permAction('chgrp', $path, $gid, $st);
                }
                $need = $isDir ? 0700 : 0600;
                if (($st['mode'] & $need) !== $need) {
                    $actions[] = permAction('chmod', $path, $st['mode'] | $need, $st);
                }
            }
        } else {
            $need = $isDir ? 05 : 04;
            if (($st['mode'] & $need) !== $need) {
                $actions[] = permAction('chmod', $path, $st['mode'] | ($isDir ? 0555 : 0444), $st);
                $unreadable++;
                $fix = true;
            }
        }
        if ($fix) {
            $count++;
        }
        if ($isDir) {
            foreach (@scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    $stack[] = $path . '/' . $name;
                }
            }
        }
    }
    if ($rule['kind'] === 'tree' && $count > 0) {
        $problems[] = $count . ' entr' . ($count === 1 ? 'y' : 'ies') . ' under ' . permShow($top) . ' cannot be used by ' . $rule['owner'] . ' (a folder it cannot write into, or a file it cannot read)' . ($wrongOwner > 0 ? '; ' . $wrongOwner . ' of them belong to another user' : '') . '.';
    }
    if ($rule['kind'] === 'webroot' && $unreadable > 0) {
        $problems[] = $unreadable . ' entr' . ($unreadable === 1 ? 'y' : 'ies') . ' under ' . permShow($top) . ' cannot be read by nginx, so those files answer 403.';
    }
    return $count;
}

function permRuleById(string $id): ?array
{
    foreach (permRules() as $rule) {
        if ($rule['id'] === $id) {
            return $rule;
        }
    }
    return null;
}

function permFiles(): array
{
    $out = [];
    foreach (permRules() as $rule) {
        $check = permCheckRule($rule);
        $out[] = [
            'id' => $rule['id'],
            'label' => $rule['label'],
            'kind' => $rule['kind'],
            'paths' => array_map('permShow', permPaths($rule) ?: array_map(function ($p) { return $p; }, (array) $rule['paths'])),
            'ok' => $check['problems'] === [],
            'problems' => $check['problems'],
            'changes' => count($check['actions']),
            'why' => $rule['why'],
        ];
    }
    return $out;
}

function permRun(string $cmd): string
{
    return trim((string) @shell_exec($cmd . ' 2>/dev/null'));
}

function permServices(): array
{
    $expect = [
        ['name' => 'FreeSWITCH', 'match' => '/^freeswitch$/', 'user' => 'www-data'],
        ['name' => 'nginx workers', 'match' => '/^nginx$/', 'user' => 'www-data', 'skipUser' => 'root'],
        ['name' => 'PHP-FPM workers', 'match' => '/^php-fpm/', 'user' => 'www-data', 'skipUser' => 'root'],
        ['name' => 'Redis', 'match' => '/^redis-server$/', 'user' => 'redis'],
        ['name' => 'PostgreSQL', 'match' => '/^postgres$/', 'user' => 'postgres'],
        ['name' => 'Background worker', 'match' => '/jobs\/worker\.php/', 'user' => 'www-data', 'args' => true],
    ];
    $ps = permRun('ps -eo user:32=,comm=,args=');
    $out = [];
    foreach ($expect as $e) {
        $users = [];
        foreach (preg_split('/\n/', $ps) ?: [] as $line) {
            $cols = preg_split('/\s+/', trim($line), 3);
            if (count($cols) < 2) {
                continue;
            }
            $subject = !empty($e['args']) ? ($cols[2] ?? '') : $cols[1];
            if (preg_match($e['match'], $subject) && (!isset($e['skipUser']) || $cols[0] !== $e['skipUser'])) {
                $users[$cols[0]] = true;
            }
        }
        $actual = array_keys($users);
        $out[] = [
            'service' => $e['name'],
            'expected' => $e['user'],
            'running' => $actual !== [],
            'users' => $actual,
            'ok' => $actual === [] || $actual === [$e['user']],
            'note' => $actual === [] ? 'not running' : ($actual === [$e['user']] ? '' : 'runs as ' . implode(', ', $actual) . '; RingQ expects ' . $e['user'] . '. Files it creates get the wrong owner. Not changed automatically.'),
        ];
    }
    return $out;
}

function permSockets(): array
{
    $out = [];
    $ping = permRun('redis-cli -h 127.0.0.1 -p 6379 ping');
    $out[] = [
        'socket' => 'TCP 127.0.0.1:6379', 'service' => 'Redis', 'client' => 'the app (www-data)',
        'state' => $ping === '' ? 'no answer' : $ping, 'ok' => $ping === 'PONG',
        'note' => $ping === 'PONG' ? '' : 'The app reaches Redis on 127.0.0.1:6379 and it did not answer PONG (' . ($ping === '' ? 'no answer' : $ping) . '). This is a service problem, not a permission. Not changed automatically.',
    ];
    $www = permUser('www-data');
    foreach (glob('/run/php/php*-fpm.sock') ?: [] as $fpm) {
        $st = permStat(realpath($fpm) ?: $fpm);
        $ok = $st !== null && $www !== null && permBits($st, $www, 'w') && permBits($st, $www, 'r');
        $out[] = ['socket' => $fpm, 'service' => 'PHP-FPM', 'client' => 'www-data (nginx)', 'state' => permDescribe($st), 'ok' => $ok,
            'note' => $ok ? '' : 'nginx cannot use this PHP-FPM socket, so pages on it answer 502. Check listen.owner / listen.mode in the pool. Not changed automatically.'];
    }
    $esl = permRun("ss -ltn 'sport = :8021' | tail -n +2");
    $out[] = ['socket' => 'TCP 8021', 'service' => 'FreeSWITCH event socket', 'client' => 'the app and node services', 'state' => $esl === '' ? 'not listening' : 'listening', 'ok' => $esl !== '',
        'note' => $esl === '' ? 'The event socket is not listening; calls cannot be controlled from the app.' : ''];
    return $out;
}

function permCron(): array
{
    $tab = permRun('crontab -l -u root');
    $lines = $tab === '' ? 0 : substr_count($tab, 'cli.php');
    $active = permRun('systemctl is-active cron');
    return [
        'ok' => $lines > 0 && ($active === 'active' || $active === ''),
        'rootJobs' => $lines,
        'cron' => $active === '' ? 'unknown' : $active,
        'note' => $lines === 0 ? 'The root crontab has no RingQ jobs; scheduled work (reports, CDR import, backups) does not run.' : ($active !== '' && $active !== 'active' ? 'The cron service is ' . $active . '.' : ''),
    ];
}

function permSecurity(): array
{
    $apparmor = permRun('cat /sys/module/apparmor/parameters/enabled');
    $selinux = permRun('getenforce');
    $denials = permRun("journalctl -k --since '-24h' --no-pager 2>/dev/null | grep -F 'apparmor=\"DENIED\"' | tail -n 5");
    $count = $denials === '' ? 0 : count(explode("\n", $denials));
    return [
        'ok' => $count === 0 && strtolower($selinux) !== 'enforcing',
        'apparmor' => $apparmor === 'Y' ? 'enabled' : ($apparmor === '' ? 'not present' : 'disabled'),
        'selinux' => $selinux === '' ? 'not present' : $selinux,
        'recentDenials' => $count === 0 ? [] : array_map(function ($l) { return mb_substr($l, 0, 300); }, explode("\n", $denials)),
        'note' => $count > 0 ? 'AppArmor refused access in the last 24 hours even where file permissions allow it. Policy is not changed automatically.' : (strtolower($selinux) === 'enforcing' ? 'SELinux is enforcing; RingQ is not built for it.' : ''),
    ];
}

function permLogs(array $rules): array
{
    $files = array_merge(
        ['/var/log/nginx/error.log', '/var/log/freeswitch/freeswitch.log', '/var/log/php7.4-fpm.log', '/var/log/php8.1-fpm.log', '/var/log/ringq-worker.log', '/var/mail/root', '/var/mail/mail'],
        glob('/root/.pm2/logs/*-error.log') ?: []
    );
    $pattern = '/Permission denied|EACCES|Operation not permitted|permission denied for (table|relation|sequence|schema|database)|must be owner of/i';
    $hits = [];
    foreach ($files as $file) {
        if (!is_file($file) || !is_readable($file)) {
            continue;
        }
        $size = (int) @filesize($file);
        $fh = @fopen($file, 'r');
        if (!$fh) {
            continue;
        }
        if ($size > PERM_LOG_TAIL) {
            fseek($fh, $size - PERM_LOG_TAIL);
            fgets($fh);
        }
        while (($line = fgets($fh)) !== false) {
            if (preg_match($pattern, $line)) {
                $hits[] = ['log' => $file, 'line' => mb_substr(trim($line), 0, 400)];
                if (count($hits) > 400) {
                    array_shift($hits);
                }
            }
        }
        fclose($fh);
    }
    $out = [];
    foreach (array_slice($hits, -25) as $hit) {
        $hit['entry'] = permLogEntry($hit['line'], $rules);
        $out[] = $hit;
    }
    return ['count' => count($hits), 'recent' => $out];
}

function permLogEntry(string $line, array $rules): ?string
{
    if (preg_match('/permission denied for (table|relation|sequence|schema|database)|must be owner of/i', $line)) {
        return 'database';
    }
    $best = null;
    $bestLen = 0;
    foreach ($rules as $rule) {
        foreach ((array) $rule['paths'] as $p) {
            $prefix = rtrim(preg_replace('/[*?\[].*$/', '', $p), '/');
            if ($prefix !== '' && strpos($line, $prefix) !== false && strlen($prefix) > $bestLen) {
                $best = $rule['id'];
                $bestLen = strlen($prefix);
            }
        }
    }
    return $best;
}

function permLogWrite(string $msg): void
{
    @file_put_contents(permRoot() . PERM_LOG, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
}

function permApply(array $a): bool
{
    $path = $a['path'];
    if ($a['op'] === 'mkdir') {
        if (is_dir($path)) {
            return true;
        }
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true)) {
            return false;
        }
        return @mkdir($path, (int) $a['value']);
    }
    $st = permStat($path);
    if ($st === null || $st['type'] === 0120000) {
        return false;
    }
    if ($a['op'] === 'chown') {
        return @lchown($path, (int) $a['value']);
    }
    if ($a['op'] === 'chgrp') {
        return @lchgrp($path, (int) $a['value']);
    }
    if ($a['op'] === 'chmod') {
        $mode = (int) $a['value'];
        if ($st['type'] === 0100000 && ($a['kind'] ?? '') === 'tree') {
            $mode = $mode & ~0111 | ($st['mode'] & 0111);
        }
        return @chmod($path, $mode);
    }
    return false;
}

function permLock()
{
    $fh = @fopen(permRoot() . PERM_LOCK, 'c');
    if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
        return null;
    }
    return $fh;
}

function permFix(array $ids): array
{
    $rules = [];
    foreach ($ids as $id) {
        $rule = permRuleById($id);
        if ($rule === null) {
            return ['ok' => false, 'error' => 'unknown_id', 'message' => 'Unknown permission entry: ' . mb_substr($id, 0, 60) . '. Use the ids permissions.check lists.'];
        }
        $rules[$id] = $rule;
    }
    $lock = permLock();
    if ($lock === null) {
        return ['ok' => false, 'error' => 'busy', 'message' => 'Another permission repair is running. Try again in a moment.'];
    }
    $plan = [];
    $skipped = [];
    foreach ($rules as $id => $rule) {
        $check = permCheckRule($rule);
        if ($check['actions'] === []) {
            $skipped[] = $id;
            continue;
        }
        foreach ($check['actions'] as $a) {
            $a['rule'] = $id;
            $a['kind'] = $rule['kind'];
            $plan[] = $a;
        }
    }
    $undoId = '';
    if ($plan !== []) {
        $undoId = date('Ymd\THis') . '-' . bin2hex(random_bytes(4));
        $dir = permRoot() . PERM_UNDO_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $record = ['id' => $undoId, 'at' => date('c'), 'ids' => array_keys($rules), 'changes' => []];
        foreach ($plan as $a) {
            $record['changes'][] = ['rule' => $a['rule'], 'op' => $a['op'], 'path' => $a['path'], 'before' => $a['before']];
        }
        if (@file_put_contents($dir . '/' . $undoId . '.json', json_encode($record, JSON_UNESCAPED_SLASHES)) === false) {
            flock($lock, LOCK_UN);
            return ['ok' => false, 'error' => 'undo_unwritable', 'message' => 'Could not write the undo record, so nothing was changed.'];
        }
        @chmod($dir . '/' . $undoId . '.json', 0600);
    }
    $failed = [];
    foreach ($plan as $a) {
        if (!permApply($a)) {
            $failed[] = $a['op'] . ' ' . permShow($a['path']);
        }
    }
    $results = [];
    foreach ($rules as $id => $rule) {
        $after = permCheckRule($rule);
        $results[] = ['id' => $id, 'label' => $rule['label'], 'changed' => count(array_filter($plan, function ($a) use ($id) { return $a['rule'] === $id; })),
            'skipped' => in_array($id, $skipped, true), 'ok' => $after['problems'] === [], 'remaining' => $after['problems']];
    }
    $verified = array_reduce($results, function ($c, $r) { return $c && $r['ok']; }, true) && $failed === [];
    permLogWrite('fix ' . implode(',', array_keys($rules)) . ' undo=' . ($undoId ?: '-') . ' changes=' . count($plan) . ' failed=' . count($failed) . ' verified=' . ($verified ? 'yes' : 'no'));
    flock($lock, LOCK_UN);
    return ['ok' => $verified, 'undo' => $undoId, 'changes' => count($plan), 'failed' => array_slice($failed, 0, 20), 'results' => $results];
}

function permRollback(string $undoId): array
{
    if (!preg_match('/^\d{8}T\d{6}-[0-9a-f]{8}$/', $undoId)) {
        return ['ok' => false, 'error' => 'bad_id', 'message' => 'That is not an undo id from permissions.repair.'];
    }
    $file = permRoot() . PERM_UNDO_DIR . '/' . $undoId . '.json';
    $record = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($record) || empty($record['changes'])) {
        return ['ok' => false, 'error' => 'not_found', 'message' => 'No undo record ' . $undoId . ' on this server.'];
    }
    if (!empty($record['rolledBack'])) {
        return ['ok' => false, 'error' => 'already', 'message' => 'That repair was already rolled back.'];
    }
    $allowed = [];
    foreach ((array) $record['ids'] as $id) {
        $rule = permRuleById((string) $id);
        if ($rule !== null) {
            $allowed[] = $rule;
        }
    }
    $lock = permLock();
    if ($lock === null) {
        return ['ok' => false, 'error' => 'busy', 'message' => 'A permission repair is running. Try again in a moment.'];
    }
    $restored = 0;
    $failed = [];
    $created = [];
    foreach (array_reverse($record['changes']) as $c) {
        $path = (string) $c['path'];
        if (!permPathAllowed($path, $allowed)) {
            $failed[] = 'not in the rule list: ' . permShow($path);
            continue;
        }
        $before = $c['before'];
        if ($before === null) {
            if ($c['op'] === 'mkdir') {
                $created[] = $path;
            }
            continue;
        }
        $st = permStat($path);
        if ($st === null || $st['type'] === 0120000) {
            continue;
        }
        $ok = true;
        if ($c['op'] === 'chown') {
            $ok = @lchown($path, (int) $before['uid']);
        } elseif ($c['op'] === 'chgrp') {
            $ok = @lchgrp($path, (int) $before['gid']);
        } elseif ($c['op'] === 'chmod') {
            $ok = @chmod($path, (int) $before['mode']);
        }
        $ok ? $restored++ : ($failed[] = $c['op'] . ' ' . permShow($path));
    }
    foreach ($created as $dir) {
        if (is_dir($dir) && count(@scandir($dir) ?: []) <= 2 && @rmdir($dir)) {
            $restored++;
        }
    }
    $record['rolledBack'] = date('c');
    @file_put_contents($file, json_encode($record, JSON_UNESCAPED_SLASHES));
    permLogWrite('rollback ' . $undoId . ' restored=' . $restored . ' failed=' . count($failed));
    flock($lock, LOCK_UN);
    return ['ok' => $failed === [], 'undo' => $undoId, 'restored' => $restored, 'failed' => array_slice($failed, 0, 20)];
}

function permPathAllowed(string $path, array $rules): bool
{
    foreach ($rules as $rule) {
        foreach (permPaths($rule) as $p) {
            if ($path === $p || (in_array($rule['kind'], ['tree', 'webroot'], true) && strpos($path, rtrim($p, '/') . '/') === 0)) {
                return true;
            }
        }
        foreach ((array) $rule['paths'] as $raw) {
            if (strpbrk($raw, '*?[') === false && $path === permRoot() . $raw) {
                return true;
            }
        }
    }
    return false;
}

function permMain(array $argv): int
{
    $cmd = $argv[1] ?? '';
    if ($cmd === 'status') {
        $areas = isset($argv[2]) && $argv[2] !== '' ? explode(',', $argv[2]) : ['files', 'services', 'sockets', 'cron', 'security', 'logs'];
        $out = [];
        if (in_array('files', $areas, true)) {
            $out['files'] = permFiles();
        }
        if (in_array('services', $areas, true)) {
            $out['services'] = permServices();
        }
        if (in_array('sockets', $areas, true)) {
            $out['sockets'] = permSockets();
        }
        if (in_array('cron', $areas, true)) {
            $out['cron'] = permCron();
        }
        if (in_array('security', $areas, true)) {
            $out['security'] = permSecurity();
        }
        if (in_array('logs', $areas, true)) {
            $out['logs'] = permLogs(permRules());
        }
        echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
        return 0;
    }
    if ($cmd === 'fix') {
        $ids = array_values(array_filter(array_slice($argv, 2), function ($s) { return $s !== ''; }));
        if ($ids === [] || count($ids) > 30) {
            echo json_encode(['ok' => false, 'error' => 'no_ids', 'message' => 'Name 1 to 30 entry ids.']), "\n";
            return 0;
        }
        echo json_encode(permFix($ids), JSON_UNESCAPED_SLASHES), "\n";
        return 0;
    }
    if ($cmd === 'rollback') {
        echo json_encode(permRollback((string) ($argv[2] ?? '')), JSON_UNESCAPED_SLASHES), "\n";
        return 0;
    }
    fwrite(STDERR, "usage: permissions.php status [areas] | fix <id>... | rollback <undo-id>\n");
    return 2;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    exit(permMain($_SERVER['argv']));
}
