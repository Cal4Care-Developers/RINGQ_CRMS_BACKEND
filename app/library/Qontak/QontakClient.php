<?php
declare(strict_types=1);

namespace Library\Qontak;

/**
 * Minimal HTTP client for the Qontak CRM OpenAPI behind the Mekari API Gateway.
 *
 * Verified against the official docs (2026-10-14):
 *   - Base URL  production https://api.mekari.com, staging https://api.mekari.io
 *   - CRM paths are prefixed /qontak/crm (e.g. /qontak/crm/contacts)
 *   - Auth: HMAC-SHA256 ("hmac username=..., algorithm=hmac-sha256,
 *     headers=date request-line, signature=...") over
 *       "date: <RFC7231 date>\n<METHOD> <path[?query]> HTTP/1.1"
 *     with a 300 s clock-skew window
 *     https://developers.mekari.com/docs/kb/hmac-authentication
 *   - Digest: SHA-256=<base64(sha256(body))> on requests with a body (KB)
 *   - X-Crm-User-Sso-Id: the CRM user the action is performed as
 *     (Qontak CRM OpenAPI - MAG Postman collection)
 *   - Response envelope {"meta":{...},"response":...}
 *
 * The Mekari KB says the signed request-line must include the query string;
 * the CRM Postman collection's pre-request script signs the path only. We sign
 * path+query (KB) and, if the gateway answers 401 for a request WITH a query
 * string, retry once signing path-only and report which mode worked so the
 * caller can persist it (config.sign_mode).
 *
 * Nothing here logs headers, bodies, secrets or signatures.
 */
class QontakClient
{
    const BASE_URLS = [
        'production' => 'https://api.mekari.com',
        'sandbox' => 'https://api.mekari.io',
    ];
    const CRM_PREFIX = '/qontak/crm';
    const CONNECT_TIMEOUT = 5;
    const TIMEOUT = 12;

    /** @var string */
    private $baseUrl;
    /** @var string */
    private $clientId;
    /** @var string */
    private $clientSecret;
    /** @var string */
    private $ssoId;
    /** @var string 'path_query' | 'path_only' */
    public $signMode;
    /** @var bool set when the fallback sign mode succeeded */
    public $signModeChanged = false;

    public function __construct(string $environment, string $clientId, string $clientSecret, string $ssoId = '', string $signMode = 'path_query')
    {
        $this->baseUrl = self::BASE_URLS[$environment] ?? self::BASE_URLS['production'];
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->ssoId = $ssoId;
        $this->signMode = $signMode === 'path_only' ? 'path_only' : 'path_query';
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query, null);
    }

    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, [], $body);
    }

    public function put(string $path, array $body): array
    {
        return $this->request('PUT', $path, [], $body);
    }

    /**
     * @return array the decoded "response" member (or the whole body if absent)
     * @throws QontakException
     */
    public function request(string $method, string $path, array $query = [], $body = null): array
    {
        $fullPath = self::CRM_PREFIX . '/' . ltrim($path, '/');
        $qs = $query ? http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '';
        $json = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $result = $this->send($method, $fullPath, $qs, $json, $this->signMode);
        if ($result['status'] === 401 && $qs !== '') {
            $alt = $this->signMode === 'path_query' ? 'path_only' : 'path_query';
            $retry = $this->send($method, $fullPath, $qs, $json, $alt);
            if ($retry['status'] !== 401) {
                $this->signMode = $alt;
                $this->signModeChanged = true;
                $result = $retry;
            }
        }
        return $this->decode($result, $method, $path);
    }

    private function headers(string $method, string $requestTarget, $json): array
    {
        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $signingString = 'date: ' . $date . "\n" . $method . ' ' . $requestTarget . ' HTTP/1.1';
        $signature = base64_encode(hash_hmac('sha256', $signingString, $this->clientSecret, true));
        $headers = [
            'Accept: application/json',
            'Date: ' . $date,
            'Authorization: hmac username="' . $this->clientId . '", algorithm="hmac-sha256", headers="date request-line", signature="' . $signature . '"',
        ];
        if ($this->ssoId !== '') {
            $headers[] = 'X-Crm-User-Sso-Id: ' . $this->ssoId;
        }
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Digest: SHA-256=' . base64_encode(hash('sha256', $json, true));
        }
        return $headers;
    }

    private function send(string $method, string $fullPath, string $qs, $json, string $signMode): array
    {
        $target = $fullPath . ($qs !== '' && $signMode === 'path_query' ? '?' . $qs : '');
        $ch = curl_init($this->baseUrl . $fullPath . ($qs !== '' ? '?' . $qs : ''));
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $this->headers($method, $target, $json),
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

    private function decode(array $r, string $method, string $path): array
    {
        $label = $method . ' ' . preg_replace('/\d+/', ':id', $path);
        if ($r['status'] === 0) {
            $timeout = in_array($r['errno'], [CURLE_OPERATION_TIMEDOUT, 28], true);
            error_log('[qontak] ' . $label . ' transport error errno=' . $r['errno']);
            throw new QontakException(
                $timeout ? 'Qontak did not respond in time.' : 'Could not reach Qontak (network error).',
                $timeout ? 'timeout' : 'unavailable'
            );
        }

        $data = json_decode($r['body'], true);
        $status = $r['status'];
        if ($status >= 200 && $status < 300) {
            if (!is_array($data)) {
                return [];
            }
            return array_key_exists('response', $data) && is_array($data['response']) ? $data['response'] : $data;
        }

        $apiMsg = '';
        if (is_array($data)) {
            $apiMsg = (string) ($data['meta']['developer_message'] ?? $data['meta']['message'] ?? $data['message'] ?? $data['error'] ?? '');
            if (is_array($data['meta']['errors'] ?? null)) {
                $apiMsg .= ' ' . json_encode($data['meta']['errors']);
            }
        }
        $apiMsg = mb_substr(trim($apiMsg), 0, 300);
        error_log('[qontak] ' . $label . ' HTTP ' . $status . ($apiMsg !== '' ? ' ' . $apiMsg : ''));

        if ($status === 401) {
            throw new QontakException('Qontak rejected the credentials (HTTP 401). Check the Client ID, Client Secret and server clock.', 'auth_failed', 401);
        }
        if ($status === 403) {
            throw new QontakException('The Qontak credentials lack permission for this action (HTTP 403).' . ($apiMsg ? ' ' . $apiMsg : ''), 'forbidden', 403);
        }
        if ($status === 404) {
            throw new QontakException('Qontak record or endpoint not found (HTTP 404).', 'not_found', 404);
        }
        if ($status === 429) {
            $retryAfter = null;
            if (preg_match('/^Retry-After:\s*(\d+)/mi', $r['headers'], $m)) {
                $retryAfter = (int) $m[1];
            }
            throw new QontakException('Qontak rate limit reached. Will retry automatically.', 'rate_limited', 429, $retryAfter);
        }
        if ($status >= 500) {
            throw new QontakException('Qontak is temporarily unavailable (HTTP ' . $status . ').', 'unavailable', $status);
        }
        throw new QontakException('Qontak rejected the request (HTTP ' . $status . ')' . ($apiMsg ? ': ' . $apiMsg : '.'), 'validation', $status);
    }
}
