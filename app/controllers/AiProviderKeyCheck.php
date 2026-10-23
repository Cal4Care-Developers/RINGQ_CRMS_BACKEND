<?php
declare(strict_types=1);

/**
 * AiProviderKeyCheck -- is the API key an admin typed into the AI agent editor actually accepted?
 *
 * Used when an agent is SAVED, so a mistyped key is refused on the spot instead of being stored and then
 * failing on every call. (The voice bridge makes the same check before answering a call; see the KEYCHECK
 * block in node/claude_server.js -- the two must agree, which is why both are tiny and only trust a clear
 * "that key is not accepted".)
 *
 * It asks the provider for its model list: a cheap authenticated GET. The verdicts:
 *   valid       the provider accepted the key
 *   invalid     the provider clearly refused it: HTTP 401, or Gemini's 400 "API key not valid"
 *   unverified  anything else -- a restricted key refused the model list (403) but may chat fine, a 5xx, a timeout, a
 *               provider that cannot be checked this way (Azure needs the agent's own endpoint). NEVER a reason to
 *               refuse a save: only a clear rejection is.
 *
 * Only fixed provider URLs are ever contacted, never anything taken from the request, so this cannot be pointed
 * at an internal address. The key is sent only to its own provider and is never logged or put in a URL.
 */
class AiProviderKeyCheck
{
    const TIMEOUT_SECONDS = 6;

    /** [url, headers[]] for one provider, or null when it cannot be checked this way. */
    public static function request(string $provider, string $key): ?array
    {
        switch (strtolower(trim($provider))) {
            case 'claude':
                return ['https://api.anthropic.com/v1/models?limit=1', ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01']];
            case 'openai':
                return ['https://api.openai.com/v1/models', ['Authorization: Bearer ' . $key]];
            case 'gemini':
                return ['https://generativelanguage.googleapis.com/v1beta/models?pageSize=1', ['x-goog-api-key: ' . $key]];
            case 'groq':
                return ['https://api.groq.com/openai/v1/models', ['Authorization: Bearer ' . $key]];
            case 'deepseek':
                return ['https://api.deepseek.com/models', ['Authorization: Bearer ' . $key]];
            case 'grok':
                return ['https://api.x.ai/v1/models', ['Authorization: Bearer ' . $key]];
            default:
                return null;
        }
    }

    /** 'valid' | 'invalid' | 'unverified' from the provider's HTTP answer. */
    public static function classify(string $provider, int $status, string $body = ''): string
    {
        if ($status >= 200 && $status < 300) { return 'valid'; }
        if ($status === 401) { return 'invalid'; }
        if ($status === 400 && strtolower(trim($provider)) === 'gemini'
            && (stripos($body, 'API_KEY_INVALID') !== false || stripos($body, 'API key not valid') !== false)) {
            return 'invalid';
        }
        return 'unverified';
    }

    /** The sentence shown to the admin when a key is refused. */
    public static function rejectionMessage(string $provider, int $status): string
    {
        $name = trim($provider) !== '' ? trim($provider) : 'The AI provider';
        return "{$name} rejected this API key (HTTP {$status}), so it was not saved. Check that it is a valid {$name} key, "
            . "that you copied all of it, and that it has not been revoked.";
    }

    /**
     * Asks the provider. $http is injectable for tests: callable(string $url, array $headers): array{0:int,1:string}
     * returning [status, body], status 0 on a network failure.
     * @return array{status:string, httpStatus:int, message:string}
     */
    public static function verify(string $provider, string $key, ?callable $http = null): array
    {
        $key = trim($key);
        $req = $key === '' ? null : self::request($provider, $key);
        if ($req === null) {
            return ['status' => 'unverified', 'httpStatus' => 0, 'message' => ''];
        }
        [$status, $body] = ($http ?? [self::class, 'curlGet'])($req[0], $req[1]);
        $verdict = self::classify($provider, (int) $status, (string) $body);
        return [
            'status'     => $verdict,
            'httpStatus' => (int) $status,
            'message'    => $verdict === 'invalid' ? self::rejectionMessage($provider, (int) $status) : '',
        ];
    }

    /** @return array{0:int,1:string} */
    public static function curlGet(string $url, array $headers): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $errno ? [0, ''] : [$status, is_string($body) ? $body : ''];
    }
}
