<?php
declare(strict_types=1);

use Phalcon\Mvc\View;

require_once __DIR__ . '/../library/ApiAuth.php';
require_once __DIR__ . '/../library/ScheduleValidator.php';

class ScheduleApiException extends \RuntimeException
{
    /** @var string */
    private $apiCode;
    /** @var int */
    private $httpStatus;
    /** @var array<string, mixed> */
    private $details;

    public function __construct(string $apiCode, string $message, int $httpStatus, array $details = [])
    {
        parent::__construct($message);
        $this->apiCode = $apiCode;
        $this->httpStatus = $httpStatus;
        $this->details = $details;
    }

    public function apiCode(): string { return $this->apiCode; }
    public function httpStatus(): int { return $this->httpStatus; }
    /** @return array<string, mixed> */
    public function details(): array { return $this->details; }
}

/**
 * HMAC-authenticated, tenant-scoped schedules API.
 *
 * Nginx strips /scheduleApi/v1/ before proxying, so the convention router sees
 * /scheduleApi/schedules[/id], /scheduleApi/hosts and
 * /scheduleApi/availability. Every action authenticates independently because
 * this application's ACL hook does not cancel dispatch on a denial.
 */
class ScheduleApiController extends ControllerBase
{
    private const READ_LIMIT_PER_MINUTE = 120;
    private const WRITE_LIMIT_PER_MINUTE = 30;
    private const MAX_PAGE_SIZE = 100;
    private const IDEMPOTENCY_TTL = 86400;
    private const LOCK_TTL = 15;

    /** GET/POST/PATCH/DELETE /scheduleApi/v1/schedules[/id] */
    public function schedulesAction($id = null)
    {
        $method = strtoupper((string)$this->request->getMethod());
        $allowed = ['GET', 'POST', 'PATCH', 'DELETE'];
        if (!in_array($method, $allowed, true)) {
            return $this->error(405, 'method_not_allowed', 'This resource supports GET, POST, PATCH and DELETE.');
        }

        try {
            $permission = $method === 'DELETE'
                ? 'schedule_delete'
                : ($method === 'GET' ? 'schedule_read' : 'schedule_write');
            $context = $this->authorize($permission, $method !== 'GET');
            $data = $this->body($method === 'POST' || $method === 'PATCH');
            $host = $this->resolveHost($context['tenant']['domain_uuid'], $this->hostSelector($data));
            $key = $this->scheduleKey($host['extension'], $context['tenant']['domain_uuid']);

            if ($method === 'GET') {
                return $this->getSchedules($key, $host, $context['tenant'], $id);
            }
            if ($method === 'POST') {
                if ($id !== null && (string)$id !== '') {
                    throw new ScheduleApiException('invalid_request', 'POST does not accept a schedule id.', 400);
                }
                return $this->createSchedule($key, $host, $context, $data);
            }
            if ($id === null || trim((string)$id) === '') {
                throw new ScheduleApiException('invalid_request', 'A schedule id is required.', 400, ['field' => 'id']);
            }
            if ($method === 'PATCH') {
                return $this->updateSchedule($key, $host, $context, (string)$id, $data);
            }
            return $this->deleteSchedule($key, $host, $context, (string)$id);
        } catch (ScheduleValidationException $e) {
            return $this->error($e->httpStatus(), $e->apiCode(), $e->getMessage(), $e->details());
        } catch (ScheduleApiException $e) {
            return $this->error($e->httpStatus(), $e->apiCode(), $e->getMessage(), $e->details());
        } catch (\Throwable $e) {
            error_log('schedule api: ' . $e->getMessage());
            return $this->error(503, 'temporarily_unavailable', 'The schedules API is temporarily unavailable.');
        }
    }

    /** GET /scheduleApi/v1/hosts */
    public function hostsAction()
    {
        if (!$this->request->isGet()) {
            return $this->error(405, 'method_not_allowed', 'This resource supports GET.');
        }
        try {
            $context = $this->authorize('schedule_read', false);
            $rows = $this->hostDirectory($context['tenant']['domain_uuid']);
            $page = max(1, (int)$this->request->getQuery('page', 'int', 1));
            $limit = max(1, min(self::MAX_PAGE_SIZE, (int)$this->request->getQuery('limit', 'int', 50)));
            $total = count($rows);
            $rows = array_slice($rows, ($page - 1) * $limit, $limit);
            $hosts = [];
            foreach ($rows as $row) {
                $hosts[] = $this->publicHost($row);
            }
            return $this->success(200, [
                'data' => $hosts,
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
            ]);
        } catch (ScheduleApiException $e) {
            return $this->error($e->httpStatus(), $e->apiCode(), $e->getMessage(), $e->details());
        } catch (\Throwable $e) {
            error_log('schedule hosts api: ' . $e->getMessage());
            return $this->error(503, 'temporarily_unavailable', 'The schedules API is temporarily unavailable.');
        }
    }

