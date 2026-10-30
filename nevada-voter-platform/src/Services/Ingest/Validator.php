<?php
declare(strict_types=1);

namespace App\Services\Ingest;

use App\Core\Config;
use App\Core\DB;

final class Validator
{
    /** Returns null when the run is OK, or a hold reason. */
    public static function check(DB $db, int $runId, string $source, array $parse): ?string
    {
        if ($parse['columns_missing']) {
            return 'Required columns missing: ' . implode(', ', $parse['columns_missing']);
        }
        $rows = (int) $parse['rows'];
        if ($rows === 0) {
            return 'File contained no data rows';
        }
        $unknownPct = $rows > 0 ? 100.0 * $parse['unknown_codes'] / $rows : 0.0;
        $db->exec('UPDATE import_runs SET unknown_code_pct = :u WHERE id = :id', [':u' => round($unknownPct, 3), ':id' => $runId]);
        if ($unknownPct > Config::float('VALIDATE_MAX_UNKNOWN_CODE_PCT', 0.5)) {
            return sprintf('Unknown code values in %.2f%% of rows (limit %.2f%%)', $unknownPct, Config::float('VALIDATE_MAX_UNKNOWN_CODE_PCT', 0.5));
        }
        $prev = $db->val("SELECT rows_total FROM import_runs WHERE source = :s AND status = 'loaded' AND id <> :id ORDER BY finished_at DESC LIMIT 1", [':s' => $source, ':id' => $runId]);
        $maxDelta = Config::float('VALIDATE_MAX_ROW_DELTA_PCT', 3);
        if ($prev !== null && (int) $prev > 0) {
            $delta = 100.0 * abs($rows - (int) $prev) / (int) $prev;
            if ($delta > $maxDelta) {
                return sprintf('Row count moved %.2f%% (%s -> %s); limit is %.1f%%', $delta, number_format((int) $prev), number_format($rows), $maxDelta);
            }
        }
        // Optional check against the county's published registration total, entered by staff in settings.
        $published = $db->val("SELECT value FROM settings WHERE key = 'clark_published_total'");
        if ($published !== null && (int) $published > 0 && $source === 'clark-voters') {
            $delta = 100.0 * abs($rows - (int) $published) / (int) $published;
            if ($delta > $maxDelta) {
                return sprintf('Row count differs from published county total by %.2f%% (%s vs %s)', $delta, number_format($rows), number_format((int) $published));
            }
        }
        return null;
    }
}
