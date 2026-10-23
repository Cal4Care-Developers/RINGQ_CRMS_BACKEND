<?php

class McpStaff
{
    const VERSION = 'RINGQ-STAFF-V1';
    const PATH = '/mcp/staff';
    const SKEW = 120;
    const NONCE_TTL = 600;
    const MAX_BODY = 262144;
    const ROLES = ['l1' => 1, 'l2' => 2, 'admin' => 3, 'owner' => 4];
    const CLASS_ROLE = ['read' => 'l1', 'write' => 'l2', 'dangerous' => 'admin'];
    const CLASS_LEVEL = ['read' => 'read', 'write' => 'readwrite', 'dangerous' => 'readwrite'];
    const LEVEL_RANK = ['off' => 0, 'read' => 1, 'readwrite' => 2];
    const OPS = ['describe', 'health', 'list', 'call', 'approvals', 'decide', 'lookup'];
    const HEALTH_TOOLS = ['get_system_overview', 'get_service_status', 'get_ssl_and_storage', 'get_trunk_status'];
    const REPO_KEYS = 'ops-staff-keys.php';
    const LOCAL_KEYS = '/etc/ringq/ops-staff-keys.json';
    const WRITES_DISABLED_KEY = 'mcp_staff:writes_disabled';
    const NONCE_PREFIX = 'mcp_staff_nonce:';
    const ROLE_LABELS = ['l1' => 'L1', 'l2' => 'L2', 'admin' => 'Admin', 'owner' => 'Owner'];
    const LOOKUP_KINDS = ['did', 'email'];
    const LOOKUP_LIMIT = 20;
    const DID_MIN_DIGITS = 6;
    const DID_MAX_DIGITS = 20;
    const DID_SUFFIX_DIGITS = 8;
    const DID_MAX_PREFIX = 4;