    /** GET /scheduleApi/v1/availability?hostExtension=...&from=...&to=... */
    public function availabilityAction()
    {
        if (!$this->request->isGet()) {
            return $this->error(405, 'method_not_allowed', 'This resource supports GET.');
        }
        try {
            $context = $this->authorize('schedule_read', false);
            $host = $this->resolveHost($context['tenant']['domain_uuid'], $this->hostSelector([]));
            $from = ScheduleValidator::parseIso($this->request->getQuery('from'), 'from');
            $to = ScheduleValidator::parseIso($this->request->getQuery('to'), 'to');
            if ($to->getTimestamp() <= $from->getTimestamp()
                || ($to->getTimestamp() - $from->getTimestamp()) > 366 * 86400
            ) {
                throw new ScheduleApiException(
                    'invalid_time_range',
                    'to must be after from and the availability window must not exceed 366 days.',
                    400,
                    ['fields' => ['from', 'to']]
                );
            }

            $busy = [];
            foreach ($this->busyIntervals($host, $context['tenant']['domain_uuid']) as $item) {
                if (!ScheduleValidator::overlaps($from, $to, $item['startObject'], $item['endObject'])) {
                    continue;
                }
                unset($item['startObject'], $item['endObject']);
                $busy[] = $item;
            }
            usort($busy, function (array $left, array $right): int {
                return strcmp((string)$left['start'], (string)$right['start']);
            });
            return $this->success(200, [
                'host' => $this->publicHost($host),
                'from' => $from->format(\DateTimeInterface::ATOM),
                'to' => $to->format(\DateTimeInterface::ATOM),
                'busy' => $busy,
            ]);
        } catch (ScheduleValidationException $e) {
            return $this->error($e->httpStatus(), $e->apiCode(), $e->getMessage(), $e->details());
        } catch (ScheduleApiException $e) {
            return $this->error($e->httpStatus(), $e->apiCode(), $e->getMessage(), $e->details());
        } catch (\Throwable $e) {
            error_log('schedule availability api: ' . $e->getMessage());
            return $this->error(503, 'temporarily_unavailable', 'The schedules API is temporarily unavailable.');
        }
    }

    /** @return array<string, mixed> */
    private function authorize(string $permission, bool $write): array
    {
        try {
            $keyData = ApiAuth::authenticate($this->request, $this->redis);
        } catch (\Exception $e) {
            if ($e->getMessage() === 'AUTH_EXPIRED') {
                throw new ScheduleApiException(
                    'auth_expired',
                    'The request timestamp is outside the allowed 30 second window.',
                    401
                );
            }
            throw new ScheduleApiException('unauthorized', 'Authentication failed.', 401);
        }

        $apiKey = (string)$this->request->getHeader('X-API-KEY');
        $domain = strtolower(trim((string)($keyData['domain'] ?? '')));
        if ($domain === '') {
            throw new ScheduleApiException('forbidden', 'The API key has no tenant.', 403);
        }
        if (strpos($domain, ':') !== false) {
            $domain = explode(':', $domain)[0];
        }
        $tenant = $this->resolveTenant($domain);

        $permissions = $this->redis->hGetAll('call_api_permissions:' . $domain);
        if (empty($permissions[$permission])) {
            throw new ScheduleApiException(
                'forbidden',
                'The API key is not allowed to use ' . $permission . '.',
                403,
                ['permission' => $permission]
            );
        }
        $this->rateLimit($apiKey, $write);
        return ['apiKey' => $apiKey, 'keyData' => $keyData, 'tenant' => $tenant];
    }

