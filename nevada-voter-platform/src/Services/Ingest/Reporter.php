<?php
declare(strict_types=1);

namespace App\Services\Ingest;

use App\Core\Alerts;
use App\Core\DB;
use App\Core\Log;

final class Reporter
{
    public static function finish(DB $db, int $runId, string $status, array $summary, ?string $holdReason = null): void
    {
        $run = $db->one('SELECT * FROM import_runs WHERE id = :id', [':id' => $runId]);
        $ms = $run ? (int) ((microtime(true) - strtotime($run['started_at'])) * 1000) : null;
        $db->exec('UPDATE import_runs SET status = :s, summary = :sum, hold_reason = :h, finished_at = now(), duration_ms = :ms,
                   rows_new = COALESCE(:n, rows_new), rows_changed = COALESCE(:c, rows_changed), rows_unchanged = COALESCE(:u, rows_unchanged), rows_removed = COALESCE(:rm, rows_removed) WHERE id = :id',
            [':s' => $status, ':sum' => json_encode($summary), ':h' => $holdReason, ':ms' => $ms, ':id' => $runId,
             ':n' => $summary['new'] ?? null, ':c' => $summary['changed'] ?? null, ':u' => $summary['unchanged'] ?? null, ':rm' => $summary['removed'] ?? null]);
        $db->exec('INSERT INTO source_state (source, last_attempt_at, last_run_id) VALUES (:s, now(), :r) ON CONFLICT (source) DO UPDATE SET last_attempt_at = now(), last_run_id = :r', [':s' => $run['source'] ?? 'unknown', ':r' => $runId]);
        $source = $run['source'] ?? '?';
        $line = sprintf('[%s] run #%d %s', $source, $runId, $status);
        if ($status === 'loaded') {
            $line .= sprintf(': %s rows, %s new, %s changed, %s removed, %s errors, %.1fs',
                number_format((int) ($summary['rows'] ?? 0)), number_format((int) ($summary['new'] ?? 0)), number_format((int) ($summary['changed'] ?? 0)),
                number_format((int) ($summary['removed'] ?? 0)), number_format((int) ($summary['errors'] ?? 0)), ($ms ?? 0) / 1000);
            Alerts::send("Import loaded: $source", $line);
        } elseif ($status === 'held') {
            Alerts::send("Import HELD for review: $source", $line . "\nReason: " . $holdReason . "\nApprove or reject it in the admin console.");
        } elseif ($status === 'failed') {
            Alerts::send("Import FAILED: $source", $line . "\n" . ($summary['error'] ?? ''));
        } else {
            Log::info($line);
        }
    }
}
