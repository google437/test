<?php
declare(strict_types=1);

namespace App\Core;

final class Log
{
    public static function write(string $level, string $msg): void
    {
        $dir = APP_ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf("[%s] %s %s\n", date('c'), strtoupper($level), $msg);
        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
        if (PHP_SAPI === 'cli' && $level !== 'debug') {
            fwrite(STDERR, $line);
        }
    }

    public static function info(string $m): void { self::write('info', $m); }
    public static function error(string $m): void { self::write('error', $m); }
    public static function warn(string $m): void { self::write('warn', $m); }
}
