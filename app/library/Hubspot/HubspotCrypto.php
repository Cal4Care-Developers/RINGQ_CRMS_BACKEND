<?php
declare(strict_types=1);

namespace Library\Hubspot;

/**
 * Secret storage for the HubSpot integration.
 *
 * The token is stored encrypted in the database (q_hubspot_config.access_token_enc).
 *
 * The master secret is RingQ's own application secret, `application.cryptSalt`
 * in app/config/config.php -- the same secret RingQ's built-in `crypt` service
 * uses (services.php). So no extra key file is needed, and the key is never
 * stored in the database next to the encrypted token.
 *
 * Keys are derived from it with a "ringq-hubspot:" label, so they are
 * HubSpot-only: separate from RingQ's own crypt service and every other
 * integration. Two purpose-bound sub-keys are derived, so the encryption key
 * and the URL-signing key are never the same bytes.
 *
 * Optional: setting HUBSPOT_KEY_FILE (path to 32 random bytes, base64) uses
 * that file instead of cryptSalt.
 *
 * Ciphertext format: "v1:" . base64(iv[12] . tag[16] . ciphertext), AES-256-GCM.
 */
class HubspotCrypto
{
    const CIPHER = 'aes-256-gcm';
    const AAD = 'hubspot';

    /** @var string|null */
    private static $masterKey = null;

    private static function masterKey(): string
    {
        if (self::$masterKey !== null) {
            return self::$masterKey;
        }
        // Optional override: a dedicated key file.
        $file = (string) getenv('HUBSPOT_KEY_FILE');
        if ($file !== '') {
            $raw = is_readable($file) ? base64_decode(trim((string) file_get_contents($file)), true) : false;
            if ($raw === false || strlen($raw) < 32) {
                throw new HubspotException('HUBSPOT_KEY_FILE is set but is not readable or not a valid key.', 'key_invalid');
            }
            return self::$masterKey = $raw;
        }
        // Default: RingQ's application secret (app/config/config.php → application.cryptSalt).
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
            throw new HubspotException('RingQ application secret (application.cryptSalt in app/config/config.php) is missing, so the HubSpot token cannot be encrypted.', 'key_missing');
        }
        return self::$masterKey = hash('sha256', 'ringq-hubspot-master:' . $salt, true);
    }

    private static function subKey(string $purpose): string
    {
        return hash_hmac('sha256', 'ringq-hubspot:' . $purpose, self::masterKey(), true);
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::subKey('enc'), OPENSSL_RAW_DATA, $iv, $tag, self::AAD, 16);
        if ($cipher === false) {
            throw new HubspotException('Could not encrypt the access token.', 'encrypt_failed');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        if (strpos($stored, 'v1:') !== 0) {
            throw new HubspotException('Stored access token has an unknown format.', 'decrypt_failed');
        }
        $bin = base64_decode(substr($stored, 3), true);
        if ($bin === false || strlen($bin) < 29) {
            throw new HubspotException('Stored access token is corrupt.', 'decrypt_failed');
        }
        $plain = openssl_decrypt(substr($bin, 28), self::CIPHER, self::subKey('enc'), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16), self::AAD);
        if ($plain === false) {
            throw new HubspotException('Stored access token could not be decrypted (was the key file changed?). Re-enter the token.', 'decrypt_failed');
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
