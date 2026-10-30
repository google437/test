<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        $sid = Auth::sessionId() ?? self::anonId();
        return Crypto::sign('csrf|' . $sid);
    }

    private static function anonId(): string
    {
        if (empty($_COOKIE['nvvp_anon'])) {
            $id = bin2hex(random_bytes(16));
            setcookie('nvvp_anon', $id, ['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => Auth::secureCookies()]);
            $_COOKIE['nvvp_anon'] = $id;
        }
        return $_COOKIE['nvvp_anon'];
    }

    public static function verify(): void
    {
        $t = $_POST['_csrf'] ?? '';
        if (!is_string($t) || !hash_equals(self::token(), $t)) {
            http_response_code(419);
            exit('Invalid or expired form token. Go back and try again.');
        }
    }

    public static function verifyHeader(): void
    {
        $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals(self::token(), $t)) {
            json_out(['error' => 'csrf'], 419);
        }
    }
}
