<?php
declare(strict_types=1);

namespace App\Services\Ingest;

use App\Core\DB;

final class Elections
{
    /** Normalize county election codes: "26GE" -> "26G", "10g" -> "10G". */
    public static function normalize(string $code): string
    {
        $c = strtoupper(trim($code));
        if (preg_match('/^(\d{2})([PG])E?$/', $c, $m)) {
            return $m[1] . $m[2];
        }
        return $c;
    }

    public static function isValid(string $code): bool
    {
        return (bool) preg_match('/^\d{2}[PG]$/', $code);
    }

    /** Approximate date and name from a code. Nevada: primary second Tuesday of June, general first Tuesday after first Monday of November. */
    public static function describe(string $code): ?array
    {
        if (!self::isValid($code)) {
            return null;
        }
        $yy = (int) substr($code, 0, 2);
        $cur = (int) date('y');
        $year = $yy <= $cur + 2 ? 2000 + $yy : 1900 + $yy;
        $type = $code[2] === 'P' ? 'primary' : 'general';
        if ($type === 'general') {
            $d = new \DateTimeImmutable("first monday of november $year");
            $d = $d->modify('+1 day');
        } else {
            $d = new \DateTimeImmutable("second tuesday of june $year");
        }
        return ['code' => $code, 'election_date' => $d->format('Y-m-d'), 'type' => $type, 'name' => $year . ' ' . ucfirst($type)];
    }

    public static function ensure(DB $db, string $code): void
    {
        $d = self::describe($code);
        if ($d) {
            $db->exec('INSERT INTO elections (code, election_date, type, name) VALUES (:c, :d, :t, :n) ON CONFLICT (code) DO NOTHING', [':c' => $d['code'], ':d' => $d['election_date'], ':t' => $d['type'], ':n' => $d['name']]);
        }
    }

    public static function syncFromHistory(DB $db): void
    {
        foreach ($db->all('SELECT DISTINCT election_code FROM vote_history WHERE election_code NOT IN (SELECT code FROM elections)') as $r) {
            self::ensure($db, $r['election_code']);
        }
    }
}
