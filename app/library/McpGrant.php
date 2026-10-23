<?php

class McpGrant
{
    const TOKEN_PREFIX = 'rqmcp_';
    const DURATIONS = [3600, 14400, 28800, 86400];
    const MAX_ACTIVE_GRANTS = 10;
    const RATE_WINDOW = 60;
    const RATE_PER_GRANT = 60;
    const RATE_PER_TENANT = 300;
    const OWNER_MODE = 7;
    const SUPPORT_EXTENSIONS = ['9998', '9997'];
    const SERVICE_KEY = 'mcp_service:disabled';
    const RETIRED = true;
    const SCOPE_READ = 'read';
    const LEVEL_OFF = 'off';
    const LEVEL_READ = 'read';
    const LEVEL_READWRITE = 'readwrite';
    const LEVELS = ['off', 'read', 'readwrite'];
    const CAPABILITIES = [
        'customerMcp' => false,
        'oauth' => false,
        'sessions' => false,
        'agentKeys' => false,
        'approvals' => false,
    ];

    /**
     * The changes a grant can permit, in the order the requirements list them.
     *
     * Read-only diagnostics are never in here: they are what a grant is FOR,
     * and are always included. These are the actions that alter the server, so
     * each one is a separate thing for the customer to allow or withhold.
     *
     * NOTE: none of these tools is implemented yet. The permission is recorded
     * and enforced now so that when they arrive they cannot arrive ungated --
     * a grant issued today already carries what its engineer may do.
     */
    const FIX_ACTIONS = [
        'unban_ip',
        'reregister_trunk',
        'kick_device',
        'hangup_call',
        'restart_service',
        'resend_credentials',
        'update_extension',
        'clear_cache',
        'call_debug',
    ];

    public static function settingsKey(string $subdomain): string
    {
        return 'mcp_settings:' . $subdomain;
    }

    public static function grantKey(string $subdomain, string $id): string
    {
        return 'mcp_grant:' . $subdomain . ':' . $id;
    }

    public static function indexKey(string $subdomain): string
    {
        return 'mcp_grants:' . $subdomain;
    }

    public static function tokenKey(string $subdomain, string $hash): string
    {
        return 'mcp_token:' . $subdomain . ':' . $hash;
    }

    public static function rateKey(string $scope, string $id): string
    {
        return 'mcp_rate:' . $scope . ':' . $id . ':' . intdiv(time(), self::RATE_WINDOW);
    }

    public static function canManage(int $mode, string $extension): bool
    {
        return $mode === self::OWNER_MODE || in_array($extension, self::SUPPORT_EXTENSIONS, true);
    }

    public static function isSupportExtension(string $extension): bool
    {
        return in_array($extension, self::SUPPORT_EXTENSIONS, true);
    }

    public static function isDuration($seconds): bool
    {
        return in_array((int) $seconds, self::DURATIONS, true) && (string) (int) $seconds === (string) $seconds;
    }

    private static function rawSettings($redis, string $subdomain): array
    {
        $raw = json_decode((string) $redis->get(self::settingsKey($subdomain)), true);
        return is_array($raw) ? $raw : [];
    }

    public static function isEnabled(array $raw): bool
    {
        return !array_key_exists('enabled', $raw) || $raw['enabled'] === true;
    }

    public static function levelOf(array $raw): string
    {
        if (!self::isEnabled($raw)) {
            return self::LEVEL_OFF;
        }
        if (!array_key_exists('enabled', $raw)) {
            return self::LEVEL_READWRITE;
        }
        $stored = (string) ($raw['supportLevel'] ?? '');
        return $stored === self::LEVEL_READWRITE ? self::LEVEL_READWRITE : self::LEVEL_READ;
    }

    public static function isLevel($value): bool
    {
        return is_string($value) && in_array($value, self::LEVELS, true);
    }

