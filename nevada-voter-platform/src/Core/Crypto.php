<?php
declare(strict_types=1);

namespace App\Core;

/** AES-256-GCM encryption for secrets at rest (TOTP seeds), keyed from APP_KEY. */
final class Crypto
{
    private static function key(): string
    {
        $k = Config::get('APP_KEY', '');
        if ($k === null || strlen($k) < 16) {
            throw new \RuntimeException('APP_KEY is not set. Run: php bin/nvvp key');
        }
        return hash('sha256', $k, true);
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $enc): string
    {
        $raw = base64_decode($enc, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('Bad ciphertext');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct = substr($raw, 28);
        $pt = openssl_decrypt($ct, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($pt === false) {
            throw new \RuntimeException('Decrypt failed');
        }
        return $pt;
    }

    public static function sign(string $data): string
    {
        return hash_hmac('sha256', $data, self::key());
    }
}