    /** @return array<string, string> */
    private function resolveTenant(string $domain): array
    {
        $rows = $this->db->fetchAll(
            "SELECT domain_uuid, domain_name, domain_enabled
               FROM v_domains
              WHERE lower(domain_name) = :domain
              LIMIT 2",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['domain' => $domain]
        );
        if (count($rows) !== 1 || empty($rows[0]['domain_uuid'])) {
            throw new ScheduleApiException(
                'forbidden',
                'The API key tenant could not be resolved unambiguously.',
                403
            );
        }
        $enabled = $rows[0]['domain_enabled'] ?? 'true';
        if ($enabled === false || $enabled === 'false' || $enabled === 'f' || $enabled === '0') {
            throw new ScheduleApiException('forbidden', 'The API key tenant is disabled.', 403);
        }
        return [
            'domain_uuid' => (string)$rows[0]['domain_uuid'],
            'domain_name' => (string)$rows[0]['domain_name'],
        ];
    }

    private function rateLimit(string $apiKey, bool $write): void
    {
        $bucket = $write ? 'write' : 'read';
        $limit = $write ? self::WRITE_LIMIT_PER_MINUTE : self::READ_LIMIT_PER_MINUTE;
        $key = 'schedule_api_rate:' . $apiKey . ':' . $bucket . ':' . (string)floor(time() / 60);
        $count = (int)$this->redis->incr($key);
        if ($count === 1) {
            $this->redis->expire($key, 120);
        }
        if ($count > $limit) {
            throw new ScheduleApiException(
                'rate_limited',
                'Too many requests. Retry after the current minute.',
                429,
                ['limitPerMinute' => $limit]
            );
        }
    }

    /** @return array<string, mixed> */
    private function body(bool $required): array
    {
        $raw = (string)$this->request->getRawBody();
        if ($raw === '') {
            if ($required) {
                throw new ScheduleApiException('invalid_request', 'A JSON request body is required.', 400);
            }
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new ScheduleApiException('invalid_request', 'The request body must be a JSON object.', 400);
        }
        return $data;
    }

    /** @return array{extension: string, email: string} */
    private function hostSelector(array $data): array
    {
        $extension = trim((string)($data['hostExtension'] ?? $this->request->getQuery('hostExtension', 'string', '')));
        $email = trim((string)($data['hostEmail'] ?? $this->request->getQuery('hostEmail', 'string', '')));
        if ($extension === '' && $email === '') {
            throw new ScheduleApiException(
                'host_not_found',
                'hostExtension or hostEmail is required.',
                404
            );
        }
        return ['extension' => $extension, 'email' => $email];
    }

    /** @return array<string, string> */
    private function resolveHost(string $domainUuid, array $selector): array
    {
        $matches = [];
        foreach ($this->hostDirectory($domainUuid) as $row) {
            if ($selector['extension'] !== '' && (string)$row['extension'] !== $selector['extension']) {
                continue;
            }
            if ($selector['email'] !== '' && strcasecmp((string)$row['user_email'], $selector['email']) !== 0) {
                continue;
            }
            $matches[] = $row;
        }
        if (count($matches) !== 1) {
            throw new ScheduleApiException(
                'host_not_found',
                'The host did not resolve to exactly one extension in this tenant.',
                404
            );
        }
        return $matches[0];
    }