    public static function settings($redis, string $subdomain): array
    {
        $raw = self::rawSettings($redis, $subdomain);
        $mcp = isset($raw['mcp']) && is_array($raw['mcp']) ? $raw['mcp'] : [];
        return [
            'enabled' => self::isEnabled($raw),
            'supportLevel' => self::levelOf($raw),
            'mcp' => [
                'enabled' => self::mcpSwitch($redis, $mcp, 'enabled'),
                'writesAllowed' => self::mcpSwitch($redis, $mcp, 'writesAllowed'),
            ],
            // On unless the owner has switched it off: only an explicit false turns it off.
            'supportMayApprove' => ($raw['supportMayApprove'] ?? true) !== false,
            'adminsMayApprove' => ($raw['adminsMayApprove'] ?? false) === true,
            'updatedBy' => (string) ($raw['updatedBy'] ?? ''),
            'updatedAt' => isset($raw['updatedAt']) ? (int) $raw['updatedAt'] : null,
        ];
    }

    private static function mcpSwitch($redis, array $mcp, string $key): bool
    {
        if (array_key_exists($key, $mcp)) {
            return $mcp[$key] === true;
        }
        return !self::serviceDisabled($redis);
    }

    public static function supportMayDecide($redis, string $extension): bool
    {
        return self::isSupportExtension($extension) && !self::serviceDisabled($redis);
    }

    private static function mergeSettings($redis, string $subdomain, array $changes, string $by): void
    {
        $raw = self::rawSettings($redis, $subdomain);
        foreach ($changes as $key => $value) {
            if ($key === 'mcp' && is_array($value)) {
                $current = isset($raw['mcp']) && is_array($raw['mcp']) ? $raw['mcp'] : [];
                $raw['mcp'] = array_merge($current, $value);
                continue;
            }
            $raw[$key] = $value;
        }
        $raw['updatedBy'] = $by;
        $raw['updatedAt'] = time();
        $redis->set(self::settingsKey($subdomain), json_encode($raw));
    }

    public static function setEnabled($redis, string $subdomain, bool $enabled, string $by): array
    {
        $raw = self::rawSettings($redis, $subdomain);
        $wasOn = self::isEnabled($raw);
        $changes = ['enabled' => $enabled];
        if (!$enabled) {
            $changes['supportLevel'] = self::LEVEL_OFF;
        } elseif (!$wasOn) {
            $changes['supportLevel'] = self::LEVEL_READ;
        } elseif (!array_key_exists('supportLevel', $raw)) {
            $changes['supportLevel'] = self::levelOf($raw);
        }
        self::mergeSettings($redis, $subdomain, $changes, $by);
        $revoked = $enabled ? 0 : self::revokeAll($redis, $subdomain);
        return ['settings' => self::settings($redis, $subdomain), 'revoked' => $revoked];
    }

    public static function setSupportLevel($redis, string $subdomain, string $level, string $by): array
    {
        if (!self::isLevel($level)) {
            throw new \InvalidArgumentException('unknown support level');
        }
        $previous = self::settings($redis, $subdomain)['supportLevel'];
        self::mergeSettings($redis, $subdomain, [
            'enabled' => $level !== self::LEVEL_OFF,
            'supportLevel' => $level,
        ], $by);
        $revoked = $level === self::LEVEL_OFF ? self::revokeAll($redis, $subdomain) : 0;
        return [
            'settings' => self::settings($redis, $subdomain),
            'previous' => $previous,
            'revoked' => $revoked,
        ];
    }

    public static function setSupportMayApprove($redis, string $subdomain, bool $allowed, string $by): array
    {
        self::mergeSettings($redis, $subdomain, ['supportMayApprove' => $allowed], $by);
        return self::settings($redis, $subdomain);
    }

    public static function setAdminsMayApprove($redis, string $subdomain, bool $allowed, string $by): array
    {
        self::mergeSettings($redis, $subdomain, ['adminsMayApprove' => $allowed], $by);
        return self::settings($redis, $subdomain);
    }

    public static function setCustomerMcp($redis, string $subdomain, array $changes, string $by): array
    {
        $clean = [];
        foreach (['enabled', 'writesAllowed'] as $key) {
            if (array_key_exists($key, $changes)) {
                $clean[$key] = (bool) $changes[$key];
            }
        }
        if ($clean !== []) {
            self::mergeSettings($redis, $subdomain, ['mcp' => $clean], $by);
        }
        return self::settings($redis, $subdomain);
    }

