<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static array $values = [];

    public static function load(string $envFile): void
    {
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v);
                if ($v !== '' && ($v[0] === '"' || $v[0] === "'") && str_ends_with($v, $v[0])) {
                    $v = substr($v, 1, -1);
                }
                if (getenv($k) === false) {
                    self::$values[$k] = $v;
                }
            }
        }
        foreach ($_ENV as $k => $v) {
            self::$values[$k] = $v;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        $v = self::$values[$key] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    }

    public static function int(string $key, int $default): int
    {
        return (int) (self::get($key, (string) $default) ?? $default);
    }

    public static function float(string $key, float $default): float
    {
        return (float) (self::get($key, (string) $default) ?? $default);
    }

    public static function path(string $key, string $default): string
    {
        $p = self::get($key, $default) ?? $default;
        return str_starts_with($p, '/') ? $p : APP_ROOT . '/' . $p;
    }
}
