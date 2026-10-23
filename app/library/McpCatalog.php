<?php

class McpCatalog
{
    const TIERS = ['user' => 1, 'admin' => 2, 'owner' => 3];
    const CLASSES = ['read', 'write', 'dangerous'];
    const MAX_RESULT_BYTES = 60000;
    const MAX_LIST_ITEMS = 50;
    const ALWAYS_REDACT = [
        'password', 'sip_password', 'sip_uuid', 'vm_password', 'voicemail_password', 'push_token', 'push_token2',
        'token', 'access_token', 'refresh_token', 'api_key', 'apikey', 'secret', 'client_secret', 'walltoken',
        'record_path', 'record_name', 'auth_password', 'extension_password', 'mac_password', 'otp', 'code_hash',
    ];
    const TRANSFORMS = ['office_hours', 'break_hours', 'schedule_global', 'schedule_after_hours', 'holiday', 'ivr_menu', 'trunk', 'did_route', 'did_unroute', 'ivr_delete', 'did_add', 'did_delete', 'extension_forwarding', 'storage_policy'];
    const IVR_KEYS = ['1' => '', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6', '7' => '7', '8' => '8', '9' => '9', '0' => '0', '*' => '11', '#' => '12'];
    const DID_KINDS = ['ivr' => '1', 'queue' => '2', 'extension' => '3', 'conference' => '4', 'group' => '7'];
    const TRUNK_REACTIONS = ['From User', 'From Display', 'Invite', 'To User'];
    const FAILURE_STATUS = ['validation' => 400, 'forbidden' => 403, 'not_found' => 404, 'conflict' => 409, 'server' => 502];
    const FORWARDING_STATUSES = ['available', 'away', 'busy', 'custom1', 'custom2'];
    const FORWARDING_SIDES = ['internal' => '', 'external' => '2'];
    const FORWARDING_FIELDS = [
        'always_on' => ['forward_all_enabled', 'flag'],
        'always_to' => ['forward_all_destination', 'destination'],
        'busy_on' => ['forward_busy_enabled', 'flag'],
        'busy_to' => ['forward_busy_destination', 'destination'],
        'no_answer_on' => ['forward_no_answer_enabled', 'flag'],
        'no_answer_to' => ['forward_no_answer_destination', 'destination'],
        'no_answer_seconds' => ['forward_no_answer_timeout', 'seconds'],
        'screening_on' => ['call_screening', 'flag'],
        'parallel_ring_on' => ['simultaneous_destination_enabled', 'flag'],
    ];
    const DESK_PHONE_BRANDS = ['yealink', 'fanvil', 'snom', 'grandstream'];
    const DESK_PHONE_STUNS = ['stun.ringq.com', 'stun1.ringq.com', 'stun2.ringq.com', 'stun1.l.google.com', 'stun2.l.google.com'];
    const DAY_CODES = ['sunday' => '1', 'monday' => '2', 'tuesday' => '3', 'wednesday' => '4', 'thursday' => '5', 'friday' => '6', 'saturday' => '7'];

    private static $cache = null;

    public static function path(): string
    {
        return dirname(__DIR__) . '/config/mcp-catalog.php';
    }

    public static function load(?string $path = null): array
    {
        if ($path === null && self::$cache !== null) {
            return self::$cache;
        }
        $file = $path ?? self::path();
        $doc = is_file($file) ? require $file : null;
        $ops = [];
        foreach ((array) ($doc['x-ringq-operations'] ?? []) as $op) {
            if (is_array($op) && isset($op['id'])) {
                $ops[(string) $op['id']] = $op;
            }
        }
        if ($path === null) {
            self::$cache = $ops;
        }
        return $ops;
    }

    public static function tierRank(string $tier): int
    {
        return self::TIERS[$tier] ?? 0;
    }

    public static function allowedFor(array $op, string $tier): bool
    {
        return self::tierRank($tier) >= self::tierRank((string) ($op['minTier'] ?? 'owner'))
            && in_array((string) ($op['access'] ?? ''), self::CLASSES, true);
    }

    public static function visible(array $ops, string $tier, string $scope): array
    {
        return array_filter($ops, function ($op) use ($tier, $scope) {
            return self::allowedFor($op, $tier) && McpOAuth::scopeAllows($scope, (string) $op['access']);
        });
    }

    public static function tokens(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_filter($words, function ($w) {
            return strlen($w) > 1 && !in_array($w, ['the', 'my', 'an', 'to', 'of', 'and', 'for', 'on', 'in', 'is', 'me', 'a'], true);
        })));
    }

    public static function search(array $ops, string $query, int $limit = 8): array
    {
        $q = self::tokens($query);
        $scored = [];
        foreach ($ops as $id => $op) {
            $hay = strtolower($id . ' ' . ($op['summary'] ?? '') . ' ' . implode(' ', (array) ($op['keywords'] ?? [])));
            $score = 0;
            foreach ($q as $word) {
                if (strpos($hay, $word) !== false) {
                    $score += 2;
                }
                foreach ((array) ($op['keywords'] ?? []) as $kw) {
                    if (strtolower((string) $kw) === $word) {
                        $score += 3;
                    }
                }
            }
            if ($q === [] || $score > 0) {
                $scored[] = ['score' => $score, 'op' => $op];
            }
        }
        usort($scored, function ($a, $b) {
            return $b['score'] <=> $a['score'] ?: strcmp($a['op']['id'], $b['op']['id']);
        });
        return array_map(function ($s) {
            return self::brief($s['op']);
        }, array_slice($scored, 0, $limit));
    }

    public static function brief(array $op): array
    {
        return [
            'operationId' => (string) $op['id'],
            'summary' => (string) ($op['summary'] ?? ''),
            'access' => (string) $op['access'],
            'needsApproval' => $op['access'] === 'dangerous',
            'tool' => $op['access'] === 'read' ? 'ringq_api_read' : 'ringq_api_write',
        ];
    }

    public static function details(array $op): array
    {
        $params = [];
        foreach ((array) ($op['params'] ?? []) as $name => $p) {
            $params[$name] = array_intersect_key((array) $p, array_flip(['type', 'required', 'enum', 'default', 'minimum', 'maximum', 'maxLength', 'pattern', 'description', 'items', 'maxItems', 'properties', 'requiredProperties']));
        }
        return self::brief($op) + [
            'parameters' => (object) $params,
            'dryRun' => $op['access'] !== 'read' && isset($op['preview']),
        ];
    }

    public static function checkValue($value, array $schema, string $name): ?string
    {
        $type = (string) ($schema['type'] ?? 'string');
        if ($type === 'integer') {
            if (!is_int($value) && !(is_string($value) && preg_match('/^-?[0-9]+$/', $value))) {
                return "$name must be a whole number.";
            }
            $value = (int) $value;
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                return "$name must be at least {$schema['minimum']}.";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                return "$name must be at most {$schema['maximum']}.";
            }
            return null;
        }
        if ($type === 'array') {
            if (!is_array($value) || array_values($value) !== $value) {
                return "$name must be a list.";
            }
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                return "$name can hold at most {$schema['maxItems']} entries.";
            }
            foreach ($value as $i => $item) {
                $err = self::checkValue($item, (array) ($schema['items'] ?? []), $name . '[' . $i . ']');
                if ($err !== null) {
                    return $err;
                }
            }
            return null;
        }
        if ($type === 'object') {
            if (!is_array($value)) {
                return "$name must be an object.";
            }
            $props = (array) ($schema['properties'] ?? []);
            foreach ((array) ($schema['requiredProperties'] ?? []) as $needed) {
                if (!array_key_exists($needed, $value)) {
                    return "$name.$needed is required.";
                }
            }
            foreach ($value as $k => $v) {
                if (!isset($props[$k])) {
                    return "$name has an unknown field: $k.";
                }
                $err = self::checkValue($v, (array) $props[$k], $name . '.' . $k);
                if ($err !== null) {
                    return $err;
                }
            }
            return null;
        }
        if (!is_string($value) && !is_int($value)) {
            return "$name must be text.";
        }
        $value = (string) $value;
        if (isset($schema['enum']) && !in_array($value, array_map('strval', (array) $schema['enum']), true)) {
            return "$name must be one of: " . implode(', ', (array) $schema['enum']) . '.';
        }
        if (isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
            return "$name cannot be empty.";
        }
        if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
            return "$name is longer than {$schema['maxLength']} characters.";
        }
        if (isset($schema['pattern']) && $value !== '' && !preg_match('/' . str_replace('/', '\/', $schema['pattern']) . '/uD', $value)) {
            return "$name is not in the expected format.";
        }
        return null;
    }

    public static function validate(array $op, $args): array
    {
        if (!is_array($args)) {
            return ['error' => 'arguments must be an object.'];
        }
        $params = (array) ($op['params'] ?? []);
        foreach (array_keys($args) as $k) {
            if (!isset($params[$k])) {
                return ['error' => "Unknown argument: $k. Call ringq_api_details for the parameters."];
            }
        }
        $clean = [];
        foreach ($params as $name => $schema) {
            if (!array_key_exists($name, $args)) {
                if (!empty($schema['required'])) {
                    return ['error' => "Missing required argument: $name."];
                }
                if (array_key_exists('default', $schema)) {
                    $clean[$name] = $schema['default'];
                }
                continue;
            }
            $err = self::checkValue($args[$name], (array) $schema, $name);
            if ($err !== null) {
                return ['error' => $err];
            }
            $clean[$name] = $args[$name];
        }
        if (isset($op['transform']) && !self::isMerge($op)) {
            $err = self::transformError((string) $op['transform'], $clean);
            if ($err !== null) {
                return ['error' => $err];
            }
        }
        if (isset($op['range'])) {
            $err = self::rangeError((array) $op['range'], $clean);
            if ($err !== null) {
                return ['error' => $err];
            }
        }
        return ['args' => $clean];
    }

    public static function buildRequest(array $op, array $args): array
    {
        $req = (array) $op['request'];
        $path = (string) $req['path'];
        $body = (array) ($req['fixed'] ?? []);
        $query = [];
        foreach ((array) ($op['params'] ?? []) as $name => $schema) {
            if (!array_key_exists($name, $args)) {
                continue;
            }
            $in = (string) ($schema['in'] ?? 'body');
            $value = $args[$name];
            if ($in === 'path') {
                $path = str_replace('{' . $name . '}', rawurlencode((string) $value), $path);
            } elseif ($in === 'query') {
                $query[$name] = $value;
            } elseif ($in === 'body') {
                $body[$name] = is_int($value) ? (string) $value : $value;
            }
        }
        if (isset($op['transform'])) {
            $body = array_merge($body, self::transformBody((string) $op['transform'], $args));
        }
        if (isset($req['bodyParam'])) {
            $body = (array) ($args[(string) $req['bodyParam']] ?? []);
        }
        return [
            'method' => (string) ($req['method'] ?? 'GET'),
            'path' => $path,
            'encoding' => (string) ($req['encoding'] ?? 'none'),
            'body' => $body,
            'query' => $query,
        ];
    }

    public static function summaryFor(array $op, array $args): string
    {
        $template = (string) ($op['summaryTemplate'] ?? ($op['summary'] ?? $op['id']));
        return trim(preg_replace_callback('/\{([a-z0-9_]+)\}/', function ($m) use ($args) {
            $v = $args[$m[1]] ?? '';
            if (is_array($v) && $v === array_values($v) && count(array_filter($v, 'is_scalar')) === count($v)) {
                $v = implode(', ', $v);
            }
            return is_scalar($v) ? mb_substr((string) $v, 0, 80) : '';
        }, $template));
    }

    public static function redact($value, array $extra = [])
    {
        $deny = array_merge(self::ALWAYS_REDACT, $extra);
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), $deny, true)) {
                continue;
            }
            $out[$k] = self::redact($v, $extra);
        }
        return $out;
    }

    public static function trim($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_values($value) === $value;
        if ($isList && count($value) > self::MAX_LIST_ITEMS) {
            $value = array_slice($value, 0, self::MAX_LIST_ITEMS);
            $value[] = ['_truncated' => 'More items exist; request the next page.'];
        }
        foreach ($value as $k => $v) {
            $value[$k] = self::trim($v);
        }
        return $value;
    }

    public static function shape($body, array $op)
    {
        $clean = self::trim(self::redact($body, (array) ($op['redact'] ?? [])));
        $json = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false && strlen($json) > self::MAX_RESULT_BYTES) {
            return ['_truncated' => 'The answer was too large to return in full.', 'preview' => mb_substr($json, 0, self::MAX_RESULT_BYTES)];
        }
        return $clean;
    }

    public static function previewChanges(array $op, array $args, $previewBody): array
    {
        $preview = (array) ($op['preview'] ?? []);
        $kind = (string) ($preview['kind'] ?? '');
        $fields = (array) ($preview['fields'] ?? []);
        $changes = [];
        if ($kind === 'create') {
            $refusal = self::createRefusal($preview, $args, $previewBody);
            if ($refusal !== null) {
                return ['found' => false, 'changes' => [], 'error' => $refusal];
            }
            foreach ($fields as $arg => $label) {
                $after = $args[$arg] ?? '';
                if (is_array($after)) {
                    $after = implode(', ', array_filter($after, 'is_scalar'));
                }
                if ((string) $after !== '') {
                    $changes[] = ['field' => $arg, 'label' => $label, 'before' => null, 'after' => $after];
                }
            }
            return ['found' => true, 'changes' => $changes];
        }
        $items = self::dig($previewBody, (string) ($preview['items'] ?? ''));
        if ($kind === 'replace') {
            return ['found' => true, 'changes' => [['field' => 'all', 'label' => 'Whole schedule', 'before' => $items, 'after' => array_values((array) ($args[(string) ($op['request']['bodyParam'] ?? '')] ?? []))]]];
        }
        if ($kind === 'object') {
            $current = is_array($items) ? $items : null;
            if ($current === null) {
                return ['found' => false, 'changes' => []];
            }
            $refusal = self::previewRefusal($preview, $args, $current, self::dig($previewBody, (string) ($preview['guardItems'] ?? '')));
            if ($refusal !== null) {
                return ['found' => false, 'changes' => [], 'error' => $refusal];
            }
            if ($fields === []) {
                foreach ((array) ($op['params'] ?? []) as $name => $schema) {
                    if ((string) ($schema['in'] ?? 'body') !== 'none') {
                        $fields[$name] = $name;
                    }
                }
            }
            foreach ($fields as $arg => $field) {
                if (!array_key_exists($arg, $args)) {
                    continue;
                }
                $before = $current[$field] ?? null;
                if (!self::sameValue($before, $args[$arg])) {
                    $changes[] = ['field' => $field, 'label' => $field, 'before' => $before, 'after' => $args[$arg]];
                }
            }
            return ['found' => true, 'changes' => $changes];
        }
        if ($kind === 'match') {
            $key = (string) ($preview['key'] ?? '');
            $argKey = (string) ($preview['argKey'] ?? $key);
            $current = null;
            foreach ((array) $items as $item) {
                if (is_array($item) && (string) ($item[$key] ?? '') === (string) ($args[$argKey] ?? '')) {
                    $current = $item;
                    break;
                }
            }
            if ($current === null) {
                return ['found' => false, 'changes' => []];
            }
            $refusal = self::previewRefusal($preview, $args, $current, $current);
            if ($refusal !== null) {
                return ['found' => false, 'changes' => [], 'error' => $refusal];
            }
            foreach ($fields as $arg => $field) {
                if (!empty($preview['partial']) && !array_key_exists($arg, $args)) {
                    continue;
                }
                $before = $current[$field] ?? null;
                $after = !empty($preview['deletes']) ? null : ($args[$arg] ?? null);
                if (!self::sameValue($before, $after)) {
                    $changes[] = ['field' => $field, 'label' => $field, 'before' => $before, 'after' => $after];
                }
            }
            return ['found' => true, 'changes' => $changes];
        }
        return ['found' => false, 'changes' => []];
    }

    public static function revertRecord(array $op, array $args, array $changes): array
    {
        $preview = (array) ($op['preview'] ?? []);
        $kind = (string) ($preview['kind'] ?? '');
        $record = ['changes' => array_values($changes), 'revert' => null, 'reason' => ''];
        if ($changes === []) {
            $record['reason'] = 'Nothing changed, so there is nothing to undo.';
            return $record;
        }
        if ($kind === 'create') {
            $record['reason'] = 'This created something new. To undo it, delete it.';
            return $record;
        }
        if ($kind === 'match' && !empty($preview['deletes'])) {
            $record['reason'] = 'This removed something. It has to be added back by hand; the values it had are listed in changes.';
            return $record;
        }
        if ($kind !== 'object' && $kind !== 'match') {
            $record['reason'] = 'This kind of change cannot be undone automatically; the previous values are listed in changes.';
            return $record;
        }
        $fields = (array) ($preview['fields'] ?? []);
        if ($fields === []) {
            foreach ((array) ($op['params'] ?? []) as $name => $schema) {
                if ((string) ($schema['in'] ?? 'body') !== 'none') {
                    $fields[$name] = $name;
                }
            }
        }
        $argFor = array_flip(array_map('strval', $fields));
        $revert = $args;
        foreach ($changes as $change) {
            $field = (string) ($change['field'] ?? '');
            if (!isset($argFor[$field]) || !array_key_exists('before', $change) || $change['before'] === null) {
                $record['reason'] = 'A previous value is missing, so this cannot be undone automatically; see changes.';
                return $record;
            }
            $revert[$argFor[$field]] = $change['before'];
        }
        $record['revert'] = ['operationId' => (string) ($op['id'] ?? ''), 'arguments' => $revert];
        return $record;
    }

    public static function sameValue($a, $b): bool
    {
        if (is_array($a) || is_array($b) || is_object($a) || is_object($b)) {
            return self::canonical($a) === self::canonical($b);
        }
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }
        return (string) $a === (string) $b;
    }

    public static function canonical($value): string
    {
        $value = json_decode((string) json_encode($value), true);
        $sort = function ($v) use (&$sort) {
            if (!is_array($v)) {
                return is_scalar($v) ? (string) $v : $v;
            }
            ksort($v);
            return array_map($sort, $v);
        };
        return (string) json_encode($sort($value));
    }

    public static function createRefusal(array $preview, array $args, $previewBody): ?string
    {
        foreach ((array) ($preview['checks'] ?? []) as $check) {
            $wanted = (string) ($args[(string) ($check['arg'] ?? '')] ?? '');
            if ($wanted === '') {
                continue;
            }
            $key = (string) ($check['key'] ?? '');
            $found = false;
            foreach ((array) self::dig($previewBody, (string) ($check['items'] ?? '')) as $item) {
                if (is_array($item) && (string) ($item[$key] ?? '') === $wanted) {
                    $found = true;
                    break;
                }
            }
            if ($found !== !empty($check['present'])) {
                return str_replace('{value}', $wanted, (string) ($check['message'] ?? 'This change is not allowed here.'));
            }
        }
        return null;
    }

    public static function previewRefusal(array $preview, array $args, array $current, $guardItems): ?string
    {
        foreach ((array) ($preview['locked'] ?? []) as $arg => $field) {
            if (array_key_exists($arg, $args) && !self::sameValue($current[$field] ?? null, $args[$arg])) {
                $now = is_scalar($current[$field] ?? null) ? (string) $current[$field] : 'set';
                return !empty($preview['deletes']) ? "$arg does not match; it is $now. Check you are deleting the right one." : "$arg cannot be changed here; it is $now.";
            }
        }
        $guard = is_array($guardItems) ? $guardItems : [];
        foreach ((array) ($preview['refuse'] ?? []) as $rule) {
            $have = $guard[(string) ($rule['field'] ?? '')] ?? null;
            if (array_key_exists('lacksArg', $rule)) {
                $wanted = (string) ($args[(string) $rule['lacksArg']] ?? '');
                if ($wanted === '' && !empty($rule['optional'])) {
                    continue;
                }
                if ($wanted === '' || !is_array($have) || !in_array($wanted, array_map('strval', $have), true)) {
                    return (string) ($rule['message'] ?? 'This change is not allowed here.');
                }
                continue;
            }
            if (!empty($rule['nonEmpty'])) {
                if (is_array($have) && $have !== []) {
                    return str_replace('{value}', implode(', ', array_map('strval', $have)), (string) ($rule['message'] ?? 'This change is not allowed here.'));
                }
                continue;
            }
            $hit = array_key_exists('equals', $rule) ? self::sameValue($have, $rule['equals']) : (array_key_exists('notEquals', $rule) && !self::sameValue($have, $rule['notEquals']));
            if ($hit) {
                return (string) ($rule['message'] ?? 'This change is not allowed here.');
            }
        }
        return null;
    }

    public static function failure(array $result): array
    {
        $status = (int) ($result['status'] ?? 0);
        $body = $result['body'] ?? null;
        $message = is_array($body) ? trim((string) ($body['message'] ?? '')) : '';
        $appCode = '';
        if (preg_match('/\s*\(Code ([A-Z]{2}[0-9]{4})\)\s*$/', $message, $m)) {
            $appCode = $m[1];
            $message = trim(substr($message, 0, -strlen($m[0])));
        }
        if (!empty($result['busy'])) {
            $code = 'server';
            $message = 'RingQ is busy. Try again in a moment.';
        } elseif (!is_array($body)) {
            $code = $status === 401 || $status === 403 ? 'forbidden' : ($status === 404 ? 'not_found' : 'server');
            $message = $status === 0 ? 'RingQ could not be reached.' : 'RingQ failed while handling that request and returned no usable answer (HTTP ' . $status . ').';
        } elseif ($message === '') {
            $code = 'server';
            $message = 'RingQ failed while handling that request (HTTP ' . $status . ').';
        } elseif ($status === 401 || preg_match('/unath?orized|unauthori[sz]ed|sign in/i', $message)) {
            $code = 'forbidden';
        } elseif ($status === 403 || preg_match('/permission|not allowed|only access your own|forbidden|cannot be (changed|deleted) here/i', $message)) {
            $code = 'forbidden';
        } elseif ($status === 404 || preg_match('/does ?not exists?|non-existing|not exist|not found|no (queue|ivr|did|extension|trunk|gateway)[^.]* with/i', $message)) {
            $code = 'not_found';
        } elseif ($status === 409 || preg_match('/already (exist|been|assigned|in use|existing)|duplicate/i', $message)) {
            $code = 'conflict';
        } elseif ($status >= 500) {
            $code = 'server';
        } else {
            $code = 'validation';
        }
        $out = ['result' => 'error', 'message' => $message, 'code' => $code, 'status' => self::FAILURE_STATUS[$code]];
        if ($appCode !== '') {
            $out['appCode'] = $appCode;
        }
        return $out;
    }

    public static function rangeError(array $range, array $args): ?string
    {
        $from = (string) ($range['start'] ?? 'start');
        $to = (string) ($range['end'] ?? 'end');
        $maxDays = (int) ($range['maxDays'] ?? 31);
        $a = (int) ($args[$from] ?? 0);
        $b = (int) ($args[$to] ?? 0);
        if ($a <= 0 || $b <= 0) {
            return "$from and $to are required.";
        }
        if ($b < $a) {
            return "$to must be after $from.";
        }
        if ($b - $a > $maxDays * 86400) {
            return "The range can cover at most $maxDays days.";
        }
        return null;
    }

    public static function isMerge(array $op): bool
    {
        return (string) ($op['preview']['kind'] ?? '') === 'object' && !empty($op['preview']['merge']);
    }

    public static function mergeCurrent(array $op, array $args, $previewBody): array
    {
        $preview = (array) ($op['preview'] ?? []);
        $current = self::dig($previewBody, (string) ($preview['items'] ?? ''));
        if (!is_array($current)) {
            return ['error' => 'The current values could not be read, so nothing was changed.'];
        }
        $refusal = self::previewRefusal($preview, $args, $current, self::dig($previewBody, (string) ($preview['guardItems'] ?? '')));
        if ($refusal !== null) {
            return ['error' => $refusal];
        }
        foreach ((array) ($op['params'] ?? []) as $name => $schema) {
            if ((string) ($schema['in'] ?? 'body') === 'none' || !array_key_exists($name, $current)) {
                continue;
            }
            if (array_key_exists($name, $args)) {
                if ((string) ($schema['type'] ?? '') === 'object' && is_array($args[$name]) && is_array($current[$name])) {
                    $args[$name] = array_replace($current[$name], $args[$name]);
                }
                continue;
            }
            $value = $current[$name];
            $err = self::checkValue($value, (array) $schema, $name);
            if ($err !== null) {
                return ['error' => "The current value of $name cannot be kept by this operation ($err) Change it in the RingQ app instead."];
            }
            $args[$name] = $value;
        }
        if (isset($op['transform'])) {
            $err = self::transformError((string) $op['transform'], $args);
            if ($err !== null) {
                return ['error' => $err];
            }
        }
        return ['args' => $args];
    }

    public static function previewArgs(array $op, array $args): array
    {
        $out = [];
        foreach ((array) ($op['preview']['args'] ?? []) as $key => $value) {
            if (is_string($value) && preg_match('/^\{([A-Za-z0-9_]+)\}$/', $value, $m)) {
                if (array_key_exists($m[1], $args)) {
                    $out[$key] = $args[$m[1]];
                }
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    public static function minutes($value, bool $allowEndOfDay = false): ?int
    {
        $value = (string) $value;
        if ($allowEndOfDay && $value === '24:00') {
            return 1440;
        }
        if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $value, $m)) {
            return null;
        }
        return (int) $m[1] * 60 + (int) $m[2];
    }

    public static function dayCode($day): ?string
    {
        return self::DAY_CODES[strtolower((string) $day)] ?? null;
    }

    public static function afterHours($value): ?array
    {
        $value = (string) $value;
        if ($value === 'hangup') {
            return ['voicemail' => '0', 'destination_action' => ''];
        }
        if (preg_match('/^vm:([0-9]+)$/', $value, $m)) {
            return ['voicemail' => '1', 'destination_action' => $m[1]];
        }
        if (preg_match('/^[0-9]+$/', $value)) {
            return ['voicemail' => '0', 'destination_action' => $value];
        }
        return null;
    }

    public static function destination($value): ?array
    {
        $value = (string) $value;
        if ($value === '' || $value === 'hangup') {
            return ['kind' => 'hangup', 'number' => ''];
        }
        if (preg_match('/^vm:([0-9]{2,10})$/', $value, $m)) {
            return ['kind' => 'voicemail', 'number' => $m[1]];
        }
        if (preg_match('/^[0-9]{2,10}$/', $value)) {
            return ['kind' => 'transfer', 'number' => $value];
        }
        return null;
    }

    public static function forwardingWire(string $kind, $value, string $extension = ''): ?string
    {
        $value = is_scalar($value) ? (string) $value : '';
        if ($kind === 'flag') {
            return in_array($value, ['true', 'false'], true) ? $value : null;
        }
        if ($kind === 'seconds') {
            return preg_match('/^[0-9]{1,3}$/D', $value) && (int) $value >= 5 && (int) $value <= 300 ? (string) (int) $value : null;
        }
        if ($value === 'voicemail') {
            return $extension === '' ? '' : '*99' . $extension;
        }
        if ($value === 'hangup') {
            return 'hangup';
        }
        if (preg_match('/^vm:([0-9]{2,10})$/D', $value, $m)) {
            return '*99' . $m[1];
        }
        return preg_match('/^[0-9]{2,20}$/D', $value) ? $value : null;
    }

    public static function forwardingSlot(string $kind, $stored, string $extension): string
    {
        $raw = $stored === null ? '' : (string) $stored;
        if ($kind === 'flag') {
            return $raw === 'true' || $raw === '1' ? 'true' : 'false';
        }
        if ($kind === 'seconds') {
            return preg_match('/^[0-9]+$/D', $raw) ? (string) max(5, min(300, (int) $raw)) : '30';
        }
        if ($raw === '') {
            return 'voicemail';
        }
        if ($raw === 'hangup') {
            return 'hangup';
        }
        $voicemail = strpos($raw, '*99') === 0;
        $digits = preg_replace('/[^0-9]/', '', $voicemail ? substr($raw, 3) : $raw);
        if ($digits === '') {
            return 'voicemail';
        }
        if ($voicemail) {
            return $digits === $extension ? 'voicemail' : 'vm:' . $digits;
        }
        return $digits;
    }

    public static function deskPhoneMac($value): ?string
    {
        $plain = strtolower(str_replace([':', '-', '.', ' '], '', trim((string) $value)));
        if (!preg_match('/^[0-9a-f]{12}$/D', $plain)) {
            return null;
        }
        return implode(':', str_split($plain, 2));
    }

    public static function deskPhoneEntries(array $list): array
    {
        $phones = [];
        foreach ($list as $entry) {
            $parts = explode('@', (string) $entry);
            if (count($parts) < 5 || trim($parts[2]) === '') {
                continue;
            }
            $phones[] = [
                'mac' => self::deskPhoneMac($parts[2]) ?? strtolower(trim($parts[2])),
                'brand' => strtolower(trim($parts[0])),
                'model' => (string) $parts[1],
                'stun' => (string) $parts[3],
                'timezone' => (string) $parts[4],
                'hostUrl' => (string) ($parts[5] ?? ''),
                'routerTunnel' => (string) ($parts[6] ?? ''),
            ];
        }
        return $phones;
    }

    public static function deskPhoneChoices($licence): array
    {
        $data = is_string($licence) ? json_decode($licence, true) : $licence;
        $models = is_array($data) ? (array) ($data['data']['autoProvision'] ?? []) : [];
        $zones = is_array($data) ? (array) ($data['data']['timezoneProvision'] ?? []) : [];
        $brands = [];
        foreach (self::DESK_PHONE_BRANDS as $brand) {
            $names = [];
            foreach ((array) ($models[$brand] ?? []) as $item) {
                $name = is_array($item) ? trim((string) ($item['model'] ?? '')) : '';
                if ($name === '' || trim((string) ($item['type'] ?? '')) === '' || strpbrk($name, '@,') !== false || in_array($name, $names, true)) {
                    continue;
                }
                $names[] = $name;
            }
            $timezones = [];
            foreach ((array) ($zones[$brand] ?? []) as $zone) {
                $value = is_array($zone) ? (string) ($zone['value'] ?? '') : '';
                if ($value === '' || strpbrk($value, '@,') !== false) {
                    continue;
                }
                $timezones[] = ['value' => $value, 'text' => (string) ($zone['text'] ?? $value)];
            }
            if ($names !== []) {
                $brands[] = ['name' => $brand, 'models' => $names, 'timezones' => $timezones];
            }
        }
        return $brands;
    }

    public static function forwardingEdit(array $behavior, string $extension): array
    {
        $edit = [];
        foreach (self::FORWARDING_SIDES as $side => $suffix) {
            foreach (self::FORWARDING_FIELDS as $field => $spec) {
                $stored = explode(',', (string) ($behavior[$spec[0] . $suffix] ?? ''));
                $slots = [];
                foreach (self::FORWARDING_STATUSES as $i => $status) {
                    $slots[$status] = self::forwardingSlot($spec[1], $stored[$i] ?? null, $extension);
                }
                $edit[$side . '_' . $field] = $slots;
            }
        }
        return $edit;
    }

    public static function calendarDate($value): ?array
    {
        if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', (string) $value, $m)) {
            return null;
        }
        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        if (!checkdate($month, $day, $year)) {
            return null;
        }
        return ['year' => $year, 'month' => $month, 'day' => $day];
    }

    public static function transformError(string $name, array $args): ?string
    {
        if (!in_array($name, self::TRANSFORMS, true)) {
            return 'This operation is not available.';
        }
        if ($name === 'ivr_menu') {
            if (self::destination($args['timeoutAction'] ?? '') === null) {
                return 'timeoutAction must be hangup, vm:<extension> or a number.';
            }
            foreach ((array) ($args['options'] ?? []) as $key => $value) {
                if (!isset(self::IVR_KEYS[(string) $key])) {
                    return "options has an unknown key: $key.";
                }
                if ((string) $value !== '' && self::destination($value) === null) {
                    return "options.$key must be hangup, vm:<extension> or a number.";
                }
            }
            return null;
        }
        if ($name === 'trunk') {
            if (!preg_match('/^[a-zA-Z0-9]{4,100}$/', (string) ($args['name'] ?? ''))) {
                return 'name must be 4 to 100 letters or digits.';
            }
            if ((string) ($args['auth'] ?? 'register') === 'register') {
                foreach (['username', 'password'] as $needed) {
                    if (strlen((string) ($args[$needed] ?? '')) < 4) {
                        return "$needed is required, at least 4 characters, when the trunk registers.";
                    }
                }
            }
            return null;
        }
        if ($name === 'did_route') {
            if (!isset(self::DID_KINDS[(string) ($args['kind'] ?? '')])) {
                return 'kind must be one of: ' . implode(', ', array_keys(self::DID_KINDS)) . '.';
            }
            return null;
        }
        if ($name === 'did_unroute') {
            return (string) ($args['number'] ?? '') === '' || (string) ($args['extension'] ?? '') === '' ? 'number and extension are required.' : null;
        }
        if ($name === 'ivr_delete') {
            return (string) ($args['extension'] ?? '') === '' || (string) ($args['name'] ?? '') === '' ? 'extension and name are required.' : null;
        }
        if ($name === 'storage_policy') {
            return (string) ($args['category'] ?? '') === '' ? 'category is required.' : null;
        }
        if ($name === 'extension_forwarding') {
            foreach (self::FORWARDING_SIDES as $side => $suffix) {
                foreach (self::FORWARDING_FIELDS as $field => $spec) {
                    $param = $side . '_' . $field;
                    $value = $args[$param] ?? null;
                    if (!is_array($value)) {
                        return "$param is required.";
                    }
                    foreach (self::FORWARDING_STATUSES as $status) {
                        if (!array_key_exists($status, $value) || self::forwardingWire($spec[1], $value[$status]) === null) {
                            return "$param.$status is missing or not a valid value.";
                        }
                    }
                }
            }
            return null;
        }
        if ($name === 'did_add') {
            return (string) ($args['number'] ?? '') === '' || (string) ($args['trunk'] ?? '') === '' || (string) ($args['countryCode'] ?? '') === '' ? 'number, trunk and countryCode are required.' : null;
        }
        if ($name === 'did_delete') {
            return (string) ($args['number'] ?? '') === '' ? 'number is required.' : null;
        }
        if ($name === 'holiday') {
            $start = self::calendarDate($args['start'] ?? '');
            if ($start === null) {
                return 'start is not a real date.';
            }
            $end = self::calendarDate($args['end'] ?? '');
            if ($end === null) {
                return 'end is not a real date.';
            }
            if (sprintf('%04d%02d%02d', $end['year'], $end['month'], $end['day']) < sprintf('%04d%02d%02d', $start['year'], $start['month'], $start['day'])) {
                return 'The holiday must end on or after its start date.';
            }
            $from = self::minutes($args['from'] ?? '00:00');
            if ($from === null) {
                return 'from must be a time between 00:00 and 23:59.';
            }
            $to = self::minutes($args['to'] ?? '24:00', true);
            if ($to === null) {
                return 'to must be a time between 00:00 and 24:00.';
            }
            if ($from >= $to) {
                return 'The start time must be before the end time.';
            }
            if (isset($args['recurring']) && !in_array((string) $args['recurring'], ['yes', 'no'], true)) {
                return 'recurring must be yes or no.';
            }
            return null;
        }
        if (self::afterHours($args['afterHours'] ?? '') === null) {
            return 'afterHours must be hangup, vm:<extension> or an extension number.';
        }
        if ($name === 'schedule_global' || $name === 'schedule_after_hours') {
            if (!in_array((string) ($args['section'] ?? ''), ['office', 'break'], true)) {
                return 'section must be office or break.';
            }
            if ($name === 'schedule_global' && !in_array((string) ($args['follow'] ?? ''), ['yes', 'no'], true)) {
                return 'follow must be yes or no.';
            }
            return null;
        }
        if (self::dayCode($args['day'] ?? '') === null) {
            return 'day must be a day of the week.';
        }
        $from = $name === 'office_hours' ? 'open' : 'start';
        $to = $name === 'office_hours' ? 'close' : 'end';
        $a = self::minutes($args[$from] ?? '');
        $b = self::minutes($args[$to] ?? '');
        if ($a === null || $b === null) {
            return "$from and $to must be times between 00:00 and 23:59.";
        }
        if ($a >= $b) {
            return $name === 'office_hours' ? 'Opening time must be before closing time.' : 'The break must start before it ends.';
        }
        return null;
    }

    public static function transformBody(string $name, array $args): array
    {
        if ($name === 'storage_policy') {
            return ['policies' => json_encode([[
                'category' => (string) ($args['category'] ?? ''),
                'enabled' => (string) ($args['enabled'] ?? '0') === '1',
                'retentionDays' => (int) ($args['retentionDays'] ?? 90),
                'archive' => (string) ($args['archive'] ?? '0') === '1',
            ]])];
        }
        if ($name === 'ivr_menu') {
            $exit = self::destination($args['timeoutAction'] ?? 'hangup');
            if ($exit === null) {
                return [];
            }
            $body = [
                'ivr_type' => $exit['kind'] === 'hangup' ? '3' : ($exit['kind'] === 'voicemail' ? '2' : '1'),
                'ivr_menu_timeout' => (string) (int) ($args['timeoutSeconds'] ?? 30),
                'ivr_menu_exit_app' => $exit['number'],
                'ivr_menu_name' => (string) ($args['name'] ?? ''),
                'ivr_menu_extension' => (string) ($args['extension'] ?? ''),
                'ivr_menu_greet_long' => (string) ($args['greeting'] ?? ''),
            ];
            $options = (array) ($args['options'] ?? []);
            foreach (self::IVR_KEYS as $key => $suffix) {
                $dest = self::destination($options[$key] ?? '');
                $body['ivr_menu_option_type' . $suffix] = $dest !== null && $dest['kind'] === 'voicemail' ? '3' : '1';
                $body['ivr_menu_option_param' . $suffix] = $dest !== null ? $dest['number'] : '';
            }
            $body['ivr_menu_direct_dial'] = (string) ($args['directDial'] ?? 'no') === 'yes' ? '1' : '2';
            return $body;
        }
        if ($name === 'trunk') {
            return [
                'gateway_uuid' => (string) ($args['uuid'] ?? ''),
                'gateway' => strtolower(trim((string) ($args['name'] ?? ''))),
                'username' => (string) ($args['username'] ?? ''),
                'auth_username' => (string) ($args['authUsername'] ?? ''),
                'password' => (string) ($args['password'] ?? ''),
                'proxy' => trim((string) ($args['host'] ?? '')) . ':' . trim((string) ($args['port'] ?? '')),
                'outbound_caller_id' => (string) ($args['outboundCallerId'] ?? ''),
                'codec' => (string) ($args['codecs'] ?? ''),
                'max_calls' => (string) ($args['maxCalls'] ?? '0'),
                'authentication' => (string) ($args['auth'] ?? 'register') === 'ip' ? '2' : '1',
                'register_transport' => strtolower((string) ($args['transport'] ?? 'udp')) === 'tcp' ? 'TCP' : 'UDP',
                'expire_seconds' => (string) ($args['expireSeconds'] ?? '3600'),
                'retry_seconds' => (string) ($args['retrySeconds'] ?? '180'),
                'from_user' => (string) ($args['fromUser'] ?? 'From User'),
                'from_display' => (string) ($args['fromDisplay'] ?? 'From Display'),
                'did_match' => (string) ($args['didMatch'] ?? 'Invite'),
            ];
        }
        if ($name === 'ivr_delete') {
            return [
                'ivr_menu_extension' => (string) ($args['extension'] ?? ''),
                'ivr_menu_name' => (string) ($args['name'] ?? ''),
            ];
        }
        if ($name === 'did_add') {
            return [
                'did_number' => (string) ($args['number'] ?? ''),
                'sip_trunk_name' => (string) ($args['trunk'] ?? ''),
                'country' => (string) ($args['countryCode'] ?? ''),
            ];
        }
        if ($name === 'did_delete') {
            return ['did_number' => (string) ($args['number'] ?? '')];
        }
        if ($name === 'extension_forwarding') {
            $body = [];
            foreach (self::FORWARDING_SIDES as $side => $suffix) {
                foreach (self::FORWARDING_FIELDS as $field => $spec) {
                    $value = (array) ($args[$side . '_' . $field] ?? []);
                    $slots = [];
                    foreach (self::FORWARDING_STATUSES as $status) {
                        $slots[] = (string) self::forwardingWire($spec[1], $value[$status] ?? null, (string) ($args['extension'] ?? ''));
                    }
                    $body[$spec[0] . $suffix] = implode(',', $slots);
                }
            }
            return $body;
        }
        if ($name === 'did_unroute') {
            return [
                'extension' => (string) ($args['extension'] ?? ''),
                'destination_number' => (string) ($args['number'] ?? ''),
            ];
        }
        if ($name === 'did_route') {
            return [
                'type2' => self::DID_KINDS[(string) ($args['kind'] ?? '')] ?? '',
                'extension' => (string) ($args['extension'] ?? ''),
                'destination_number' => (string) ($args['number'] ?? ''),
                'destination_name' => (string) ($args['name'] ?? ''),
            ];
        }
        if ($name === 'holiday') {
            $start = self::calendarDate($args['start'] ?? '');
            $end = self::calendarDate($args['end'] ?? '');
            if ($start === null || $end === null) {
                return [];
            }
            return [
                'id' => isset($args['id']) && (string) $args['id'] !== '' ? (string) $args['id'] : '0',
                'day' => (string) $start['day'],
                'month' => (string) $start['month'],
                'year' => (string) $start['year'],
                'day2' => (string) $end['day'],
                'month2' => (string) $end['month'],
                'year2' => (string) $end['year'],
                'start_time' => (string) self::minutes($args['from'] ?? '00:00'),
                'end_time' => (string) self::minutes($args['to'] ?? '24:00', true),
                'recurring' => (string) ($args['recurring'] ?? 'no') === 'yes' ? '1' : '0',
                'holiday_name' => (string) ($args['name'] ?? ''),
                'holiday_greeting' => (string) ($args['greeting'] ?? ''),
            ];
        }
        $after = self::afterHours($args['afterHours'] ?? '');
        if ($after === null || !in_array($name, self::TRANSFORMS, true)) {
            return [];
        }
        $entity = (string) ($args['entity'] ?? '');
        if ($name === 'schedule_global') {
            $types = ['office' => ['yes' => '3', 'no' => '5'], 'break' => ['yes' => '4', 'no' => '6']];
            $type = $types[(string) ($args['section'] ?? '')][(string) ($args['follow'] ?? '')] ?? '';
            return ['type' => $type, 'extension' => $entity] + $after;
        }
        if ($name === 'schedule_after_hours') {
            return ['type' => (string) ($args['section'] ?? '') === 'break' ? '2' : '1', 'extension' => $entity] + $after;
        }
        $day = (string) self::dayCode($args['day'] ?? '');
        if ($name === 'office_hours') {
            return [
                'type' => '1',
                'extension' => $entity,
                'day_week' => $day,
                'start_time' => (string) ((int) self::minutes($args['close'] ?? '') + 1),
                'end_time' => (string) self::minutes($args['open'] ?? ''),
            ] + $after;
        }
        return [
            'type' => '2',
            'extension' => $entity,
            'day_week' => $day,
            'start_time' => (string) self::minutes($args['start'] ?? ''),
            'end_time' => (string) ((int) self::minutes($args['end'] ?? '') + 1),
        ] + $after;
    }

    public static function dig($value, string $path)
    {
        if ($path === '') {
            return $value;
        }
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public static function argsHash(string $operationId, array $args): string
    {
        $canon = $args;
        ksort($canon);
        return hash('sha256', $operationId . "\n" . json_encode($canon, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function appSucceeded($body): bool
    {
        if (!is_array($body)) {
            return false;
        }
        $result = strtolower((string) ($body['result'] ?? $body['status'] ?? ''));
        return in_array($result, ['success', 'ok', 'true', '1'], true);
    }
}
