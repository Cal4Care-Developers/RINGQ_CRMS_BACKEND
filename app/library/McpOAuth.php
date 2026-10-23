<?php

class McpOAuth
{
    const ACCESS_PREFIX = 'rqoa_';
    const REFRESH_PREFIX = 'rqor_';
    const KEY_PREFIX = 'rqak_';
    const CODE_TTL = 60;
    const ACCESS_TTL = 3600;
    const SCOPE_READ = 'read';
    const SCOPE_WRITE = 'write';
    const SCOPES = ['read', 'write'];
    const CLIENT_NAME_MAX = 200;
    const MAX_REDIRECTS = 10;
    const REGISTER_PER_IP = 20;
    const REGISTER_WINDOW = 3600;

    public static function newToken(string $prefix): string
    {
        return $prefix . bin2hex(random_bytes(32));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isWellFormed(string $token, string $prefix): bool
    {
        return strlen($token) === strlen($prefix) + 64
            && strncmp($token, $prefix, strlen($prefix)) === 0
            && ctype_xdigit(substr($token, strlen($prefix)));
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function pkceMatches(string $verifier, string $challenge): bool
    {
        if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier)) {
            return false;
        }
        if (!preg_match('/^[A-Za-z0-9\-_]{43}$/', $challenge)) {
            return false;
        }
        return hash_equals($challenge, self::base64url(hash('sha256', $verifier, true)));
    }

    public static function normalizeScope($scope): string
    {
        $parts = preg_split('/\s+/', trim((string) $scope));
        return in_array(self::SCOPE_WRITE, (array) $parts, true) ? self::SCOPE_WRITE : self::SCOPE_READ;
    }

    public static function scopeAllows(string $granted, string $class): bool
    {
        if ($class === 'read') {
            return true;
        }
        return $granted === self::SCOPE_WRITE;
    }

    public static function isLoopbackRedirect(array $u): bool
    {
        $host = strtolower((string) ($u['host'] ?? ''));
        return ($u['scheme'] ?? '') === 'http' && in_array($host, ['127.0.0.1', 'localhost', '[::1]', '::1'], true);
    }

    public static function cleanRedirectUris($value): array
    {
        if (!is_array($value) || $value === [] || count($value) > self::MAX_REDIRECTS) {
            return [];
        }
        $out = [];
        foreach ($value as $uri) {
            $uri = trim((string) $uri);
            if ($uri === '' || strlen($uri) > 2000 || strpos($uri, '#') !== false) {
                return [];
            }
            $u = parse_url($uri);
            if (!is_array($u) || empty($u['scheme'])) {
                return [];
            }
            $scheme = strtolower($u['scheme']);
            $ok = ($scheme === 'https' && !empty($u['host']))
                || self::isLoopbackRedirect($u)
                || (preg_match('/^[a-z][a-z0-9+.\-]*$/', $scheme) && !in_array($scheme, ['http', 'https', 'javascript', 'data', 'file', 'vbscript', 'blob', 'about', 'ws', 'wss'], true));
            if (!$ok) {
                return [];
            }
            $out[] = $uri;
        }
        return array_values(array_unique($out));
    }

    public static function redirectAllowed(array $registered, string $uri): bool
    {
        if (in_array($uri, $registered, true)) {
            return true;
        }
        $asked = parse_url($uri);
        if (!is_array($asked) || !self::isLoopbackRedirect($asked)) {
            return false;
        }
        foreach ($registered as $reg) {
            $r = parse_url($reg);
            if (is_array($r) && self::isLoopbackRedirect($r)
                && strtolower((string) $r['host']) === strtolower((string) $asked['host'])
                && (string) ($r['path'] ?? '') === (string) ($asked['path'] ?? '')) {
                return true;
            }
        }
        return false;
    }

