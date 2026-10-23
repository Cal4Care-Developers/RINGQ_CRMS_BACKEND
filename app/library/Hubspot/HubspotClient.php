<?php
declare(strict_types=1);

namespace Library\Hubspot;

/**
 * Minimal HTTPS client for the HubSpot CRM API (https://api.hubapi.com).
 *
 * Auth: a HubSpot *private app* access token sent as "Authorization: Bearer".
 * Private app tokens do not expire and have no refresh token; they stop
 * working only when the app is deleted or the token is rotated in HubSpot,
 * which surfaces here as HTTP 401 (category INVALID_AUTHENTICATION /
 * EXPIRED_AUTHENTICATION).
 *
 * Error body (HubSpot standard error object):
 *   {"status":"error","message":"...","correlationId":"...","category":"MISSING_SCOPES",
 *    "errors":[...],"context":{"requiredGranularScopes":[...]}}
 *
 * Rate limits: HTTP 429 (category RATE_LIMITS). An interactive request retries
 * once after Retry-After (max 2 s); background sync callers get a
 * `rate_limited` exception and back off.
 *
 * Nothing here logs headers, request bodies or the token. Log lines contain
 * method, a path with ids masked, HTTP status, HubSpot category and correlationId.
 */
class HubspotClient
{
    const BASE_URL = 'https://api.hubapi.com';
    const CONNECT_TIMEOUT = 5;
    const TIMEOUT = 15;

    /** @var string */
    private $token;
    /** @var bool retry one 429 inline (interactive requests) */
    private $retryInline;

    public function __construct(string $token, bool $retryInline = true)
    {
        $this->token = $token;
        $this->retryInline = $retryInline;
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query, null);
    }

    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, [], $body);
    }

    public function patch(string $path, array $body): array
    {
        return $this->request('PATCH', $path, [], $body);
    }

    public function put(string $path, $body = null): array
    {
        return $this->request('PUT', $path, [], $body);
    }

    /**
     * @return array decoded JSON body ([] for an empty 2xx body)
     * @throws HubspotException
     */
    public function request(string $method, string $path, array $query = [], $body = null): array
    {
        $url = self::BASE_URL . '/' . ltrim($path, '/');
        if ($query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $json = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $r = $this->send($method, $url, $json);
        if ($r['status'] === 429 && $this->retryInline) {
            $wait = $this->retryAfter($r['headers']);
            if ($wait === null || $wait <= 2) {
                usleep((int) (max(1, (int) $wait) * 1000000));
                $r = $this->send($method, $url, $json);
            }
        }
        return $this->decode($r, $method, $path);
    }

    private function send(string $method, string $url, $json): array
    {
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
        ];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($json !== null) {
            $opts[CURLOPT_POSTFIELDS] = $json;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        unset($headers, $opts);

        if ($raw === false) {
            return ['status' => 0, 'errno' => $errno, 'headers' => '', 'body' => ''];
        }
        return [
            'status' => $status,
            'errno' => 0,
            'headers' => substr((string) $raw, 0, $headerSize),
            'body' => substr((string) $raw, $headerSize),
        ];
    }

    private function retryAfter(string $headers): ?int
    {
        if (preg_match('/^Retry-After:\s*(\d+)/mi', $headers, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function decode(array $r, string $method, string $path): array
    {
        $label = $method . ' ' . preg_replace('/\d{3,}/', ':id', (string) strtok($path, '?'));
        if ($r['status'] === 0) {
            $timeout = in_array($r['errno'], [CURLE_OPERATION_TIMEDOUT, 28], true);
            error_log('[hubspot] ' . $label . ' transport error errno=' . $r['errno']);
            throw new HubspotException(
                $timeout ? 'HubSpot did not respond in time.' : 'Could not reach HubSpot (network error).',
                $timeout ? 'timeout' : 'unavailable'
            );
        }

        $data = json_decode($r['body'], true);
        $status = $r['status'];
        if ($status >= 200 && $status < 300) {
            return is_array($data) ? $data : [];
        }

        $category = is_array($data) ? (string) ($data['category'] ?? '') : '';
        $apiMsg = is_array($data) ? mb_substr(trim((string) ($data['message'] ?? '')), 0, 300) : '';
        $correlation = is_array($data) ? (string) ($data['correlationId'] ?? '') : '';
        error_log('[hubspot] ' . $label . ' HTTP ' . $status . ($category !== '' ? ' ' . $category : '')
            . ($correlation !== '' ? ' correlationId=' . $correlation : ''));

        if ($status === 401) {
            $expired = $category === 'EXPIRED_AUTHENTICATION';
            throw new HubspotException(
                $expired
                    ? 'The HubSpot access token has expired. Create a new private app token and save it in HubSpot settings.'
                    : 'HubSpot rejected the access token (HTTP 401). Check that the private app still exists and the token was copied completely.',
                'auth_failed',
                401
            );
        }
        if ($status === 403) {
            $scopes = [];
            if (is_array($data['context']['requiredGranularScopes'] ?? null)) {
                $scopes = array_map('strval', $data['context']['requiredGranularScopes']);
            } elseif (is_array($data['errors'][0]['context']['requiredScopes'] ?? null)) {
                $scopes = array_map('strval', $data['errors'][0]['context']['requiredScopes']);
            }
            if ($category === 'MISSING_SCOPES' || $scopes) {
                throw new HubspotException(
                    'The HubSpot private app is missing a required scope' . ($scopes ? ': ' . implode(', ', array_slice($scopes, 0, 6)) : '') . '.',
                    'missing_scopes',
                    403
                );
            }
            throw new HubspotException('HubSpot denied this action (HTTP 403).' . ($apiMsg !== '' ? ' ' . $apiMsg : ''), 'forbidden', 403);
        }
        if ($status === 404) {
            throw new HubspotException('HubSpot record not found (HTTP 404).', 'not_found', 404);
        }
        if ($status === 409) {
            throw new HubspotException('HubSpot reported a conflict: ' . ($apiMsg !== '' ? $apiMsg : 'record already exists.'), 'conflict', 409);
        }
        if ($status === 429) {
            throw new HubspotException('HubSpot rate limit reached. It will be retried automatically.', 'rate_limited', 429, $this->retryAfter($r['headers']));
        }
        if ($status >= 500) {
            throw new HubspotException('HubSpot is temporarily unavailable (HTTP ' . $status . ').', 'unavailable', $status);
        }
        throw new HubspotException('HubSpot rejected the request (HTTP ' . $status . ')' . ($apiMsg !== '' ? ': ' . $apiMsg : '.'), 'validation', $status);
    }
}