    const ACTIVITY_LIMIT = 25;
    const ACTIVITY_MAX = 100;
    const ACTIVITY_RESULTS = ['success', 'error', 'refused', 'denied'];
    const ACTIVITY_SOURCES = ['staff', 'customer', 'all'];

    public static function activityFilters(array $query): array
    {
        $limit = (int) ($query['limit'] ?? 0);
        $limit = $limit <= 0 ? self::ACTIVITY_LIMIT : min($limit, self::ACTIVITY_MAX);
        $before = (int) ($query['before'] ?? 0);
        $tool = (string) ($query['tool'] ?? '');
        $result = (string) ($query['result'] ?? '');
        $source = (string) ($query['source'] ?? '');
        return [
            'limit' => $limit,
            'before' => $before > 0 ? $before : 0,
            'actor' => mb_substr(trim((string) ($query['actor'] ?? '')), 0, 190),
            'tool' => preg_match('/^[a-z0-9_-]{1,64}$/', $tool) ? $tool : '',
            'result' => in_array($result, self::ACTIVITY_RESULTS, true) ? $result : '',
            'source' => in_array($source, self::ACTIVITY_SOURCES, true) ? $source : '',
        ];
    }

    public static function activityRow(array $row): array
    {
        $staff = (string) ($row['feature'] ?? '') === 'support-mcp';
        $email = (string) ($row['user_email'] ?? '');
        $name = (string) ($row['user_fullname'] ?? '');
        return [
            'id' => (int) ($row['id'] ?? 0),
            'at' => (string) ($row['occurred_at'] ?? ''),
            'actor' => $email !== '' ? $email : $name,
            'actorKind' => $staff ? 'staff' : 'customer',
            'grant' => $staff ? $name : '',
            'tool' => (string) ($row['action'] ?? ''),
            'detail' => (string) ($row['entity'] ?? ''),
            'result' => (string) ($row['result'] ?? ''),
            'ip' => (string) ($row['ip_address'] ?? ''),
            'undo' => self::undoOf($row),
        ];
    }

    public static function undoOf(array $row): ?array
    {
        if ((string) ($row['feature'] ?? '') !== 'ai-mcp' || (string) ($row['result'] ?? '') !== 'success') {
            return null;
        }
        $meta = json_decode((string) ($row['metadata'] ?? ''), true);
        $revert = is_array($meta) ? ($meta['revert'] ?? null) : null;
        if (!is_array($revert) || (string) ($revert['operationId'] ?? '') === '' || !is_array($revert['arguments'] ?? null)) {
            return null;
        }
        return ['available' => true, 'operationId' => (string) $revert['operationId']];
    }

    public static function undoKey(string $domainUuid, int $id): string
    {
        return 'mcp:undone:' . $domainUuid . ':' . $id;
    }

    public static function capabilities(): array
    {
        return self::CAPABILITIES;
    }

    public static function serviceDisabled($redis): bool
    {
        return (string) $redis->get(self::SERVICE_KEY) !== '';
    }

    public static function service($redis): array
    {
        if (!self::serviceDisabled($redis)) {
            return ['enabled' => true, 'updatedBy' => '', 'updatedAt' => null];
        }
        $raw = json_decode((string) $redis->get(self::SERVICE_KEY), true);
        $raw = is_array($raw) ? $raw : [];
        return [
            'enabled' => false,
            'updatedBy' => (string) ($raw['by'] ?? ''),
            'updatedAt' => isset($raw['at']) ? (int) $raw['at'] : null,
        ];
    }

    public static function setServiceEnabled($redis, bool $enabled, string $by): array
    {
        if ($enabled) {
            $redis->del(self::SERVICE_KEY);
        } else {
            $redis->set(self::SERVICE_KEY, json_encode(['by' => $by, 'at' => time()]));
        }
        return self::service($redis);
    }

