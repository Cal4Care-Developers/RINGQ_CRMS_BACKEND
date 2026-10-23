<?php
declare(strict_types=1);

namespace Library\Qontak;

/**
 * Secret storage for the Qontak integration.
 *
 * The master key lives OUTSIDE the code base and the database, in a root-owned
 * file readable by the PHP-FPM group (default /etc/ringq/qontak.key, override
 * with the QONTAK_KEY_FILE environment variable). It is 32 random bytes,
 * base64-encoded. Two purpose-bound sub-keys are derived from it so the
 * encryption key and the URL-signing key are never the same bytes.
 *
 * Ciphertext format: "v1:" . base64(iv[12] . tag[16] . ciphertext), AES-256-GCM.
 * Rotating the master key: decrypt with the old key, re-encrypt with the new
 * one (see README "Key rotation"); the "v1:" prefix leaves room for that.
 */
class QontakCrypto
{
    const DEFAULT_KEY_FILE = '/etc/ringq/qontak.key';
    const CIPHER = 'aes-256-gcm';

    /** @var string|null */
    private static $masterKey = null;

    private static function masterKey(): string
    {
        if (self::$masterKey !== null) {
            return self::$masterKey;
        }
        $file = getenv('QONTAK_KEY_FILE') ?: self::DEFAULT_KEY_FILE;
        if (!is_readable($file)) {
            throw new QontakException('Qontak encryption key is not installed on this server.', 'key_missing');
        }
        $raw = base64_decode(trim((string) file_get_contents($file)), true);
        if ($raw === false || strlen($raw) < 32) {
            throw new QontakException('Qontak encryption key is invalid.', 'key_invalid');
        }
        self::$masterKey = $raw;
        return $raw;
    }

    private static function subKey(string $purpose): string
    {
        return hash_hmac('sha256', 'ringq-qontak:' . $purpose, self::masterKey(), true);
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::subKey('enc'), OPENSSL_RAW_DATA, $iv, $tag, 'qontak', 16);
        if ($cipher === false) {
            throw new QontakException('Could not encrypt the credential.', 'encrypt_failed');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        if (strpos($stored, 'v1:') !== 0) {
            throw new QontakException('Stored credential has an unknown format.', 'decrypt_failed');
        }
        $bin = base64_decode(substr($stored, 3), true);
        if ($bin === false || strlen($bin) < 29) {
            throw new QontakException('Stored credential is corrupt.', 'decrypt_failed');
        }
        $plain = openssl_decrypt(substr($bin, 28), self::CIPHER, self::subKey('enc'), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16), 'qontak');
        if ($plain === false) {
            throw new QontakException('Stored credential could not be decrypted (key changed?).', 'decrypt_failed');
        }
        return $plain;
    }

    /** HMAC for signed recording links and webhook tokens. */
    public static function sign(string $data): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $data, self::subKey('sign'), true)), '+/', '-_'), '=');
    }

    public static function verify(string $data, string $signature): bool
    {
        return hash_equals(self::sign($data), $signature);
    }
}
