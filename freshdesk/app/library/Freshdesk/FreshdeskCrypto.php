<?php
declare(strict_types=1);

namespace Library\Freshdesk;

/**
 * Secret storage for the Freshdesk integration (same scheme as HubspotCrypto).
 *
 * The API key is stored encrypted in q_freshdesk_config.api_key_enc. The master
 * secret is RingQ's own application secret, `application.cryptSalt` in
 * app/config/config.php, so no key file is needed and the key is never stored
 * in the database next to the ciphertext.
 *
 * Keys are derived with a "ringq-freshdesk:" label, so they are Freshdesk-only:
 * separate from RingQ's crypt service and every other integration. Two
 * purpose-bound sub-keys: one encrypts, one signs recording links.
 *
 * Optional: FRESHDESK_KEY_FILE (path to 32 random bytes, base64) is used
 * instead of cryptSalt.
 *
 * Ciphertext format: "v1:" . base64(iv[12] . tag[16] . ciphertext), AES-256-GCM.
 */
class FreshdeskCrypto
{
    const CIPHER = 'aes-256-gcm';
    const AAD = 'freshdesk';

    /** @var string|null */
    private static $masterKey = null;

    private static function masterKey(): string
    {
        if (self::$masterKey !== null) {
            return self::$masterKey;
        }
        $file = (string) getenv('FRESHDESK_KEY_FILE');
        if ($file !== '') {
            $raw = is_readable($file) ? base64_decode(trim((string) file_get_contents($file)), true) : false;
            if ($raw === false || strlen($raw) < 32) {
                throw new FreshdeskException('FRESHDESK_KEY_FILE is set but is not readable or not a valid key.', 'key_invalid');
            }
            return self::$masterKey = $raw;
        }
        $salt = '';
        try {
            $di = \Phalcon\Di::getDefault();
            if ($di && $di->has('config')) {
                $salt = (string) ($di->getShared('config')->application->cryptSalt ?? '');
            }
        } catch (\Throwable $e) {
            $salt = '';
        }
        if (strlen($salt) < 16) {
            throw new FreshdeskException('RingQ application secret (application.cryptSalt in app/config/config.php) is missing, so the Freshdesk API key cannot be encrypted.', 'key_missing');
        }
        return self::$masterKey = hash('sha256', 'ringq-freshdesk-master:' . $salt, true);
    }

    private static function subKey(string $purpose): string
    {
        return hash_hmac('sha256', 'ringq-freshdesk:' . $purpose, self::masterKey(), true);
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::subKey('enc'), OPENSSL_RAW_DATA, $iv, $tag, self::AAD, 16);
        if ($cipher === false) {
            throw new FreshdeskException('Could not encrypt the API key.', 'encrypt_failed');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        if (strpos($stored, 'v1:') !== 0) {
            throw new FreshdeskException('Stored API key has an unknown format.', 'decrypt_failed');
        }
        $bin = base64_decode(substr($stored, 3), true);
        if ($bin === false || strlen($bin) < 29) {
            throw new FreshdeskException('Stored API key is corrupt.', 'decrypt_failed');
        }
        $plain = openssl_decrypt(substr($bin, 28), self::CIPHER, self::subKey('enc'), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16), self::AAD);
        if ($plain === false) {
            throw new FreshdeskException('Stored API key could not be decrypted (was the application secret changed?). Re-enter the API key.', 'decrypt_failed');
        }
        return $plain;
    }

    /** URL-safe HMAC for signed recording links. */
    public static function sign(string $data): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $data, self::subKey('sign'), true)), '+/', '-_'), '=');
    }

    public static function verify(string $data, string $signature): bool
    {
        return hash_equals(self::sign($data), $signature);
    }
}