    public static function canonical(string $method, string $host, string $path, string $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [
            self::VERSION,
            strtoupper($method),
            strtolower(trim(explode(':', $host)[0])),
            $path,
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    public static function keyFiles(): array
    {
        return [dirname(__DIR__) . '/config/' . self::REPO_KEYS, self::LOCAL_KEYS];
    }

    public static function keysFrom(array $paths, int $now): array
    {
        $keys = [];
        foreach ($paths as $path) {
            $doc = self::keyDocument((string) $path);
            foreach ((array) ($doc['keys'] ?? []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $id = (string) ($entry['id'] ?? '');
                $public = base64_decode((string) ($entry['publicKey'] ?? ''), true);
                if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $id) || $public === false || strlen($public) !== 32) {
                    continue;
                }
                $notAfter = isset($entry['notAfter']) ? (int) $entry['notAfter'] : 0;
                if ($notAfter > 0 && $notAfter < $now) {
                    continue;
                }
                $keys[$id] = $public;
            }
        }
        return $keys;
    }

    public static function keyDocument(string $path): array
    {
        if (substr($path, -4) === '.php') {
            $doc = is_file($path) ? require $path : null;
        } else {
            $raw = @file_get_contents($path);
            $doc = $raw === false ? null : json_decode($raw, true);
        }
        return is_array($doc) ? $doc : [];
    }

    public static function verify($redis, array $keys, array $headers, string $method, string $host, string $path, string $body, int $now): ?array
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            error_log('ops staff: sodium is not available, refusing every staff request');
            return null;
        }
        if ($path !== self::PATH || strlen($body) > self::MAX_BODY) {
            return null;
        }
        $keyId = (string) ($headers['key'] ?? '');
        $timestamp = (string) ($headers['timestamp'] ?? '');
        $nonce = (string) ($headers['nonce'] ?? '');
        $signature = base64_decode((string) ($headers['signature'] ?? ''), true);
        if (!isset($keys[$keyId]) || !preg_match('/^[0-9]{10}$/', $timestamp) || !preg_match('/^[0-9a-f]{32}$/', $nonce)
            || $signature === false || strlen($signature) !== 64) {
            return null;
        }
        if (abs($now - (int) $timestamp) > self::SKEW) {
            return null;
        }
        $message = self::canonical($method, $host, $path, $timestamp, $nonce, $body);
        if (!sodium_crypto_sign_verify_detached($signature, $message, $keys[$keyId])) {
            return null;
        }
        if (!$redis->set(self::NONCE_PREFIX . $nonce, '1', 'EX', self::NONCE_TTL, 'NX')) {
            return null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || self::claims($decoded) === null || !in_array((string) ($decoded['op'] ?? ''), self::OPS, true)) {
            return null;
        }
        $decoded['keyId'] = $keyId;
        return $decoded;
    }

    public static function claims(array $body): ?array
    {
        $staff = $body['staff'] ?? null;
        if (!is_array($staff)) {
            return null;
        }
        $id = (string) ($staff['id'] ?? '');
        $email = strtolower(trim((string) ($staff['email'] ?? '')));
        $role = (string) ($staff['role'] ?? '');
        if (!preg_match('/^[0-9]{1,12}$/', $id) || !filter_var($email, FILTER_VALIDATE_EMAIL) || !isset(self::ROLES[$role])) {
            return null;
        }
        return [
            'id' => $id,
            'email' => mb_substr($email, 0, 190),
            'name' => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F<>]+/u', ' ', (string) ($staff['name'] ?? ''))), 0, 120),
            'role' => $role,
            'client' => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F<>]+/u', ' ', (string) ($staff['client'] ?? ''))), 0, 120),
            'session' => preg_match('/^[0-9a-f]{32}$/', (string) ($staff['session'] ?? '')) ? (string) $staff['session'] : '',
            'ip' => @inet_pton((string) ($staff['ip'] ?? '')) !== false ? (string) $staff['ip'] : '',
        ];
    }

    public static function roleRank(string $role): int
    {
        return self::ROLES[$role] ?? 0;
    }

    public static function roleLabel(string $role): string
    {
        return self::ROLE_LABELS[$role] ?? $role;
    }

    public static function permits(string $level, string $role, string $class, bool $writesKilled): ?string
    {
        if (!isset(self::CLASS_ROLE[$class])) {
            return 'That operation is not available to RingQ support.';
        }
        if ((self::LEVEL_RANK[$level] ?? 0) === 0) {
            return 'This customer has RingQ support access turned off.';
        }
        if ((self::LEVEL_RANK[$level] ?? 0) < self::LEVEL_RANK[self::CLASS_LEVEL[$class]]) {
            return 'This customer allows RingQ support to read only. They can allow changes in Global Settings, Advanced, AI & MCP.';
        }
        if (self::roleRank($role) < self::roleRank(self::CLASS_ROLE[$class])) {
            return 'Your RingQ staff role (' . self::roleLabel($role) . ') cannot ' . ($class === 'dangerous' ? 'make high-risk changes.' : 'make changes.');
        }
        if ($class !== 'read' && $writesKilled) {
            return 'Changes by RingQ support are switched off on this server.';
        }
        return null;
    }

    public static function catalogScope(string $level, string $role, bool $writesKilled): string
    {
        return self::permits($level, $role, 'write', $writesKilled) === null ? McpOAuth::SCOPE_WRITE : McpOAuth::SCOPE_READ;
    }

    public static function actorRef(array $staff, string $domainUuid): string
    {
        return 'staff:' . $staff['id'] . ':' . $domainUuid;
    }

    public static function label(array $staff): string
    {
        $name = $staff['name'] !== '' ? $staff['name'] : $staff['email'];
        return 'RingQ support: ' . $name . ' (' . $staff['email'] . ', ' . self::roleLabel($staff['role']) . ')';
    }

    public static function ownerExtensions(string $permission1): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $permission1)), function ($x) {
            return preg_match('/^[0-9A-Za-z*#+]{1,32}$/', $x) === 1;
        }));
    }

    public static function ownerUser($db, string $domainUuid): ?array
    {
        $modes = $db->fetchOne(
            "SELECT permission1 FROM q_company_modes WHERE domain_uuid = :d LIMIT 1",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['d' => $domainUuid]
        );
        foreach (self::ownerExtensions((string) ($modes['permission1'] ?? '')) as $extension) {
            $row = $db->fetchOne(
                "SELECT u.user_uuid::text AS user_uuid
                   FROM v_extensions e
                   JOIN v_extension_users eu ON eu.extension_uuid = e.extension_uuid
                   JOIN v_users u ON u.user_uuid = eu.user_uuid
                  WHERE e.domain_uuid = :d AND e.extension = :x AND u.user_enabled = 'true'
                  LIMIT 1",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid, 'x' => $extension]
            );
            if ($row) {
                $user = McpUserSession::lookupUser($db, $domainUuid, (string) $row['user_uuid']);
                if ($user !== null) {
                    return $user + ['user_uuid' => (string) $row['user_uuid']];
                }
            }
        }
        return null;
    }

    public static function toolsFor(string $level, string $role, bool $writesKilled): array
    {
        $tools = [];
        foreach (McpAccount::toolsFor('owner', self::catalogScope($level, $role, $writesKilled) === McpOAuth::SCOPE_WRITE) as $tool) {
            if ($tool['name'] === 'get_account_info') {
                continue;
            }
            $tools[] = $tool;
        }
        return $tools;
    }

    public static function healthSummary(array $parts): array
    {
        $o = is_array($parts['get_system_overview'] ?? null) ? $parts['get_system_overview'] : null;
        $s = is_array($parts['get_service_status'] ?? null) ? $parts['get_service_status'] : null;
        $c = is_array($parts['get_ssl_and_storage'] ?? null) ? $parts['get_ssl_and_storage'] : null;
        $t = is_array($parts['get_trunk_status'] ?? null) ? $parts['get_trunk_status'] : null;
        $down = [];
        foreach ((array) ($s['systemd'] ?? []) as $unit) {
            if (is_array($unit) && (string) ($unit['state'] ?? '') !== 'active') {
                $down[] = 'system: ' . (string) ($unit['name'] ?? '?');
            }
        }
        foreach ((array) ($s['nodeServices'] ?? []) as $node) {
            if (is_array($node) && !in_array((string) ($node['state'] ?? ''), ['online', 'unknown'], true)) {
                $down[] = 'node: ' . (string) ($node['name'] ?? '?');
            }
        }
        $trunksDown = [];
        $trunksUnknown = 0;
        foreach ((array) ($t['trunks'] ?? []) as $g) {
            if (!is_array($g) || empty($g['enabled']) || empty($g['registers'])) {
                continue;
            }
            $state = strtolower((string) ($g['state'] ?? 'unknown'));
            if ($state === 'unknown' || $state === '') {
                $trunksUnknown++;
            } elseif (!in_array($state, ['reged', 'registered', 'up'], true)) {
                $trunksDown[] = (string) ($g['name'] ?? '?');
            }
        }
        $disk = $c['disk']['pct'] ?? ($o['resources']['diskUsedPercent'] ?? null);
        $missing = [];
        foreach (self::HEALTH_TOOLS as $tool) {
            if (!is_array($parts[$tool] ?? null)) {
                $missing[] = $tool;
            }
        }
        return [
            'version' => (string) ($o['server']['version'] ?? ''),
            'cpuLoadPercent' => isset($o['resources']['cpuLoadPercent']) ? (float) $o['resources']['cpuLoadPercent'] : null,
            'diskUsedPercent' => $disk === null ? null : (float) $disk,
            'sslDaysLeft' => isset($c['certificate']['daysUntilExpiry']) ? (int) $c['certificate']['daysUntilExpiry'] : null,
            'sslError' => $c === null ? null : ($c['certificate']['error'] ?? null),
            'servicesDown' => $down,
            'sipProfiles' => is_array($s['sipProfiles'] ?? null) ? count($s['sipProfiles']) : null,
            'trunks' => ['total' => (int) ($t['count'] ?? 0), 'down' => $trunksDown, 'unknown' => $trunksUnknown],
            'notes' => array_values(array_merge((array) ($s['notes'] ?? []), (array) ($c['notes'] ?? []))),
            'unavailable' => $missing,
        ];
    }

    public static function approvalRequester($db, string $domainUuid, string $approvalId): ?array
    {
        if (!preg_match('/^appr_[0-9a-f]{24}$/', $approvalId)) {
            return null;
        }
        $row = $db->fetchOne(
            "SELECT actor_kind, actor_ref, operation_id FROM q_mcp_approvals WHERE approval_id = :id AND domain_uuid = :d",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['id' => $approvalId, 'd' => $domainUuid]
        );
        return $row ?: null;
    }

    public static function normalizeDid(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || preg_match('/[^0-9+()\s.\-]/', $raw)) {
            return '';
        }
        $digits = preg_replace('/[^0-9]/', '', $raw);
        if (strpos($raw, '+') === false && substr($digits, 0, 2) === '00') {
            $digits = substr($digits, 2);
        }
        $length = strlen($digits);
        return $length >= self::DID_MIN_DIGITS && $length <= self::DID_MAX_DIGITS ? $digits : '';
    }

    public static function normalizeEmail(string $raw): string
    {
        $email = strtolower(trim($raw));
        return strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    public static function didMatches(string $query, string $stored): bool
    {
        $query = self::normalizeDid($query);
        $stored = self::normalizeDid($stored);
        if ($query === '' || $stored === '') {
            return false;
        }
        if ($query === $stored) {
            return true;
        }
        $long = strlen($query) >= strlen($stored) ? $query : $stored;
        $short = $long === $query ? $stored : $query;
        foreach ([$short, ltrim($short, '0') === $short ? null : substr($short, 1)] as $tail) {
            if ($tail === null || strlen($tail) < self::DID_SUFFIX_DIGITS) {
                continue;
            }
            if (strlen($long) - strlen($tail) <= self::DID_MAX_PREFIX && substr($long, -strlen($tail)) === $tail) {
                return true;
            }
        }
        return false;
    }

    public static function lookupRequest(array $body): ?array
    {
        $kind = (string) ($body['kind'] ?? '');
        $raw = (string) ($body['value'] ?? '');
        $searchId = (string) ($body['searchId'] ?? '');
        if (!in_array($kind, self::LOOKUP_KINDS, true) || !preg_match('/^[0-9a-f]{16,32}$/', $searchId)) {
            return null;
        }
        $value = $kind === 'did' ? self::normalizeDid($raw) : self::normalizeEmail($raw);
        return $value === '' ? null : ['kind' => $kind, 'value' => $value, 'searchId' => $searchId];
    }

    public static function lookup($db, string $domainUuid, array $request): ?array
    {
        try {
            return self::lookupRows($db, $domainUuid, $request);
        } catch (\Throwable $e) {
            error_log('ops staff lookup: ' . $e->getMessage());
            return null;
        }
    }

    private static function lookupRows($db, string $domainUuid, array $request): array
    {
        $matches = [];
        if ($request['kind'] === 'did') {
            $value = (string) $request['value'];
            $tail = strlen($value) > self::DID_SUFFIX_DIGITS ? substr($value, -self::DID_SUFFIX_DIGITS) : $value;
            $rows = $db->fetchAll(
                "SELECT did_number FROM q_dids
                  WHERE domain_uuid = :d AND regexp_replace(did_number, '[^0-9]', '', 'g') LIKE :t
                  LIMIT 50",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid, 't' => '%' . $tail]
            ) ?: [];
            foreach ($rows as $row) {
                $did = (string) ($row['did_number'] ?? '');
                if (self::didMatches($value, $did)) {
                    $matches[] = ['kind' => 'did', 'did' => $did];
                }
            }
        } else {
            $rows = $db->fetchAll(
                "SELECT e.extension
                   FROM v_users u
                   LEFT JOIN v_extension_users eu ON eu.user_uuid = u.user_uuid
                   LEFT JOIN v_extensions e ON e.extension_uuid = eu.extension_uuid AND e.domain_uuid = u.domain_uuid
                  WHERE u.domain_uuid = :d AND lower(trim(u.user_email)) = :e
                  LIMIT 50",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid, 'e' => (string) $request['value']]
            ) ?: [];
            foreach ($rows as $row) {
                $extension = isset($row['extension']) && $row['extension'] !== null ? (string) $row['extension'] : null;
                $matches[] = ['kind' => 'email', 'extension' => $extension];
            }
        }
        $unique = [];
        foreach ($matches as $match) {
            $unique[json_encode($match)] = $match;
        }
        return array_slice(array_values($unique), 0, self::LOOKUP_LIMIT);
    }

    public static function lookupAudit(?array $request, array $matches): array
    {
        return [
            'searchKind' => $request === null ? 'invalid' : (string) $request['kind'],
            'searchId' => $request === null ? '' : (string) $request['searchId'],
            'matched' => $matches !== [],
            'matches' => $matches,
        ];
    }

    public static function mayDecide(array $settings, array $staff, string $domainUuid, ?array $requester, bool $serverWide = false): ?string
    {
        if (!$serverWide && empty($settings['supportMayApprove'])) {
            return 'This customer has not allowed RingQ to approve on their behalf. The account owner must approve in RingQ.';
        }
        if (self::roleRank($staff['role']) < self::roleRank('admin')) {
            return 'Only a RingQ staff Admin can approve on a customer\'s behalf.';
        }
        if ($requester === null || (string) $requester['actor_kind'] !== 'staff') {
            return 'That request was not made by RingQ support, so only the account owner can decide it.';
        }
        if ((string) $requester['actor_ref'] === self::actorRef($staff, $domainUuid)) {
            return 'You cannot approve your own request. Ask another RingQ Admin or the account owner.';
        }
        return null;
    }
}
