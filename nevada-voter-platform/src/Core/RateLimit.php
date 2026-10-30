<?php
declare(strict_types=1);

namespace App\Core;

final class RateLimit
{
    /** Returns true when the action is allowed. Fixed window counter stored in the database. */
    public static function allow(string $key, int $max, int $windowSeconds): bool
    {
        $db = DB::app();
        $window = date('Y-m-d H:i:s', intdiv(time(), $windowSeconds) * $windowSeconds);
        $count = $db->val(
            'INSERT INTO rate_limits (key, window_start, count) VALUES (:k, :w, 1)
             ON CONFLICT (key, window_start) DO UPDATE SET count = rate_limits.count + 1 RETURNING count',
            [':k' => $key, ':w' => $window]
        );
        if (random_int(1, 200) === 1) {
            $db->exec("DELETE FROM rate_limits WHERE window_start < now() - interval '1 day'");
        }
        return (int) $count <= $max;
    }
}
