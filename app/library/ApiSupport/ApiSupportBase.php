<?php
declare(strict_types=1);

use ElephantIO\Client;

/**
 * Shared plumbing for the API-key "admin" endpoints (queues, agents, IVRs,
 * extension settings, hotel). Used by IndexController through the ApiSupport*
 * traits, so every method runs in the controller's scope and can reach
 * authenticate(), $this->db, $this->redis, $this->response and $this->request.
 *
 * Every endpoint follows the same contract as the existing call APIs:
 *   - POST /index/<action> with a JSON body that includes "domain"
 *   - HMAC headers checked by authenticate() (401 on failure)
 *   - permission flag in Redis call_api_permissions:<domain> (403 when off)
 *   - JSON answer {"status":"success"|"error","message":...}
 */
trait ApiSupportBase
{
    /**
     * Authenticate, validate required fields, check the permission flag and
     * resolve the tenant.
     *
     * @param string|string[] $permission permission flag that must be on; when several names are given any one is enough
     *                                    (for APIs whose flag was saved under more than one name)
     * @param string[]|string[][] $required field names that must be non-empty
     * @return array{data: array, domain: string, domain_uuid: string, session: array}|\Phalcon\Http\ResponseInterface
     *         a response when the request must stop, otherwise the context
     */
    protected function apiBegin($permission, array $required = [])
    {
        $permissions = (array)$permission;
        $this->view->disable();
        $this->response->setContentType('application/json');
        $data = $this->request->getJsonRawBody(true);
        if (!is_array($data)) {
            $data = [];
        }

        try {
            $this->authenticate();
        } catch (\Exception $e) {
            return $this->apiFail('Unauthorized', 401);
        }

        $domain = trim((string)($data['domain'] ?? ''));
        if ($domain === '') {
            return $this->apiFail('Missing parameters: domain', 400);
        }
        foreach ($required as $field) {
            if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')
                || $data[$field] === [] || $data[$field] === null) {
                return $this->apiFail('Missing parameters: ' . $field, 400);
            }
        }

        $allowed = false;
        foreach ($permissions as $name) {
            if ((int)$this->redis->hGet('call_api_permissions:' . $domain, $name) === 1) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            return $this->apiFail('Permission denied: ' . $permissions[0], 403);
        }

        $row = $this->db->fetchOne(
            'SELECT domain_uuid FROM v_domains WHERE domain_name = :domain',
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['domain' => $domain]
        );
        if (!$row) {
            return $this->apiFail('Domain not found', 404);
        }

        return [
            'data'        => $data,
            'domain'      => $domain,
            'domain_uuid' => (string)$row['domain_uuid'],
            // Stand-in for the web session the UI code reads.
            'session'     => [
                'domain_uuid' => (string)$row['domain_uuid'],
                'subdomain'   => $domain,
                'user_uuid'   => null,
            ],
        ];
    }

    protected function apiOk(array $payload = [], int $status = 200)
    {
        return $this->response->setStatusCode($status)->setJsonContent(
            array_merge(['status' => 'success'], $payload)
        );
    }

    protected function apiFail(string $message, int $status = 400)
    {
        return $this->response->setStatusCode($status)->setJsonContent([
            'status'  => 'error',
            'message' => $message,
        ]);
    }

    /** Failure from a caught throwable; never leaks SQL or file paths. */
    protected function apiFailFrom(\Throwable $e, int $status = 400)
    {
        return $this->apiFail(ControllerBase::safeErrorMessage($e), $status);
    }

    protected function apiUuid(): string
    {
        return (new \Phalcon\Security\Random())->uuid();
    }

    /** Best-effort socket.io emit to the node service; failures are ignored like the UI does. */
    protected function apiEmit(string $event, array $payload): void
    {
        try {
            $client = new Client(Client::engine(Client::CLIENT_4X, 'ws://127.0.0.1:3000', ['persistent' => false]));
            $client->initialize();
            $client->emit($event, $payload);
        } catch (\Throwable $e) {
            error_log('api emit ' . $event . ': ' . $e->getMessage());
        }
    }

    /** Delete cache files under both cache roots. $names are file names, not paths. */
    protected function apiUnlinkCache(array $names): void
    {
        foreach ($names as $name) {
            $name = basename((string)$name);
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            foreach (['/var/cache/fusionpbx/', '/var/cache/ringq/'] as $root) {
                if (file_exists($root . $name)) {
                    @unlink($root . $name);
                }
            }
        }
    }

    /** Delete several Redis keys (null-safe). */
    protected function apiRedisDel(array $keys): void
    {
        foreach ($keys as $key) {
            $this->redis->del($key);
        }
    }

    /** One-line audit entry attributed to the API key. */
    protected function apiAudit(array $ctx, string $feature, string $verb, array $metadata = []): void
    {
        try {
            $this->audit($feature, $verb, [
                'actor'    => ['domain_uuid' => $ctx['domain_uuid'], 'user_fullname' => 'API key'],
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            error_log('api audit: ' . $e->getMessage());
        }
    }

    /** Next free value after the largest used one, or $start when none (queue/IVR extension numbering). */
    protected function apiNextNumber(string $table, string $column, string $domainUuid, int $floor, int $start): int
    {
        $row = $this->db->fetchOne(
            "SELECT MAX(CAST($column AS INTEGER)) AS m FROM $table WHERE domain_uuid = :d AND $column ~ '^[0-9]+$' AND CAST($column AS INTEGER) > :f",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['d' => $domainUuid, 'f' => $floor]
        );
        return !empty($row['m']) ? ((int)$row['m'] + 1) : $start;
    }
}
