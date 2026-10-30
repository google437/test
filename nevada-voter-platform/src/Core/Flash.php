<?php
declare(strict_types=1);

namespace App\Core;

final class Flash
{
    public static function set(string $type, string $msg): void
    {
        setcookie('nvvp_flash', base64_encode(json_encode([$type, $msg])), ['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => Auth::secureCookies()]);
    }

    public static function pull(): ?array
    {
        if (empty($_COOKIE['nvvp_flash'])) {
            return null;
        }
        $v = json_decode(base64_decode($_COOKIE['nvvp_flash']) ?: '', true);
        setcookie('nvvp_flash', '', ['path' => '/', 'expires' => 1]);
        return is_array($v) && count($v) === 2 ? ['type' => $v[0], 'msg' => $v[1]] : null;
    }
}