    public static function cleanClientName($value): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F<>]+/u', ' ', (string) $value));
        if ($name === '') {
            return 'Unnamed app';
        }
        return mb_substr($name, 0, self::CLIENT_NAME_MAX);
    }

    public static function registerClient($db, array $body): ?array
    {
        $uris = self::cleanRedirectUris($body['redirect_uris'] ?? null);
        if ($uris === []) {
            return null;
        }
        $auth = (string) ($body['token_endpoint_auth_method'] ?? 'none');
        if ($auth !== 'none') {
            return null;
        }
        $id = 'rqc_' . self::newId();
        $name = self::cleanClientName($body['client_name'] ?? '');
        $db->execute(
            "INSERT INTO q_mcp_clients (client_id, client_name, redirect_uris) VALUES (:id, :name, CAST(:uris AS jsonb))",
            ['id' => $id, 'name' => $name, 'uris' => json_encode($uris, JSON_UNESCAPED_SLASHES)]
        );
        return [
            'client_id' => $id,
            'client_name' => $name,
            'redirect_uris' => $uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'client_id_issued_at' => time(),
        ];
    }

    public static function findClient($db, string $id): ?array
    {
        if (!preg_match('/^rqc_[0-9a-f]{32}$/', $id)) {
            return null;
        }
        $row = $db->fetchOne(
            "SELECT client_id, client_name, redirect_uris FROM q_mcp_clients WHERE client_id = :id",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['id' => $id]
        );
        if (!$row) {
            return null;
        }
        $row['redirect_uris'] = json_decode((string) $row['redirect_uris'], true) ?: [];
        return $row;
    }

    public static function codeKey(string $hash): string
    {
        return 'mcp_oauth_code:' . $hash;
    }

    public static function issueCode($redis, array $data): string
    {
        $code = self::newToken('rqcd_');
        $redis->setex(self::codeKey(self::hash($code)), self::CODE_TTL, json_encode($data));
        return $code;
    }

    public static function consumeCode($redis, string $code): ?array
    {
        if (!self::isWellFormed($code, 'rqcd_')) {
            return null;
        }
        $key = self::codeKey(self::hash($code));
        $raw = $redis->get($key);
        if ($raw === null || $raw === false) {
            return null;
        }
        if ((int) $redis->del($key) !== 1) {
            return null;
        }
        $data = json_decode((string) $raw, true);
        return is_array($data) ? $data : null;
    }

    public static function accessKey(string $hash): string
    {
        return 'mcp_oauth_access:' . $hash;
    }

    public static function issueAccess($redis, string $sessionId): string
    {
        $token = self::newToken(self::ACCESS_PREFIX);
        $redis->setex(self::accessKey(self::hash($token)), self::ACCESS_TTL, $sessionId);
        return $token;
    }

    public static function tokenResponse(string $access, string $refresh, string $scope): array
    {
        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope' => $scope,
        ];
    }

    public static function createSession($db, $redis, array $code): array
    {
        $sessionId = self::newId();
        $refresh = self::newToken(self::REFRESH_PREFIX);
        $db->execute(
            "INSERT INTO q_mcp_sessions (session_id, domain_uuid, user_uuid, client_id, client_name, scopes, resource, refresh_hash, last_used_at)
             VALUES (:sid, :d, :u, :c, :cn, :s, :r, :h, now())",
            [
                'sid' => $sessionId,
                'd' => (string) $code['domain_uuid'],
                'u' => (string) $code['user_uuid'],
                'c' => (string) $code['client_id'],
                'cn' => mb_substr((string) $code['client_name'], 0, self::CLIENT_NAME_MAX),
                's' => self::normalizeScope($code['scope'] ?? ''),
                'r' => mb_substr((string) ($code['resource'] ?? ''), 0, 255),
                'h' => self::hash($refresh),
            ]
        );
        $access = self::issueAccess($redis, $sessionId);
        return self::tokenResponse($access, $refresh, self::normalizeScope($code['scope'] ?? ''));
    }

    public static function refresh($db, $redis, string $refresh, string $clientId): ?array
    {
        if (!self::isWellFormed($refresh, self::REFRESH_PREFIX)) {
            return null;
        }
        $hash = self::hash($refresh);
        $row = $db->fetchOne(
            "SELECT session_id, client_id, scopes, refresh_hash, revoked_at FROM q_mcp_sessions WHERE refresh_hash = :h OR prev_refresh_hash = :p LIMIT 1",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['h' => $hash, 'p' => $hash]
        );
        if (!$row || $row['revoked_at'] !== null || !hash_equals((string) $row['client_id'], $clientId)) {
            return null;
        }
        if (!hash_equals(trim((string) $row['refresh_hash']), $hash)) {
            self::revokeSession($db, (string) $row['session_id'], 'refresh token reused');
            return null;
        }
        $next = self::newToken(self::REFRESH_PREFIX);
        $db->execute(
            "UPDATE q_mcp_sessions SET prev_refresh_hash = refresh_hash, refresh_hash = :n, last_used_at = now()
              WHERE session_id = :sid AND refresh_hash = :h AND revoked_at IS NULL",
            ['n' => self::hash($next), 'sid' => (string) $row['session_id'], 'h' => $hash]
        );
        $access = self::issueAccess($redis, (string) $row['session_id']);
        return self::tokenResponse($access, $next, (string) $row['scopes']);
    }

    public static function sessionForAccess($db, $redis, string $token): ?array
    {
        if (!self::isWellFormed($token, self::ACCESS_PREFIX)) {
            return null;
        }
        $sessionId = (string) $redis->get(self::accessKey(self::hash($token)));
        if ($sessionId === '') {
            return null;
        }
        $row = $db->fetchOne(
            "SELECT session_id, domain_uuid, user_uuid, client_id, client_name, scopes, revoked_at FROM q_mcp_sessions WHERE session_id = :sid",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['sid' => $sessionId]
        );
        if (!$row || $row['revoked_at'] !== null) {
            return null;
        }
        return $row;
    }

    public static function touchSession($db, string $sessionId): void
    {
        $db->execute(
            "UPDATE q_mcp_sessions SET last_used_at = now() WHERE session_id = :sid AND (last_used_at IS NULL OR last_used_at < now() - interval '1 minute')",
            ['sid' => $sessionId]
        );
    }

    public static function revokeSession($db, string $sessionId, string $by): bool
    {
        $db->execute(
            "UPDATE q_mcp_sessions SET revoked_at = now(), revoked_by = :by WHERE session_id = :sid AND revoked_at IS NULL",
            ['sid' => $sessionId, 'by' => mb_substr($by, 0, 255)]
        );
        return $db->affectedRows() > 0;
    }

    public static function revokeByRefresh($db, string $token, string $by): bool
    {
        if (!self::isWellFormed($token, self::REFRESH_PREFIX)) {
            return false;
        }
        $row = $db->fetchOne(
            "SELECT session_id FROM q_mcp_sessions WHERE refresh_hash = :h AND revoked_at IS NULL",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['h' => self::hash($token)]
        );
        return $row ? self::revokeSession($db, (string) $row['session_id'], $by) : false;
    }

    public static function revokeTenant($db, string $domainUuid, string $by): int
    {
        $db->execute(
            "UPDATE q_mcp_sessions SET revoked_at = now(), revoked_by = :by WHERE domain_uuid = :d AND revoked_at IS NULL",
            ['d' => $domainUuid, 'by' => mb_substr($by, 0, 255)]
        );
        $sessions = $db->affectedRows();
        $db->execute(
            "UPDATE q_mcp_agent_keys SET revoked_at = now() WHERE domain_uuid = :d AND revoked_at IS NULL",
            ['d' => $domainUuid]
        );
        return $sessions + $db->affectedRows();
    }

    public static function listSessions($db, string $domainUuid, ?string $userUuid): array
    {
        $sql = "SELECT s.session_id, s.user_uuid, s.client_name, s.scopes, s.created_at, s.last_used_at, u.user_email
                  FROM q_mcp_sessions s LEFT JOIN v_users u ON u.user_uuid::text = s.user_uuid
                 WHERE s.domain_uuid = :d AND s.revoked_at IS NULL";
        $bind = ['d' => $domainUuid];
        if ($userUuid !== null) {
            $sql .= " AND s.user_uuid = :u";
            $bind['u'] = $userUuid;
        }
        $rows = $db->fetchAll($sql . " ORDER BY s.created_at DESC LIMIT 200", \Phalcon\Db\Enum::FETCH_ASSOC, $bind);
        return array_map(function ($r) {
            return [
                'id' => (string) $r['session_id'],
                'user' => (string) ($r['user_email'] ?? ''),
                'userUuid' => (string) $r['user_uuid'],
                'client' => (string) $r['client_name'],
                'scopes' => $r['scopes'] === self::SCOPE_WRITE ? ['read', 'write'] : ['read'],
                'createdAt' => strtotime((string) $r['created_at']) ?: 0,
                'lastUsedAt' => $r['last_used_at'] ? (strtotime((string) $r['last_used_at']) ?: null) : null,
            ];
        }, $rows);
    }

    public static function findSession($db, string $domainUuid, string $sessionId): ?array
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $sessionId)) {
            return null;
        }
        $row = $db->fetchOne(
            "SELECT session_id, user_uuid, client_name FROM q_mcp_sessions WHERE session_id = :sid AND domain_uuid = :d AND revoked_at IS NULL",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['sid' => $sessionId, 'd' => $domainUuid]
        );
        return $row ?: null;
    }

    public static function createAgentKey($db, string $domainUuid, string $userUuid, string $name, string $scope, array $ips, ?int $expiresAt): array
    {
        $id = self::newId();
        $token = self::newToken(self::KEY_PREFIX);
        $db->execute(
            "INSERT INTO q_mcp_agent_keys (key_id, domain_uuid, user_uuid, name, key_hash, scopes, ip_allowlist, expires_at)
             VALUES (:id, :d, :u, :n, :h, :s, CAST(:ips AS jsonb), CASE WHEN :e = 0 THEN NULL ELSE to_timestamp(:e2) END)",
            [
                'id' => $id, 'd' => $domainUuid, 'u' => $userUuid, 'n' => $name,
                'h' => self::hash($token), 's' => $scope === self::SCOPE_WRITE ? self::SCOPE_WRITE : self::SCOPE_READ,
                'ips' => json_encode(array_values($ips)), 'e' => (int) $expiresAt, 'e2' => (int) $expiresAt,
            ]
        );
        return ['id' => $id, 'token' => $token];
    }

    public static function listAgentKeys($db, string $domainUuid): array
    {
        $rows = $db->fetchAll(
            "SELECT key_id, name, scopes, ip_allowlist, created_at, last_used_at, expires_at FROM q_mcp_agent_keys
              WHERE domain_uuid = :d AND revoked_at IS NULL ORDER BY created_at DESC LIMIT 200",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['d' => $domainUuid]
        );
        return array_map(function ($r) {
            return [
                'id' => (string) $r['key_id'],
                'name' => (string) $r['name'],
                'scopes' => $r['scopes'] === self::SCOPE_WRITE ? ['read', 'write'] : ['read'],
                'ipAllowlist' => json_decode((string) $r['ip_allowlist'], true) ?: [],
                'createdAt' => strtotime((string) $r['created_at']) ?: 0,
                'lastUsedAt' => $r['last_used_at'] ? (strtotime((string) $r['last_used_at']) ?: null) : null,
                'expiresAt' => $r['expires_at'] ? (strtotime((string) $r['expires_at']) ?: null) : null,
            ];
        }, $rows);
    }

    public static function revokeAgentKey($db, string $domainUuid, string $keyId): bool
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $keyId)) {
            return false;
        }
        $db->execute(
            "UPDATE q_mcp_agent_keys SET revoked_at = now() WHERE key_id = :id AND domain_uuid = :d AND revoked_at IS NULL",
            ['id' => $keyId, 'd' => $domainUuid]
        );
        return $db->affectedRows() > 0;
    }

    public static function ipAllowed(array $allowlist, string $ip): bool
    {
        if ($allowlist === []) {
            return true;
        }
        foreach ($allowlist as $entry) {
            if (self::ipInRange($ip, (string) $entry)) {
                return true;
            }
        }
        return false;
    }

    public static function ipInRange(string $ip, string $range): bool
    {
        $parts = explode('/', $range, 2);
        $net = @inet_pton($parts[0]);
        $addr = @inet_pton($ip);
        if ($net === false || $addr === false || strlen($net) !== strlen($addr)) {
            return false;
        }
        $bits = isset($parts[1]) ? (int) $parts[1] : strlen($net) * 8;
        if ($bits < 0 || $bits > strlen($net) * 8) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (strncmp($net, $addr, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($net[$bytes]) & $mask) === (ord($addr[$bytes]) & $mask);
    }

    public static function agentKeyFor($db, string $token, string $ip): ?array
    {
        if (!self::isWellFormed($token, self::KEY_PREFIX)) {
            return null;
        }
        $row = $db->fetchOne(
            "SELECT key_id, domain_uuid, user_uuid, name, scopes, ip_allowlist, expires_at, revoked_at FROM q_mcp_agent_keys WHERE key_hash = :h",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['h' => self::hash($token)]
        );
        if (!$row || $row['revoked_at'] !== null) {
            return null;
        }
        if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) <= time()) {
            return null;
        }
        if (!self::ipAllowed(json_decode((string) $row['ip_allowlist'], true) ?: [], $ip)) {
            return null;
        }
        $db->execute(
            "UPDATE q_mcp_agent_keys SET last_used_at = now() WHERE key_id = :id AND (last_used_at IS NULL OR last_used_at < now() - interval '1 minute')",
            ['id' => (string) $row['key_id']]
        );
        return $row;
    }

    public static function registerRateKey(string $ip): string
    {
        return 'mcp_oauth_register:' . $ip;
    }
}