    public static function newToken(): string
    {
        return self::TOKEN_PREFIX . bin2hex(random_bytes(32));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormed(string $token): bool
    {
        return strlen($token) === strlen(self::TOKEN_PREFIX) + 64
            && strpos($token, self::TOKEN_PREFIX) === 0
            && ctype_xdigit(substr($token, strlen(self::TOKEN_PREFIX)));
    }

    public static function bearerFrom($header): string
    {
        $header = trim((string) $header);
        if (stripos($header, 'bearer ') !== 0) {
            return '';
        }
        return trim(substr($header, 7));
    }

    public static function cleanLabel($label): string
    {
        $label = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $label);
        $label = trim(preg_replace('/\s+/u', ' ', (string) $label));
        return mb_substr($label, 0, 80);
    }

    /**
     * The engineer a grant is for.
     *
     * R1 of the requirements asks the token to carry the staff member's email.
     * This is asserted by the CUSTOMER, not verified by us -- there is no
     * central staff directory in this architecture -- so it is recorded as a
     * claim and the screens say so. It is still worth having: it puts a name in
     * the audit column built for one, instead of leaving it null.
     */
    /**
     * Which changes this grant permits.
     *
     * Absent means ALL of them, by the operator's decision -- a customer who
     * does not touch the list has not thereby withheld everything. An explicit
     * empty list is honoured as "read only", because that is somebody choosing
     * to withhold rather than not answering. Unknown names are dropped rather
     * than stored, so a future rename cannot leave a grant permitting something
     * no longer named.
     */
    public static function cleanFixActions($value): array
    {
        if ($value === null) {
            return self::FIX_ACTIONS;
        }
        if (!is_array($value)) {
            return self::FIX_ACTIONS;
        }
        $out = [];
        foreach ($value as $item) {
            $name = trim((string) $item);
            if (in_array($name, self::FIX_ACTIONS, true) && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /** Whether a tool is one of the changes a grant has to permit. */
    public static function isFixAction(string $name): bool
    {
        return in_array($name, self::FIX_ACTIONS, true);
    }

    /**
     * The one gate. A change the grant does not permit is refused here, whether
     * or not the tool exists yet.
     */
    public static function permits(array $grant, string $tool, string $level = self::LEVEL_OFF): bool
    {
        if (!self::isFixAction($tool)) {
            return true;
        }
        if ($level !== self::LEVEL_READWRITE) {
            return false;
        }
        $allowed = isset($grant['fixActions']) && is_array($grant['fixActions']) ? $grant['fixActions'] : [];
        return in_array($tool, $allowed, true);
    }

    public static function cleanEngineerEmail($value): string
    {
        $email = trim((string) $value);
        if ($email === '' || strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return '';
        }
        return $email;
    }

    public static function issue($redis, string $subdomain, string $label, int $ttl, array $creator, string $engineerEmail = '', $fixActions = null): array
    {
        $id = bin2hex(random_bytes(8));
        $token = self::newToken();
        $hash = self::hashToken($token);
        $now = time();
        $grant = [
            'id' => $id,
            'label' => $label,
            'engineerEmail' => self::cleanEngineerEmail($engineerEmail),
            'fixActions' => self::cleanFixActions($fixActions),
            'scope' => self::SCOPE_READ,
            'tokenHash' => $hash,
            'createdBy' => (string) ($creator['name'] ?? ''),
            'createdByExtension' => (string) ($creator['extension'] ?? ''),
            'createdAt' => $now,
            'expiresAt' => $now + $ttl,
            'lastUsedAt' => null,
            'uses' => 0,
        ];
        $redis->setex(self::grantKey($subdomain, $id), $ttl, json_encode($grant));
        $redis->setex(self::tokenKey($subdomain, $hash), $ttl, $id);
        $redis->sadd(self::indexKey($subdomain), $id);
        return ['token' => $token, 'grant' => self::publicView($grant)];
    }

    public static function load($redis, string $subdomain, string $id): ?array
    {
        if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
            return null;
        }
        $grant = json_decode((string) $redis->get(self::grantKey($subdomain, $id)), true);
        return is_array($grant) ? $grant : null;
    }

    public static function active($redis, string $subdomain): array
    {
        $out = [];
        foreach ((array) $redis->smembers(self::indexKey($subdomain)) as $id) {
            $grant = self::load($redis, $subdomain, (string) $id);
            if ($grant === null || (int) $grant['expiresAt'] <= time()) {
                $redis->srem(self::indexKey($subdomain), (string) $id);
                continue;
            }
            $out[] = self::publicView($grant);
        }
        usort($out, function ($a, $b) {
            return $b['createdAt'] <=> $a['createdAt'];
        });
        return $out;
    }

    public static function publicView(array $grant): array
    {
        return [
            'id' => (string) $grant['id'],
            'label' => (string) $grant['label'],
            'engineerEmail' => (string) ($grant['engineerEmail'] ?? ''),
            'fixActions' => isset($grant['fixActions']) && is_array($grant['fixActions']) ? array_values($grant['fixActions']) : [],
            'scope' => (string) $grant['scope'],
            'createdBy' => (string) $grant['createdBy'],
            'createdByExtension' => (string) ($grant['createdByExtension'] ?? ''),
            'createdAt' => (int) $grant['createdAt'],
            'expiresAt' => (int) $grant['expiresAt'],
            'lastUsedAt' => $grant['lastUsedAt'] === null ? null : (int) $grant['lastUsedAt'],
            'uses' => (int) $grant['uses'],
        ];
    }

    public static function revoke($redis, string $subdomain, string $id): bool
    {
        $grant = self::load($redis, $subdomain, $id);
        $redis->srem(self::indexKey($subdomain), $id);
        if ($grant === null) {
            return false;
        }
        $redis->del(self::tokenKey($subdomain, (string) $grant['tokenHash']));
        $redis->del(self::grantKey($subdomain, $id));
        return true;
    }

    public static function revokeAll($redis, string $subdomain): int
    {
        $count = 0;
        foreach ((array) $redis->smembers(self::indexKey($subdomain)) as $id) {
            if (self::revoke($redis, $subdomain, (string) $id)) {
                $count++;
            }
        }
        $redis->del(self::indexKey($subdomain));
        return $count;
    }

    public static function authorize($redis, string $subdomain, string $token): ?array
    {
        if ($subdomain === '' || !self::isWellFormed($token)) {
            return null;
        }
        if (self::serviceDisabled($redis) || !self::settings($redis, $subdomain)['enabled']) {
            return null;
        }
        $hash = self::hashToken($token);
        $id = (string) $redis->get(self::tokenKey($subdomain, $hash));
        if ($id === '') {
            return null;
        }
        $grant = self::load($redis, $subdomain, $id);
        if ($grant === null
            || !hash_equals((string) $grant['tokenHash'], $hash)
            || (int) $grant['expiresAt'] <= time()
            || ($grant['scope'] ?? '') !== self::SCOPE_READ) {
            return null;
        }
        return $grant;
    }

    public static function touch($redis, string $subdomain, array $grant): void
    {
        $ttl = (int) $grant['expiresAt'] - time();
        if ($ttl <= 0) {
            return;
        }
        $grant['lastUsedAt'] = time();
        $grant['uses'] = (int) $grant['uses'] + 1;
        $redis->setex(self::grantKey($subdomain, (string) $grant['id']), $ttl, json_encode($grant));
    }

    public static function rateLimited($redis, string $subdomain, string $grantId): bool
    {
        $grantKey = self::rateKey('grant', $subdomain . ':' . $grantId);
        $tenantKey = self::rateKey('tenant', $subdomain);
        $grantCount = (int) $redis->incr($grantKey);
        if ($grantCount === 1) {
            $redis->expire($grantKey, self::RATE_WINDOW * 2);
        }
        $tenantCount = (int) $redis->incr($tenantKey);
        if ($tenantCount === 1) {
            $redis->expire($tenantKey, self::RATE_WINDOW * 2);
        }
        return $grantCount > self::RATE_PER_GRANT || $tenantCount > self::RATE_PER_TENANT;
    }
}
