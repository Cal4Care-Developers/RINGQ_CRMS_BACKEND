<?php
declare(strict_types=1);

/**
 * Shared authentication for RingQ's external machine-to-machine APIs.
 *
 * The signature is the lowercase SHA-256 HMAC of the exact bytes
 * apiKey || timestamp || nonce || rawBody. Keep this class independent of
 * Phalcon types so the contract can be exercised from the command line.
 */
class ApiAuth
{
    /**
     * @return array<string, mixed> the active key record from Redis
     * @throws \Exception with a stable AUTH_* reason
     */
    public static function authenticate($request, $redis): array
    {
        $apiKey = $request->getHeader('X-API-KEY');
        $timestamp = $request->getHeader('X-TIMESTAMP');
        $nonce = $request->getHeader('X-NONCE');
        $signature = $request->getHeader('X-SIGNATURE');

        if (!$apiKey || !$timestamp || !$nonce || !$signature) {
            throw new \Exception('AUTH_MISSING');
        }

        if (abs(time() - (int)$timestamp) > 30) {
            throw new \Exception('AUTH_EXPIRED');
        }

        $nonceKey = "nonce:$apiKey:$nonce";
        if ($redis->exists($nonceKey)) {
            throw new \Exception('AUTH_REPLAY');
        }
        $redis->setex($nonceKey, 60, 1);

        $keyData = $redis->hGetAll("call_api_keys:$apiKey");
        if (!$keyData || ($keyData['status'] ?? '') !== 'active') {
            throw new \Exception('AUTH_INVALID_KEY');
        }

        $rawBody = $request->getRawBody() ?: '';
        $payload = $apiKey . $timestamp . $nonce . $rawBody;
        $expected = hash_hmac('sha256', $payload, $keyData['secret']);
        if (!hash_equals($expected, $signature)) {
            throw new \Exception('AUTH_BAD_SIGNATURE');
        }

        return $keyData;
    }
}
