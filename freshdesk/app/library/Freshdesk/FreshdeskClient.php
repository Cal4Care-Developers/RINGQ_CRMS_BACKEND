<?php
declare(strict_types=1);

namespace Library\Freshdesk;

/**
 * Minimal HTTP client for the Freshdesk REST API v2.
 *
 * Verified against https://developers.freshdesk.com/api/ (2026-10-23):
 *   - Base URL https://<account>.freshdesk.com/api/v2/ (HTTPS only; custom
 *     CNAME domains do not work with the API)
 *   - Auth: HTTP Basic with the agent's API key as user name and "X" as password
 *   - JSON bodies; attachments as multipart/form-data (field "attachments[]")
 *   - Errors: {"description": "...", "errors": [{"field", "message", "code"}]}
 *   - 429 carries Retry-After (seconds); limits are per account per minute
 *
 * Redirects are never followed (a wrong account name must not leak the key to
 * another host). Nothing here logs headers, bodies or the API key.
 */
class FreshdeskClient
{
    const CONNECT_TIMEOUT = 5;
    const TIMEOUT = 15;
    const UPLOAD_TIMEOUT = 120;

    /** @var string e.g. cal4care.freshdesk.com */
    private $domain;
    /** @var string */
    private $apiKey;

    public function __construct(string $domain, string $apiKey)
    {
        if (!self::validDomain($domain)) {
            throw new FreshdeskException('The Freshdesk address must look like yourcompany.freshdesk.com.', 'validation');
        }
        $this->domain = strtolower($domain);
        $this->apiKey = $apiKey;
    }

    public static function validDomain(string $domain): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,62}\.freshdesk\.com$/i', $domain);
    }

    /** "cal4care", "https://cal4care.freshdesk.com/a/" ... -> "cal4care.freshdesk.com", or '' */
    public static function normalizeDomain(string $raw): string
    {
        $t = strtolower(trim($raw));
        $t = (string) preg_replace('#^https?://#', '', $t);
        $t = (string) preg_replace('#[/?\#].*$#', '', $t);
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $t)) {
            $t .= '.freshdesk.com';
        }
        return self::validDomain($t) ? $t : '';
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
     * @return array decoded JSON (object or list)
     * @throws FreshdeskException
     */
    public function request(string $method, string $path, array $query = [], $body = null): array
    {
        $url = $this->url($path, $query);
        $json = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Accept: application/json'];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $r = $this->send($method, $url, $headers, $json, self::TIMEOUT);
        return $this->decode($r, $method, $path);
    }

    /**
     * multipart/form-data POST (notes with an attachment). $fields are plain
     * form fields; $file = ['path' => ..., 'name' => ..., 'mime' => ...].
     */
    public function postMultipart(string $path, array $fields, array $file): array
    {
        $post = [];
        foreach ($fields as $k => $v) {
            $post[$k] = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        }
        $post['attachments[]'] = new \CURLFile((string) $file['path'], (string) $file['mime'], (string) $file['name']);
        $r = $this->send('POST', $this->url($path, []), ['Accept: application/json'], $post, self::UPLOAD_TIMEOUT);
        return $this->decode($r, 'POST', $path);
    }

    private function url(string $path, array $query): string
    {
        $qs = $query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '';
        return 'https://' . $this->domain . '/api/v2/' . ltrim($path, '/') . $qs;
    }

    /** @param string|array|null $payload */
    private function send(string $method, string $url, array $headers, $payload, int $timeout): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->apiKey . ':X',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'RingQ-Freshdesk/1.0',
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = $payload;
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
            error_log('[freshdesk] ' . $label . ' transport error errno=' . $r['errno']);
            if (in_array($r['errno'], [6], true)) {
                throw new FreshdeskException('The Freshdesk address could not be found. Check the account name.', 'not_found');
            }
            throw new FreshdeskException(
                $timeout ? 'Freshdesk did not respond in time.' : 'Could not reach Freshdesk (network error).',
                $timeout ? 'timeout' : 'unavailable'
            );
        }

        $data = json_decode($r['body'], true);
        $status = $r['status'];
        if ($status >= 200 && $status < 300) {
            return is_array($data) ? $data : [];
        }

        // Safe summary of Freshdesk's error: field names and codes only (messages can echo values).
        $items = [];
        $code = '';
        if (is_array($data)) {
            $code = (string) ($data['code'] ?? '');
            foreach ((array) ($data['errors'] ?? []) as $e) {
                if (is_array($e)) {
                    $items[] = ['field' => mb_substr((string) ($e['field'] ?? ''), 0, 60), 'code' => mb_substr((string) ($e['code'] ?? ''), 0, 40)];
                    if ($code === '' && !empty($e['code'])) {
                        $code = (string) $e['code'];
                    }
                }
            }
        }
        $detail = implode(', ', array_map(function ($e) {
            return trim($e['field'] . ($e['code'] !== '' ? ' (' . $e['code'] . ')' : ''));
        }, $items));
        error_log('[freshdesk] ' . $label . ' HTTP ' . $status . ($code !== '' ? ' ' . $code : '') . ($detail !== '' ? ' ' . $detail : ''));

        if ($status >= 300 && $status < 400) {
            throw new FreshdeskException('No Freshdesk account answers at this address (HTTP ' . $status . '). Use the yourcompany.freshdesk.com address.', 'not_found', $status);
        }
        if ($status === 401) {
            throw new FreshdeskException('Freshdesk rejected the API key (HTTP 401). Copy it again from Profile settings → View API key.', 'auth_failed', 401);
        }
        if ($status === 403) {
            if ($code === 'account_suspended') {
                throw new FreshdeskException('The Freshdesk account is suspended (HTTP 403).', 'forbidden', 403);
            }
            if ($code === 'password_lockout') {
                throw new FreshdeskException('Freshdesk locked the agent after too many failed logins (HTTP 403).', 'forbidden', 403);
            }
            throw new FreshdeskException('The Freshdesk agent of this API key is not allowed to do this (HTTP 403' . ($code !== '' ? ', ' . $code : '') . ').', 'forbidden', 403);
        }
        if ($status === 404) {
            throw new FreshdeskException('Freshdesk record or account not found (HTTP 404).', 'not_found', 404);
        }
        if ($status === 409) {
            throw new FreshdeskException('Freshdesk reported a conflict (HTTP 409)' . ($detail !== '' ? ': ' . $detail : '') . '.', 'conflict', 409, null, $items);
        }
        if ($status === 429) {
            $retryAfter = null;
            if (preg_match('/^Retry-After:\s*(\d+)/mi', $r['headers'], $m)) {
                $retryAfter = (int) $m[1];
            }
            throw new FreshdeskException('Freshdesk API rate limit reached. Will retry automatically.', 'rate_limited', 429, $retryAfter);
        }
        if ($status >= 500) {
            throw new FreshdeskException('Freshdesk is temporarily unavailable (HTTP ' . $status . ').', 'unavailable', $status);
        }
        throw new FreshdeskException('Freshdesk rejected the request (HTTP ' . $status . ')' . ($detail !== '' ? ': ' . $detail : '.'), 'validation', $status, null, $items);
    }
}
