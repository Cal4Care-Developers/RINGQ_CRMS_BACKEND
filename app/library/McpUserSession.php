<?php

use Phalcon\Session\Adapter\Libmemcached;
use Phalcon\Storage\AdapterFactory;
use Phalcon\Storage\SerializerFactory;

class McpUserSession
{
    const SESSION_PREFIX = 'ringq_';
    const LIFETIME = 600;
    const CACHE_TTL = 540;
    const LOOPBACK_MAX = 4;
    const LOOPBACK_TIMEOUT = 20;
    const AUTH_KEYS = [
        'username', 'firstname', 'lastname', 'email', 'image', 'extension', 'subdomain',
        'domain', 'domain_uuid', 'sip_uuid', 'user_uuid', 'walltoken', 'access', 'result',
    ];

    public static function lookupUser($db, string $domainUuid, string $userUuid): ?array
    {
        $row = $db->fetchOne(
            "SELECT u.username, u.user_email, u.domain_uuid, u.user_enabled, e.extension, e.accountcode,
                    q.first_name, q.last_name, q.image
               FROM v_users u
               JOIN v_extension_users eu ON eu.user_uuid = u.user_uuid
               JOIN v_extensions e ON e.extension_uuid = eu.extension_uuid
               LEFT JOIN q_users q ON q.user_uuid = u.user_uuid
              WHERE u.user_uuid = :u AND u.domain_uuid = :d
              LIMIT 1",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['u' => $userUuid, 'd' => $domainUuid]
        );
        if (!$row || (string) $row['user_enabled'] !== 'true') {
            return null;
        }
        return $row;
    }

    public static function authFor(array $user, string $publicUrl, string $userUuid): array
    {
        return [
            'username' => (string) $user['username'],
            'firstname' => (string) ($user['first_name'] ?? ''),
            'lastname' => (string) ($user['last_name'] ?? ''),
            'email' => (string) $user['user_email'],
            'image' => (string) ($user['image'] ?? ''),
            'extension' => (string) $user['extension'],
            'subdomain' => (string) $user['accountcode'],
            'domain' => $publicUrl,
            'domain_uuid' => (string) $user['domain_uuid'],
            'sip_uuid' => '',
            'user_uuid' => $userUuid,
            'walltoken' => '',
            'access' => 'registered',
            'result' => 'success',
        ];
    }

    public static function adapter(): Libmemcached
    {
        return new Libmemcached(new AdapterFactory(new SerializerFactory()), [
            'client' => [],
            'servers' => [['host' => '127.0.0.1', 'port' => 11211, 'weight' => 0]],
            'lifetime' => self::LIFETIME,
            'prefix' => self::SESSION_PREFIX,
        ]);
    }

    public static function encode(array $auth): string
    {
        return 'auth|' . serialize($auth);
    }

    public static function cacheKey(string $actorRef): string
    {
        return 'mcp_user_session:' . $actorRef;
    }

    public static function sessionFor($redis, string $actorRef, array $auth): string
    {
        $cached = (string) $redis->get(self::cacheKey($actorRef));
        if (preg_match('/^[a-z0-9]{32}$/', $cached)) {
            return $cached;
        }
        $id = bin2hex(random_bytes(16));
        self::adapter()->write($id, self::encode($auth));
        $redis->setex(self::cacheKey($actorRef), self::CACHE_TTL, $id);
        return $id;
    }

    public static function forget($redis, string $actorRef): void
    {
        $id = (string) $redis->get(self::cacheKey($actorRef));
        if (preg_match('/^[a-z0-9]{32}$/', $id)) {
            self::adapter()->destroy($id);
        }
        $redis->del(self::cacheKey($actorRef));
    }

    public static function acquire($redis): bool
    {
        $n = (int) $redis->incr('mcp_loopback_inflight');
        $redis->expire('mcp_loopback_inflight', self::LOOPBACK_TIMEOUT + 10);
        if ($n > self::LOOPBACK_MAX) {
            $redis->decr('mcp_loopback_inflight');
            return false;
        }
        return true;
    }

    public static function release($redis): void
    {
        if ((int) $redis->decr('mcp_loopback_inflight') < 0) {
            $redis->set('mcp_loopback_inflight', 0);
        }
    }

    public static function call($redis, string $host, string $sessionId, string $callId, array $request): array
    {
        if (!self::acquire($redis)) {
            return ['ok' => false, 'busy' => true, 'status' => 0, 'body' => null];
        }
        try {
            $path = '/' . ltrim((string) $request['path'], '/');
            $method = strtoupper((string) ($request['method'] ?? 'GET'));
            $query = isset($request['query']) && is_array($request['query']) && $request['query'] !== []
                ? '?' . http_build_query($request['query']) : '';
            $headers = [
                'Cookie: RINGQ=' . $sessionId,
                'X-Ringq-Mcp-Call: ' . $callId,
                'Accept: application/json',
            ];
            $ch = curl_init('https://' . $host . ':8443' . $path . $query);
            $opts = [
                CURLOPT_RESOLVE => [$host . ':8443:127.0.0.1'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => self::LOOPBACK_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_CUSTOMREQUEST => $method,
            ];
            if ($method !== 'GET') {
                $encoding = (string) ($request['encoding'] ?? 'json');
                $body = $request['body'] ?? [];
                if ($encoding === 'form') {
                    $opts[CURLOPT_POSTFIELDS] = http_build_query((array) $body);
                    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                } elseif ($encoding === 'json-array') {
                    $opts[CURLOPT_POSTFIELDS] = json_encode(array_values((array) $body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                } elseif ($encoding === 'fu-json') {
                    $opts[CURLOPT_POSTFIELDS] = json_encode((object) $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                } else {
                    $opts[CURLOPT_POSTFIELDS] = json_encode((object) $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $headers[] = 'Content-Type: application/json';
                }
            }
            $opts[CURLOPT_HTTPHEADER] = $headers;
            curl_setopt_array($ch, $opts);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($raw === false) {
                return ['ok' => false, 'busy' => false, 'status' => 0, 'body' => null];
            }
            $body = json_decode((string) $raw, true);
            return ['ok' => $status >= 200 && $status < 300 && $body !== null, 'busy' => false, 'status' => $status, 'body' => $body];
        } finally {
            self::release($redis);
        }
    }
}