    /** @return array<int, array<string, string>> */
    private function hostDirectory(string $domainUuid): array
    {
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT vu.user_uuid, vu.user_email,
                    COALESCE(qu.first_name, '') AS first_name,
                    COALESCE(qu.last_name, '') AS last_name,
                    ve.extension, ve.extension_uuid
               FROM v_users vu
               JOIN v_extension_users veu
                 ON veu.user_uuid = vu.user_uuid
                AND veu.domain_uuid = vu.domain_uuid
               JOIN v_extensions ve
                 ON ve.extension_uuid = veu.extension_uuid
                AND ve.domain_uuid = veu.domain_uuid
          LEFT JOIN q_users qu ON qu.user_uuid = vu.user_uuid
              WHERE vu.domain_uuid = :domain_uuid
              ORDER BY ve.extension, vu.user_email",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['domain_uuid' => $domainUuid]
        );
        $result = [];
        foreach ($rows as $row) {
            if (trim((string)($row['extension'] ?? '')) === '') {
                continue;
            }
            $result[] = [
                'user_uuid' => (string)($row['user_uuid'] ?? ''),
                'user_email' => (string)($row['user_email'] ?? ''),
                'first_name' => (string)($row['first_name'] ?? ''),
                'last_name' => (string)($row['last_name'] ?? ''),
                'extension' => (string)$row['extension'],
                'extension_uuid' => (string)($row['extension_uuid'] ?? ''),
            ];
        }
        return $result;
    }

    /** @return array<string, string> */
    private function publicHost(array $host): array
    {
        return [
            'extension' => (string)$host['extension'],
            'email' => (string)$host['user_email'],
            'firstName' => (string)$host['first_name'],
            'lastName' => (string)$host['last_name'],
            'displayName' => trim((string)$host['first_name'] . ' ' . (string)$host['last_name']),
        ];
    }

    private function scheduleKey(string $extension, string $domainUuid): string
    {
        return 'schedule:' . $extension . ':' . $domainUuid;
    }

    private function getSchedules(string $key, array $host, array $tenant, $id)
    {
        $rows = $this->scheduleRows($key);
        if ($id !== null && trim((string)$id) !== '') {
            foreach ($rows as $row) {
                if ((string)($row['id'] ?? '') === (string)$id) {
                    $links = $this->knownMeetingLinks($tenant, [$row]);
                    return $this->success(200, [
                        'data' => ScheduleValidator::storedToApi(
                            $row,
                            $host['extension'],
                            $links[(string)($row['ringqmeetId'] ?? '')] ?? null
                        ),
                    ]);
                }
            }
            throw new ScheduleApiException('not_found', 'Schedule not found.', 404);
        }

        $page = max(1, (int)$this->request->getQuery('page', 'int', 1));
        $limit = max(1, min(self::MAX_PAGE_SIZE, (int)$this->request->getQuery('limit', 'int', 50)));
        $total = count($rows);
        $rows = array_slice($rows, ($page - 1) * $limit, $limit);
        $links = $this->knownMeetingLinks($tenant, $rows);
        $data = [];
        foreach ($rows as $row) {
            $data[] = ScheduleValidator::storedToApi(
                $row,
                $host['extension'],
                $links[(string)($row['ringqmeetId'] ?? '')] ?? null
            );
        }
        return $this->success(200, ['data' => $data, 'page' => $page, 'limit' => $limit, 'total' => $total]);
    }

    private function createSchedule(string $key, array $host, array $context, array $data)
    {
        $clientRequestId = trim((string)($data['clientRequestId'] ?? ''));
        if ($clientRequestId !== '' && strlen($clientRequestId) > 128) {
            throw new ScheduleApiException(
                'invalid_request',
                'clientRequestId must be at most 128 characters.',
                400,
                ['field' => 'clientRequestId']
            );
        }
        return $this->withHostLock($host, $context['tenant']['domain_uuid'], function () use (
            $key,
            $host,
            $context,
            $data,
            $clientRequestId
        ) {
            $idempotencyKey = $clientRequestId === '' ? '' : $this->idempotencyKey($context['apiKey'], $clientRequestId);
            if ($idempotencyKey !== '') {
                $priorId = (string)$this->redis->get($idempotencyKey);
                if ($priorId !== '') {
                    $prior = $this->findSchedule($key, $priorId);
                    if ($prior !== null) {
                        return $this->success(200, [
                            'data' => ScheduleValidator::storedToApi($prior['row'], $host['extension']),
                            'idempotentReplay' => true,
                        ]);
                    }
                    $this->redis->del($idempotencyKey);
                }
            }

            $validated = ScheduleValidator::validate($data);
            $internalUsers = $this->resolveInternalUsers(
                $context['tenant']['domain_uuid'],
                $validated['internalExtensions'],
                ''
            );
            $this->assertNoConflict(
                $host,
                $context['tenant']['domain_uuid'],
                $validated['start'],
                $validated['end']
            );

            $roomId = (new \Phalcon\Security\Random())->uuid();
            $meetingLink = $this->openMeetingLink(
                $host,
                $context['tenant'],
                $roomId,
                $validated['title'],
                $validated['hostPermissions']
            );
            foreach ($internalUsers as &$internalUser) {
                $internalUser['meetLink'] = $meetingLink;
            }
            unset($internalUser);
            $row = array_merge(
                [
                    'meetingTitle' => $validated['title'],
                    'meetingDescription' => $validated['description'],
                    'emailAddresses' => implode(',', $validated['emailAddresses']),
                    'internalUsers' => $internalUsers,
                    'hostName' => trim($host['first_name'] . ' ' . $host['last_name']),
                    'hostEmail' => $host['user_email'],
                    'ringqmeetId' => $roomId,
                    'ringqmeetLink' => $meetingLink,
                    'ringqmeetParticipantLink' => $meetingLink,
                    'id' => uniqid(),
                ],
                ScheduleValidator::storedTimeFields($validated),
                ScheduleValidator::storedMeetingFields($validated)
            );
            $encoded = json_encode($row);
            if ($encoded === false || $this->redis->lpush($key, $encoded) === false) {
                throw new ScheduleApiException('temporarily_unavailable', 'The schedule could not be stored.', 503);
            }
            $this->redis->lpush('email_queue', json_encode(['type' => 'schedule', 'data' => $row]));
            if ($idempotencyKey !== '') {
                $this->redis->setex($idempotencyKey, self::IDEMPOTENCY_TTL, $row['id']);
            }
            $this->writeApiAudit($context, $host, 'create', $row['id']);
            return $this->success(201, ['data' => ScheduleValidator::storedToApi($row, $host['extension'])]);
        });
    }

    private function updateSchedule(string $key, array $host, array $context, string $id, array $patch)
    {
        return $this->withHostLock($host, $context['tenant']['domain_uuid'], function () use (
            $key,
            $host,
            $context,
            $id,
            $patch
        ) {
            $found = $this->findSchedule($key, $id);
            if ($found === null) {
                throw new ScheduleApiException('not_found', 'Schedule not found.', 404);
            }
            $allowed = [
                'title', 'description', 'start', 'end', 'duration', 'timezone', 'emailAddresses', 'internalUsers',
                'allDay', 'repeat', 'bypassLobby', 'hostPermissions',
            ];
            $changes = array_intersect_key($patch, array_flip($allowed));
            if ($changes === []) {
                throw new ScheduleApiException(
                    'invalid_request',
                    'PATCH must include at least one mutable schedule field.',
                    400
                );
            }
            $current = ScheduleValidator::storedToApi($found['row'], $host['extension']);
            if (array_key_exists('duration', $changes) && !array_key_exists('end', $changes)) {
                unset($current['end']);
            } elseif (!array_key_exists('duration', $changes)) {
                unset($current['duration']);
            }
            $merged = array_merge($current, $changes);
            $validated = ScheduleValidator::validate($merged);
            $meetingLink = (string)($found['row']['ringqmeetLink'] ?? '');
            $internalUsers = $this->resolveInternalUsers(
                $context['tenant']['domain_uuid'],
                $validated['internalExtensions'],
                $meetingLink
            );
            $this->assertNoConflict(
                $host,
                $context['tenant']['domain_uuid'],
                $validated['start'],
                $validated['end'],
                $id
            );
            $row = array_merge(
                $found['row'],
                [
                    'meetingTitle' => $validated['title'],
                    'meetingDescription' => $validated['description'],
                    'emailAddresses' => implode(',', $validated['emailAddresses']),
                    'internalUsers' => $internalUsers,
                ],
                ScheduleValidator::storedTimeFields($validated),
                ScheduleValidator::storedMeetingFields($validated)
            );
            if ($validated['hostPermissions'] === null) {
                unset($row['hostPermissions']);
            }
            if ($this->redis->lset($key, $found['index'], json_encode($row)) === false) {
                throw new ScheduleApiException('temporarily_unavailable', 'The schedule could not be updated.', 503);
            }
            $this->writeApiAudit($context, $host, 'update', $id);
            $links = $this->knownMeetingLinks($context['tenant'], [$row]);
            return $this->success(200, [
                'data' => ScheduleValidator::storedToApi(
                    $row,
                    $host['extension'],
                    $links[(string)($row['ringqmeetId'] ?? '')] ?? null
                ),
            ]);
        });
    }

    private function deleteSchedule(string $key, array $host, array $context, string $id)
    {
        return $this->withHostLock($host, $context['tenant']['domain_uuid'], function () use (
            $key,
            $host,
            $context,
            $id
        ) {
            $found = $this->findSchedule($key, $id);
            if ($found === null) {
                throw new ScheduleApiException('not_found', 'Schedule not found.', 404);
            }
            if ((int)$this->redis->lrem($key, 1, $found['encoded']) < 1) {
                throw new ScheduleApiException('temporarily_unavailable', 'The schedule could not be deleted.', 503);
            }
            $this->writeApiAudit($context, $host, 'delete', $id);
            return $this->success(200, ['deleted' => true, 'id' => $id]);
        });
    }

    private function withHostLock(array $host, string $domainUuid, callable $operation)
    {
        $lockKey = 'schedule_api_lock:' . $host['extension'] . ':' . $domainUuid;
        $token = bin2hex(random_bytes(16));
        if (!$this->redis->setnx($lockKey, $token)) {
            throw new ScheduleApiException(
                'schedule_conflict',
                'This host schedule is being changed. Retry the request.',
                409,
                ['retryable' => true]
            );
        }
        $this->redis->expire($lockKey, self::LOCK_TTL);
        try {
            return $operation();
        } finally {
            // The operation is bounded to local Redis/DB work. Checking the
            // token prevents an expired lock acquired by another request from
            // being deleted by this one.
            try {
                $this->redis->eval(
                    "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
                    [$lockKey, $token],
                    1
                );
            } catch (\Throwable $e) {
                // The TTL is the final safety net; never fail a completed API
                // write because lock cleanup itself failed.
            }
        }
    }

    private function idempotencyKey(string $apiKey, string $clientRequestId): string
    {
        return 'schedule_api_idempotency:' . hash('sha256', $apiKey . "\0" . $clientRequestId);
    }

    /** @return array<int, array<string, string>> */
    private function resolveInternalUsers(string $domainUuid, array $extensions, string $meetingLink): array
    {
        if ($extensions === []) {
            return [];
        }
        $byExtension = [];
        foreach ($this->hostDirectory($domainUuid) as $row) {
            $byExtension[(string)$row['extension']][] = $row;
        }
        $result = [];
        foreach ($extensions as $extension) {
            $matches = $byExtension[$extension] ?? [];
            if (count($matches) !== 1) {
                throw new ScheduleValidationException(
                    'unknown_internal_user',
                    'An internal user extension was not found in this tenant.',
                    400,
                    ['extension' => $extension]
                );
            }
            $row = $matches[0];
            $result[] = [
                'display' => trim(trim((string)$row['first_name'] . ' ' . (string)$row['last_name'])
                    . ' (' . (string)$row['extension'] . ')'),
                'user_email' => (string)$row['user_email'],
                'first_name' => (string)$row['first_name'],
                'last_name' => (string)$row['last_name'],
                'extension' => (string)$row['extension'],
                'meetLink' => $meetingLink,
            ];
        }
        return $result;
    }

    /**
     * The link the app and the invitation email show: the control plane's short
     * code. The room is opened here exactly as the email worker opens it
     * (meetCreateRoomLink), so the two agree; the uuid form is the fallback.
     */
    private function openMeetingLink(array $host, array $tenant, string $ringqmeetId, string $title, ?array $policy): string
    {
        $fallback = 'https://' . $tenant['domain_name'] . '/meet/m/' . rawurlencode($ringqmeetId);
        $ownerKey = $this->meetOwnerKey($host, $tenant['domain_uuid']);
        if ($ownerKey === null) {
            return $fallback;
        }
        $accessKey = null;
        try {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $ttl = 120;
            $accessKey = 'meet:access:' . hash('sha256', $token);
            $hostName = trim($host['first_name'] . ' ' . $host['last_name']);
            $this->redis->setex($accessKey, $ttl, json_encode([
                'ownerKey' => $ownerKey,
                'displayName' => $hostName !== '' ? $hostName : $ownerKey,
                'email' => (string)$host['user_email'],
                'domainUuid' => strtolower($tenant['domain_uuid']),
                'canHost' => true,
                'permissions' => ['host' => true, 'join' => true],
                'issuedAt' => time(),
                'expiresAt' => time() + $ttl,
            ]));
            $request = [
                'meetType' => 'scheduled',
                'title' => $title,
                'externalReference' => $ringqmeetId,
            ];
            if ($policy !== null) {
                $request['policy'] = $policy;
            }
            $ch = curl_init('https://' . $tenant['domain_name'] . '/meet/api/v1/rooms');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
                CURLOPT_POSTFIELDS => json_encode($request),
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $decoded = $status === 200 && is_string($body) ? json_decode($body, true) : null;
            return is_array($decoded) && !empty($decoded['joinUrl']) ? (string)$decoded['joinUrl'] : $fallback;
        } catch (\Throwable $e) {
            error_log('schedule api room: ' . $e->getMessage());
            return $fallback;
        } finally {
            if ($accessKey !== null) {
                try {
                    $this->redis->del($accessKey);
                } catch (\Throwable $e) {
                }
            }
        }
    }

    /**
     * The owner key this host already holds in RingQ Meet, or null. A room
     * opened under a key the host's own sessions do not use locks them out of
     * it, so a key is accepted only when the meetings table shows it in use.
     */
    private function meetOwnerKey(array $host, string $domainUuid): ?string
    {
        $candidates = array_values(array_unique(array_filter([
            (string)($host['extension_uuid'] ?? ''),
            (string)($host['user_uuid'] ?? ''),
        ])));
        if ($candidates === []) {
            return null;
        }
        try {
            $placeholders = implode(',', array_fill(0, count($candidates), '?'));
            $known = $this->db->fetchAll(
                "SELECT DISTINCT owner_key FROM meet_meetings
                  WHERE domain_uuid = ? AND owner_key IN (" . $placeholders . ")",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                array_merge([$domainUuid], $candidates)
            );
        } catch (\Throwable $e) {
            return null;
        }
        return is_array($known) && count($known) === 1 ? (string)$known[0]['owner_key'] : null;
    }

    /**
     * Short-code links for rows whose room has been opened, keyed by ringqmeetId.
     *
     * @return array<string, string>
     */
    private function knownMeetingLinks(array $tenant, array $rows): array
    {
        $references = [];
        foreach ($rows as $row) {
            $reference = trim((string)($row['ringqmeetId'] ?? ''));
            if ($reference !== '') {
                $references[$reference] = $reference;
            }
        }
        if ($references === []) {
            return [];
        }
        try {
            $placeholders = implode(',', array_fill(0, count($references), '?'));
            $found = $this->db->fetchAll(
                "SELECT external_reference, slug FROM meet_meetings
                  WHERE domain_uuid = ? AND external_reference IN (" . $placeholders . ")",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                array_merge([$tenant['domain_uuid']], array_values($references))
            );
        } catch (\Throwable $e) {
            return [];
        }
        $links = [];
        foreach (is_array($found) ? $found : [] as $meeting) {
            $slug = trim((string)($meeting['slug'] ?? ''));
            if ($slug !== '') {
                $links[(string)$meeting['external_reference']] = 'https://' . $tenant['domain_name'] . '/meet/m/' . $slug;
            }
        }
        return $links;
    }

    private function assertNoConflict(
        array $host,
        string $domainUuid,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        ?string $ignoreScheduleId = null
    ): void {
        foreach ($this->busyIntervals($host, $domainUuid, $ignoreScheduleId) as $busy) {
            if (!ScheduleValidator::overlaps($start, $end, $busy['startObject'], $busy['endObject'])) {
                continue;
            }
            throw new ScheduleValidationException(
                'schedule_conflict',
                'The host is already busy during this interval.',
                409,
                [
                    'conflictWith' => $busy['conflictWith'],
                    'id' => $busy['id'],
                    'title' => $busy['title'],
                    'start' => $busy['start'],
                    'end' => $busy['end'],
                ]
            );
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function busyIntervals(array $host, string $domainUuid, ?string $ignoreScheduleId = null): array
    {
        $busy = [];
        foreach ($this->scheduleRows($this->scheduleKey($host['extension'], $domainUuid)) as $row) {
            if ($ignoreScheduleId !== null && (string)($row['id'] ?? '') === $ignoreScheduleId) {
                continue;
            }
            $interval = ScheduleValidator::scheduleInterval($row);
            if ($interval !== null) {
                $busy[] = $this->busyItem('schedule', $row['id'] ?? '', $row['meetingTitle'] ?? '', $interval);
            }
        }

        foreach ($this->scheduleRows('booking:' . $host['extension'] . ':' . $domainUuid) as $booking) {
            $bookingId = (string)($booking['id'] ?? '');
            if ($bookingId === '') {
                continue;
            }
            foreach ($this->scheduleRows('client_bookings:' . $bookingId) as $appointment) {
                $interval = ScheduleValidator::bookingInterval($appointment, $booking);
                if ($interval !== null) {
                    $busy[] = $this->busyItem(
                        'booking',
                        $appointment['id'] ?? $bookingId,
                        $booking['meetingTitle'] ?? 'Booking',
                        $interval
                    );
                }
            }
        }

        foreach ($this->scheduleRows('webinars:' . $host['extension'] . ':' . $domainUuid) as $webinar) {
            $interval = ScheduleValidator::webinarInterval($webinar);
            if ($interval !== null) {
                $busy[] = $this->busyItem('webinar', $webinar['id'] ?? '', $webinar['title'] ?? 'Webinar', $interval);
            }
        }
        return $busy;
    }

    /** @return array<string, mixed> */
    private function busyItem(string $type, $id, $title, array $interval): array
    {
        return [
            'conflictWith' => $type,
            'id' => (string)$id,
            'title' => (string)$title,
            'start' => $interval['start']->format(\DateTimeInterface::ATOM),
            'end' => $interval['end']->format(\DateTimeInterface::ATOM),
            'startObject' => $interval['start'],
            'endObject' => $interval['end'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function scheduleRows(string $key): array
    {
        $rows = [];
        foreach ($this->redis->lrange($key, 0, -1) as $encoded) {
            $row = json_decode((string)$encoded, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** @return array{row: array<string, mixed>, encoded: string, index: int}|null */
    private function findSchedule(string $key, string $id): ?array
    {
        foreach ($this->redis->lrange($key, 0, -1) as $index => $encoded) {
            $row = json_decode((string)$encoded, true);
            if (is_array($row) && (string)($row['id'] ?? '') === $id) {
                return ['row' => $row, 'encoded' => (string)$encoded, 'index' => (int)$index];
            }
        }
        return null;
    }

    private function writeApiAudit(array $context, array $host, string $action, string $id): void
    {
        try {
            $metadata = json_encode([
                'hostExtension' => (string)$host['extension'],
                'apiKey' => (string)$context['apiKey'],
            ], JSON_UNESCAPED_SLASHES);
            $this->db->execute(
                "INSERT INTO audit_logs
                    (domain_uuid, user_uuid, username, user_fullname, user_email,
                     extension, ip_address, user_agent, feature, action, entity,
                     result, metadata)
                 VALUES
                    (:domain_uuid, NULL, :username, NULL, NULL,
                     :extension, :ip_address, :user_agent, :feature, :action, :entity,
                     'success', CAST(:metadata AS jsonb))",
                [
                    'domain_uuid' => $context['tenant']['domain_uuid'],
                    'username' => substr((string)$context['apiKey'], 0, 255),
                    'extension' => substr((string)$host['extension'], 0, 32),
                    'ip_address' => substr((string)$this->request->getClientAddress(), 0, 45),
                    'user_agent' => substr((string)$this->request->getUserAgent(), 0, 512),
                    'feature' => 'schedule-api',
                    'action' => $action,
                    'entity' => substr($id, 0, 255),
                    'metadata' => $metadata === false ? null : $metadata,
                ]
            );
            $this->enforceAuditRetention($context['tenant']['domain_uuid']);
        } catch (\Throwable $e) {
            error_log('schedule api audit: ' . $e->getMessage());
        }
    }

    private function success(int $status, array $payload)
    {
        return $this->json($status, array_merge(['result' => true], $payload));
    }

    private function error(int $status, string $code, string $message, array $details = [])
    {
        $payload = ['result' => false, 'code' => $code, 'message' => $message];
        if ($details !== []) {
            $payload['details'] = $details;
        }
        return $this->json($status, $payload);
    }

    private function json(int $status, array $payload)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setStatusCode($status, $this->reason($status));
        $this->response->setHeader('Content-Type', 'application/json');
        $this->response->setHeader('Cache-Control', 'no-store');
        return $this->response->setContent(json_encode($payload));
    }

    private function reason(int $status): string
    {
        $reasons = [
            200 => 'OK', 201 => 'Created', 400 => 'Bad Request', 401 => 'Unauthorized',
            403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed',
            409 => 'Conflict', 429 => 'Too Many Requests', 503 => 'Service Unavailable',
        ];
        return $reasons[$status] ?? 'Error';
    }
}
