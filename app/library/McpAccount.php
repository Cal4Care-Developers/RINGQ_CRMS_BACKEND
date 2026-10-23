<?php

class McpAccount
{
    const CURATED_TIERS = [
        'get_extension' => 'user',
        'search_call_logs' => 'user',
        'list_registrations' => 'user',
        'list_live_calls' => 'admin',
        'get_trunk_status' => 'admin',
        'trace_number' => 'admin',
        'get_call_sip_trace' => 'admin',
        'get_call_debug_log' => 'admin',
        'get_audit_log' => 'admin',
        'get_system_overview' => 'owner',
        'get_service_status' => 'owner',
        'get_security_blocks' => 'owner',
        'get_ssl_and_storage' => 'owner',
    ];
    const USER_PINNED = [
        'get_extension' => 'extension',
        'search_call_logs' => 'extension',
        'list_registrations' => 'extension',
    ];
    const GENERIC = ['get_account_info', 'ringq_api_search', 'ringq_api_details', 'ringq_api_read', 'ringq_api_write'];
    const APPROVAL_TTL = 86400;
    const IDEM_TTL = 86400;
    const IDEM_PENDING_TTL = 120;
    const RATE_WINDOW = 60;
    const RATE = ['read' => 60, 'write' => 20, 'dangerous' => 5];
    const RATE_TENANT_FACTOR = 5;
    const ACCOUNT_DISABLED_KEY = 'mcp_account:disabled';
    const WRITES_DISABLED_KEY = 'mcp_account:writes_disabled';
    const SCHEMA_KEY = 'mcp_account:schema_ready';
    const TABLES = ['q_mcp_clients', 'q_mcp_sessions', 'q_mcp_agent_keys', 'q_mcp_approvals'];
    const SCHEMA = [
        "CREATE TABLE IF NOT EXISTS public.q_mcp_clients (
            client_id VARCHAR(64) PRIMARY KEY,
            client_name VARCHAR(200) NOT NULL DEFAULT '',
            redirect_uris JSONB NOT NULL DEFAULT '[]'::jsonb,
            created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
            last_used_at TIMESTAMPTZ
        )",
        "CREATE TABLE IF NOT EXISTS public.q_mcp_sessions (
            session_id VARCHAR(64) PRIMARY KEY,
            domain_uuid VARCHAR(64) NOT NULL,
            user_uuid VARCHAR(64) NOT NULL,
            client_id VARCHAR(64) NOT NULL,
            client_name VARCHAR(200) NOT NULL DEFAULT '',
            scopes VARCHAR(64) NOT NULL DEFAULT 'read',
            resource VARCHAR(255) NOT NULL DEFAULT '',
            refresh_hash CHAR(64) NOT NULL,
            prev_refresh_hash CHAR(64),
            created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
            last_used_at TIMESTAMPTZ,
            revoked_at TIMESTAMPTZ,
            revoked_by VARCHAR(255)
        )",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_q_mcp_sessions_refresh ON public.q_mcp_sessions (refresh_hash)",
        "CREATE INDEX IF NOT EXISTS idx_q_mcp_sessions_domain ON public.q_mcp_sessions (domain_uuid, user_uuid)",
        "CREATE TABLE IF NOT EXISTS public.q_mcp_agent_keys (
            key_id VARCHAR(64) PRIMARY KEY,
            domain_uuid VARCHAR(64) NOT NULL,
            user_uuid VARCHAR(64) NOT NULL,
            name VARCHAR(60) NOT NULL,
            key_hash CHAR(64) NOT NULL,
            scopes VARCHAR(64) NOT NULL DEFAULT 'read',
            ip_allowlist JSONB NOT NULL DEFAULT '[]'::jsonb,
            created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
            last_used_at TIMESTAMPTZ,
            expires_at TIMESTAMPTZ,
            revoked_at TIMESTAMPTZ
        )",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_q_mcp_agent_keys_hash ON public.q_mcp_agent_keys (key_hash)",
        "CREATE INDEX IF NOT EXISTS idx_q_mcp_agent_keys_domain ON public.q_mcp_agent_keys (domain_uuid)",
        "CREATE TABLE IF NOT EXISTS public.q_mcp_approvals (
            approval_id VARCHAR(64) PRIMARY KEY,
            domain_uuid VARCHAR(64) NOT NULL,
            user_uuid VARCHAR(64) NOT NULL,
            actor_kind VARCHAR(16) NOT NULL,
            actor_ref VARCHAR(64) NOT NULL,
            actor_label VARCHAR(255) NOT NULL DEFAULT '',
            operation_id VARCHAR(128) NOT NULL,
            args JSONB NOT NULL DEFAULT '{}'::jsonb,
            args_hash CHAR(64) NOT NULL,
            summary TEXT NOT NULL DEFAULT '',
            changes JSONB NOT NULL DEFAULT '[]'::jsonb,
            created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
            expires_at TIMESTAMPTZ NOT NULL,
            decided_at TIMESTAMPTZ,
            decided_by VARCHAR(255),
            decision VARCHAR(16),
            executed_at TIMESTAMPTZ
        )",
        "CREATE INDEX IF NOT EXISTS idx_q_mcp_approvals_domain ON public.q_mcp_approvals (domain_uuid, decision)",
    ];

    public static function tierForMode(int $mode): string
    {
        if ($mode === 7) {
            return 'owner';
        }
        if ($mode === 10) {
            return 'user';
        }
        return 'admin';
    }

    public static function tierLabel(string $tier): string
    {
        return ['owner' => 'Account owner', 'admin' => 'Admin', 'user' => 'User'][$tier] ?? 'User';
    }

    public static function curatedAllowed(string $tool, string $tier): bool
    {
        return isset(self::CURATED_TIERS[$tool])
            && McpCatalog::tierRank($tier) >= McpCatalog::tierRank(self::CURATED_TIERS[$tool]);
    }

    public static function pin(string $tool, array $args, string $tier, string $ownExtension): array
    {
        if ($tier === 'user' && isset(self::USER_PINNED[$tool])) {
            $args[self::USER_PINNED[$tool]] = $ownExtension;
        }
        return $args;
    }

    public static function writesOpen(array $mcp, string $scope, bool $killed, bool $planAllowsWrites = true): bool
    {
        return !$killed && $planAllowsWrites && $scope === McpOAuth::SCOPE_WRITE && !empty($mcp['enabled']) && !empty($mcp['writesAllowed']);
    }

    public static function cleanIpRange(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $parts = explode('/', $value, 2);
        $packed = @inet_pton($parts[0]);
        if ($packed === false) {
            return null;
        }
        $address = inet_ntop($packed);
        if (!isset($parts[1])) {
            return $address;
        }
        $max = strlen($packed) * 8;
        if (!preg_match('/^[0-9]{1,3}$/', $parts[1]) || (int) $parts[1] > $max) {
            return null;
        }
        return $address . '/' . (int) $parts[1];
    }

    public static function cleanExpiry($value)
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (is_int($value) || (is_string($value) && preg_match('/^[0-9]{1,12}$/', $value))) {
            return (int) $value;
        }
        if (is_string($value)) {
            $at = strtotime($value);
            return $at === false ? false : $at;
        }
        return false;
    }

    public static function planName($db, string $domainUuid): ?string
    {
        try {
            $row = $db->fetchOne(
                'SELECT "packageName" AS package FROM q_companies WHERE domain_uuid::text = :d LIMIT 1',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid]
            );
        } catch (\Throwable $e) {
            return null;
        }
        return $row ? trim((string) ($row['package'] ?? '')) : '';
    }

    public static function planAllowsWrites(?string $packageName): bool
    {
        if ($packageName === null) {
            return false;
        }
        return stripos($packageName, 'essential') === false;
    }

    public static function scopeOffered(array $mcp, bool $planAllowsWrites): string
    {
        if (empty($mcp['enabled'])) {
            return 'none';
        }
        return !empty($mcp['writesAllowed']) && $planAllowsWrites ? McpOAuth::SCOPE_WRITE : McpOAuth::SCOPE_READ;
    }

    public static function canApprove(string $tier, bool $adminsMayApprove): bool
    {
        return $tier === 'owner' || ($tier === 'admin' && $adminsMayApprove);
    }

    public static function dangerousAllowed(string $tier, bool $adminsMayApprove): bool
    {
        return self::canApprove($tier, $adminsMayApprove);
    }

    public static function customerVisible(array $ops, string $tier, bool $adminsMayApprove): array
    {
        $dangerous = self::dangerousAllowed($tier, $adminsMayApprove);
        return array_filter($ops, function ($op) use ($dangerous) {
            return $dangerous || (string) ($op['access'] ?? '') !== 'dangerous';
        });
    }

    public static function customerRefusal(array $op, string $tier, bool $adminsMayApprove, bool $planAllowsWrites): ?array
    {
        $access = (string) ($op['access'] ?? '');
        if ($access === 'read') {
            return null;
        }
        if (!$planAllowsWrites) {
            return ['plan_read_only', 'Your RingQ plan includes read-only AI access. Upgrade the plan to let assistants make changes.'];
        }
        if ($access === 'dangerous' && !self::dangerousAllowed($tier, $adminsMayApprove)) {
            return ['not_permitted', 'High-risk changes need an account owner. An owner can let admins request them in AI & MCP.'];
        }
        return null;
    }

    public static function mayDecide(string $tier, string $viewerUuid, bool $adminsMayApprove, ?array $request, bool $serverWide = false): ?string
    {
        if ($request === null) {
            return 'That request was not found, has expired, or was already decided.';
        }
        if ($serverWide) {
            return 'This change affects every account on this shared server, so a RingQ Admin approves it, not an account owner.';
        }
        if ($tier === 'owner') {
            return null;
        }
        if (!self::canApprove($tier, $adminsMayApprove)) {
            return 'Only an account owner can approve this.';
        }
        if ((string) ($request['actor_kind'] ?? '') === 'staff') {
            return 'Only an account owner can approve a request from RingQ support.';
        }
        if ($viewerUuid === '' || hash_equals((string) ($request['user_uuid'] ?? ''), $viewerUuid)) {
            return 'You cannot approve your own request. Ask an account owner or another admin.';
        }
        return null;
    }

    public static function approvalRequest($db, string $domainUuid, string $approvalId): ?array
    {
        if (!preg_match('/^appr_[0-9a-f]{24}$/', $approvalId)) {
            return null;
        }
        $row = $db->fetchOne(
            "SELECT actor_kind, user_uuid, operation_id FROM q_mcp_approvals
              WHERE approval_id = :id AND domain_uuid = :d AND decision IS NULL AND expires_at > now()",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['id' => $approvalId, 'd' => $domainUuid]
        );
        return $row ?: null;
    }

    public static function permits(array $op, string $tier, string $scope, bool $writesOpen): ?string
    {
        if (!McpCatalog::allowedFor($op, $tier)) {
            return 'Your RingQ role cannot use this operation.';
        }
        if ($op['access'] !== 'read' && !$writesOpen) {
            return 'Changes are not allowed on this connection. An account owner must allow assistants to make changes, and you must have connected with Read & write.';
        }
        if (!McpOAuth::scopeAllows($scope, (string) $op['access'])) {
            return 'This connection is read only.';
        }
        return null;
    }

    public static function isSingleInstance(string $host): bool
    {
        $host = trim($host);
        return $host !== '' && count(explode('.', $host)) < 4;
    }

    public static function instanceRefusal(array $op, string $host, bool $staff = false): ?string
    {
        if (!$staff && self::serverWideOnShared($op, $host)) {
            return 'This server is shared by several accounts, so this operation affects all of them. Ask RingQ support to run it.';
        }
        return null;
    }

    public static function serverWideOnShared(?array $op, string $host): bool
    {
        return $op !== null && !empty($op['singleInstanceOnly']) && !self::isSingleInstance($host);
    }

    public static function toolsFor(string $tier, bool $writesOpen): array
    {
        $tools = [
            [
                'name' => 'get_account_info',
                'description' => 'Who you are on this RingQ account: your name, extension, role and what this connection may do.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
            ],
            [
                'name' => 'ringq_api_search',
                'description' => 'Find RingQ operations by what you want to do, e.g. "change my work hours" or "list aux codes". Returns operation ids for ringq_api_details, ringq_api_read and ringq_api_write.',
                'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'What you want to do.']], 'required' => ['query']],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
            ],
            [
                'name' => 'ringq_api_details',
                'description' => 'The parameters, access class and dry-run support of one operation.',
                'inputSchema' => ['type' => 'object', 'properties' => ['operationId' => ['type' => 'string']], 'required' => ['operationId']],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
            ],
            [
                'name' => 'ringq_api_read',
                'description' => 'Run a read operation (access "read") with its arguments. Never changes anything.',
                'inputSchema' => ['type' => 'object', 'properties' => ['operationId' => ['type' => 'string'], 'arguments' => ['type' => 'object']], 'required' => ['operationId']],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
            ],
        ];
        if ($writesOpen) {
            $tools[] = [
                'name' => 'ringq_api_write',
                'description' => 'Run an operation that CHANGES the account. Call it with dryRun: true first to see what would change. Pass an idempotencyKey so a retry cannot apply twice. High-risk operations return an approvalId that an account owner must approve in RingQ before you call again with that approvalId.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'operationId' => ['type' => 'string'],
                    'arguments' => ['type' => 'object'],
                    'dryRun' => ['type' => 'boolean', 'description' => 'Show what would change without changing it.'],
                    'idempotencyKey' => ['type' => 'string', 'description' => 'Any unique string per intended change.'],
                    'approvalId' => ['type' => 'string', 'description' => 'The approved request to carry out.'],
                ], 'required' => ['operationId']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
            ];
        }
        foreach (McpTools::describe() as $tool) {
            if (self::curatedAllowed($tool['name'], $tier)) {
                $tool['annotations'] = ['readOnlyHint' => true, 'destructiveHint' => false];
                if ($tier === 'user' && isset(self::USER_PINNED[$tool['name']])) {
                    $tool['description'] .= ' Limited to your own extension.';
                }
                $tools[] = $tool;
            }
        }
        return $tools;
    }

    public static function rateKey(string $actorRef, string $class): string
    {
        return 'mcp_account_rate:' . $class . ':' . $actorRef;
    }

    public static function rateLimited($redis, string $actorRef, string $class): bool
    {
        $key = self::rateKey($actorRef, $class);
        $n = (int) $redis->incr($key);
        if ($n === 1) {
            $redis->expire($key, self::RATE_WINDOW);
        }
        return $n > (self::RATE[$class] ?? 20);
    }

    public static function tenantRateKey(string $bucket, string $class): string
    {
        return 'mcp_account_rate_tenant:' . $class . ':' . $bucket;
    }

    public static function rateRefusal($redis, string $bucket, string $actorRef, string $class): ?string
    {
        if (self::rateLimited($redis, $actorRef, $class)) {
            return "Too many requests. Wait a minute and try again.";
        }
        if ($bucket === '') {
            return null;
        }
        $key = self::tenantRateKey($bucket, $class);
        $n = (int) $redis->incr($key);
        if ($n === 1) {
            $redis->expire($key, self::RATE_WINDOW);
        }
        if ($n > (self::RATE[$class] ?? 20) * self::RATE_TENANT_FACTOR) {
            return "This account has reached its AI request limit for this minute. Wait a minute and try again.";
        }
        return null;
    }

    public static function idemKey(string $domainUuid, string $actorRef, string $key): string
    {
        return 'mcp_idem:' . $domainUuid . ':' . $actorRef . ':' . hash('sha256', $key);
    }

    public static function idemBegin($redis, string $key)
    {
        $existing = $redis->get($key);
        if ($existing !== null && $existing !== false) {
            $decoded = json_decode((string) $existing, true);
            return is_array($decoded) ? $decoded : ['pending' => true];
        }
        $set = $redis->set($key, json_encode(['pending' => true]), 'EX', self::IDEM_PENDING_TTL, 'NX');
        if (!$set) {
            return ['pending' => true];
        }
        return null;
    }

    public static function idemEnd($redis, string $key, ?array $result): void
    {
        if ($result === null) {
            $redis->del($key);
            return;
        }
        $redis->setex($key, self::IDEM_TTL, json_encode(['result' => $result]));
    }

    public static function createApproval($db, array $actor, array $op, array $args, string $summary, array $changes): string
    {
        $id = 'appr_' . bin2hex(random_bytes(12));
        $db->execute(
            "INSERT INTO q_mcp_approvals (approval_id, domain_uuid, user_uuid, actor_kind, actor_ref, actor_label, operation_id, args, args_hash, summary, changes, expires_at)
             VALUES (:id, :d, :u, :k, :r, :l, :op, CAST(:args AS jsonb), :h, :s, CAST(:c AS jsonb), now() + interval '24 hours')",
            [
                'id' => $id,
                'd' => (string) $actor['domain_uuid'],
                'u' => (string) $actor['user_uuid'],
                'k' => (string) $actor['kind'],
                'r' => (string) $actor['ref'],
                'l' => mb_substr((string) $actor['label'], 0, 255),
                'op' => (string) $op['id'],
                'args' => json_encode(McpCatalog::redact($args), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'h' => McpCatalog::argsHash((string) $op['id'], $args),
                's' => mb_substr($summary, 0, 500),
                'c' => json_encode(array_values($changes), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );
        return $id;
    }

    public static function consumeApproval($db, string $approvalId, array $actor, string $operationId, array $args): ?string
    {
        if (!preg_match('/^appr_[0-9a-f]{24}$/', $approvalId)) {
            return 'That approval id is not valid.';
        }
        $row = $db->fetchOne(
            "SELECT decision, executed_at, expires_at > now() AS live, actor_ref, args_hash, operation_id FROM q_mcp_approvals WHERE approval_id = :id AND domain_uuid = :d",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['id' => $approvalId, 'd' => (string) $actor['domain_uuid']]
        );
        if (!$row) {
            return 'That approval was not found.';
        }
        if ((string) $row['actor_ref'] !== (string) $actor['ref'] || (string) $row['operation_id'] !== $operationId
            || !hash_equals(trim((string) $row['args_hash']), McpCatalog::argsHash($operationId, $args))) {
            return 'That approval is for a different request. Send exactly the arguments that were approved.';
        }
        if ($row['executed_at'] !== null) {
            return 'That approved request has already been carried out.';
        }
        if ($row['decision'] === 'deny') {
            return 'An account owner denied this request.';
        }
        if ($row['decision'] !== 'approve') {
            return $row['live'] === true || $row['live'] === 't' ? 'This request is still waiting for an account owner to approve it.' : 'This request expired before it was approved.';
        }
        $db->execute(
            "UPDATE q_mcp_approvals SET executed_at = now() WHERE approval_id = :id AND decision = 'approve' AND executed_at IS NULL",
            ['id' => $approvalId]
        );
        return $db->affectedRows() === 1 ? null : 'That approved request has already been carried out.';
    }

    public static function pendingApprovals($db, string $domainUuid, ?array $viewer = null): array
    {
        $rows = $db->fetchAll(
            "SELECT approval_id, actor_kind, actor_label, user_uuid, operation_id, summary, changes, created_at, expires_at
               FROM q_mcp_approvals
              WHERE domain_uuid = :d AND decision IS NULL AND expires_at > now()
              ORDER BY created_at DESC LIMIT 100",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['d' => $domainUuid]
        );
        $ops = McpCatalog::load();
        if ($viewer !== null && (string) $viewer['tier'] !== 'owner') {
            $rows = array_values(array_filter($rows, function ($r) {
                return (string) $r['actor_kind'] !== 'staff';
            }));
        }
        return array_map(function ($r) use ($ops, $viewer) {
            $op = $ops[(string) $r['operation_id']] ?? null;
            $row = [
                'id' => (string) $r['approval_id'],
                'summary' => (string) $r['summary'],
                'tool' => (string) $r['operation_id'],
                'endpoint' => $op ? strtoupper((string) $op['request']['method']) . ' ' . (string) $op['request']['path'] : '',
                'requestedBy' => (string) $r['actor_label'],
                'requestedByKind' => in_array($r['actor_kind'], ['key', 'staff'], true) ? (string) $r['actor_kind'] : 'customer',
                'requestedAt' => strtotime((string) $r['created_at']) ?: 0,
                'expiresAt' => strtotime((string) $r['expires_at']) ?: 0,
                'changes' => json_decode((string) $r['changes'], true) ?: [],
            ];
            if ($viewer !== null) {
                $row['canDecide'] = self::mayDecide((string) $viewer['tier'], (string) $viewer['user_uuid'], (bool) $viewer['adminsMayApprove'], $r) === null;
            }
            return $row;
        }, $rows);
    }

    public static function decideApproval($db, string $domainUuid, string $approvalId, string $decision, string $by): bool
    {
        if (!preg_match('/^appr_[0-9a-f]{24}$/', $approvalId) || !in_array($decision, ['approve', 'deny'], true)) {
            return false;
        }
        $db->execute(
            "UPDATE q_mcp_approvals SET decision = :dec, decided_at = now(), decided_by = :by
              WHERE approval_id = :id AND domain_uuid = :d AND decision IS NULL AND expires_at > now()",
            ['dec' => $decision, 'by' => mb_substr($by, 0, 255), 'id' => $approvalId, 'd' => $domainUuid]
        );
        return $db->affectedRows() === 1;
    }

    public static function schemaReady($db, $redis): bool
    {
        $cached = $redis->get(self::SCHEMA_KEY);
        if ($cached === '1') {
            return true;
        }
        if (!self::tablesPresent($db)) {
            try {
                foreach (self::SCHEMA as $sql) {
                    $db->execute($sql);
                }
            } catch (\Throwable $e) {
                error_log("mcp schema: " . $e->getMessage());
                return false;
            }
            if (!self::tablesPresent($db)) {
                return false;
            }
        }
        $redis->setex(self::SCHEMA_KEY, 300, '1');
        return true;
    }

    private static function tablesPresent($db): bool
    {
        try {
            foreach (self::TABLES as $table) {
                $row = $db->fetchOne("SELECT to_regclass(:t) AS t", \Phalcon\Db\Enum::FETCH_ASSOC, ['t' => 'public.' . $table]);
                if (!$row || $row['t'] === null) {
                    return false;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }
        return true;
    }

    public static function capabilities(bool $ready): array
    {
        return [
            'customerMcp' => $ready,
            'oauth' => $ready,
            'sessions' => $ready,
            'agentKeys' => $ready,
            'approvals' => $ready,
        ];
    }
}
