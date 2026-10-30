<?php
declare(strict_types=1);

namespace App\Services\Ingest;

use App\Core\Config;
use App\Core\DB;
use App\Core\Log;

/**
 * One run of the voter-list pipeline: check, fetch, archive, parse, validate, diff, apply, enrich, report.
 * Idempotent: an unchanged ETag/Last-Modified or an identical SHA-256 ends the run as no_change.
 */
final class Pipeline
{
    public const SOURCES = [
        'clark-voters' => ['file_env' => 'CLARK_ALL_FILE', 'full_snapshot' => true, 'county' => 'clark'],
        'clark-active' => ['file_env' => 'CLARK_ACTIVE_FILE', 'full_snapshot' => false, 'county' => 'clark'],
    ];

    public function __construct(private DB $db) {}

    public static function sourceUrl(string $source): ?string
    {
        $cfg = self::SOURCES[$source] ?? null;
        if (!$cfg) {
            return null;
        }
        $file = Config::get($cfg['file_env']);
        return $file ? rtrim(Config::get('CLARK_BASE_URL', 'https://elections.clarkcountynv.gov/VoterRequestsTV/'), '/') . '/' . $file : null;
    }

    /**
     * @param string|null $file local file override (skips the HEAD check and download)
     */
    public function run(string $source, ?string $file = null, bool $force = false): array
    {
        $cfg = self::SOURCES[$source] ?? null;
        if (!$cfg) {
            throw new \InvalidArgumentException("Unknown source $source");
        }
        $db = $this->db;
        $url = $file ?? self::sourceUrl($source);
        $runId = (int) $db->insert('import_runs', ['source' => $source, 'status' => 'started', 'source_url' => $url]);
        $db->exec('INSERT INTO source_state (source, last_attempt_at) VALUES (:s, now()) ON CONFLICT (source) DO UPDATE SET last_attempt_at = now()', [':s' => $source]);
        $tmp = null;
        try {
            $state = $db->one('SELECT * FROM source_state WHERE source = :s', [':s' => $source]);
            $fetcher = new Fetcher();
            if ($file === null) {
                // 1. Check
                $head = $fetcher->head($url);
                if ($head['status'] >= 400) {
                    throw new \RuntimeException("Source returned HTTP {$head['status']}");
                }
                $db->exec('UPDATE import_runs SET etag = :e, last_modified = :lm WHERE id = :id', [':e' => $head['etag'], ':lm' => $head['last_modified'], ':id' => $runId]);
                if (!$force && $state && ($head['etag'] || $head['last_modified']) && $head['etag'] === $state['etag'] && $head['last_modified'] === $state['last_modified']) {
                    Reporter::finish($db, $runId, 'no_change', ['reason' => 'headers unchanged']);
                    return ['run_id' => $runId, 'status' => 'no_change'];
                }
                // 2. Fetch
                $tmp = tempnam(APP_ROOT . '/storage/tmp', 'dl') . '.zip';
                $sha = $fetcher->download($url, $tmp);
                $db->exec('INSERT INTO source_state (source, etag, last_modified) VALUES (:s, :e, :lm) ON CONFLICT (source) DO UPDATE SET etag = :e, last_modified = :lm', [':s' => $source, ':e' => $head['etag'], ':lm' => $head['last_modified']]);
            } else {
                if (!is_file($file)) {
                    throw new \RuntimeException('File not found');
                }
                $tmp = $file;
                $sha = hash_file('sha256', $file);
            }
            $db->exec('UPDATE import_runs SET sha256 = :sha, status = :st WHERE id = :id', [':sha' => $sha, ':st' => 'fetched', ':id' => $runId]);
            if (!$force && $db->val("SELECT 1 FROM import_runs WHERE source = :s AND sha256 = :sha AND status = 'loaded' AND id <> :id", [':s' => $source, ':sha' => $sha, ':id' => $runId])) {
                Reporter::finish($db, $runId, 'no_change', ['reason' => 'identical file (sha256 match)']);
                return ['run_id' => $runId, 'status' => 'no_change'];
            }
            // 3. Archive
            $raw = Archive::store($source, $tmp, $sha);
            $db->exec('UPDATE import_runs SET raw_path = :p WHERE id = :id', [':p' => $raw, ':id' => $runId]);
            $db->exec('UPDATE source_state SET sha256 = :sha, last_change_at = now() WHERE source = :s', [':sha' => $sha, ':s' => $source]);
            return $this->processArchived($runId, $source, Archive::absolute($raw));
        } catch (\Throwable $e) {
            Log::error("Run #$runId failed: " . $e->getMessage());
            Reporter::finish($db, $runId, 'failed', ['error' => $e->getMessage()]);
            return ['run_id' => $runId, 'status' => 'failed', 'error' => $e->getMessage()];
        } finally {
            if ($tmp && $file === null && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** Steps 4 to 9 from an archived raw file (also used for re-runs and approvals). */
    public function processArchived(int $runId, string $source, string $path): array
    {
        $db = $this->db;
        $cfg = self::SOURCES[$source];
        // 4. Parse
        $db->exec('DELETE FROM staging_voters WHERE run_id = :r', [':r' => $runId]);
        $parse = (new ClarkVoterParser())->parse($db, $path, $runId, $cfg['county']);
        $db->exec('UPDATE import_runs SET rows_total = :n, rows_error = :e, status = :s WHERE id = :id', [':n' => $parse['rows'], ':e' => $parse['errors'], ':s' => 'parsed', ':id' => $runId]);
        // 5. Validate
        $hold = Validator::check($db, $runId, $source, $parse);
        if ($hold !== null) {
            Reporter::finish($db, $runId, 'held', ['rows' => $parse['rows'], 'errors' => $parse['errors']], $hold);
            return ['run_id' => $runId, 'status' => 'held', 'reason' => $hold];
        }
        return $this->diffApply($runId, $source, $parse);
    }

    public function diffApply(int $runId, string $source, array $parse): array
    {
        $db = $this->db;
        $cfg = self::SOURCES[$source];
        // 6. Diff
        $diff = Differ::classify($db, $runId);
        $removedPreview = $cfg['full_snapshot'] ? Differ::countRemoved($db, $runId, $cfg['county']) : 0;
        // A diff that would remove a large share of the file is itself suspicious: hold it.
        $live = (int) $db->val("SELECT count(*) FROM voters WHERE source_county = :c AND status <> 'removed'", [':c' => $cfg['county']]);
        if ($live > 0 && $removedPreview > $live * Config::float('VALIDATE_MAX_ROW_DELTA_PCT', 3) / 100 && !$db->val('SELECT approved_by FROM import_runs WHERE id = :id', [':id' => $runId])) {
            Reporter::finish($db, $runId, 'held', $diff + ['rows' => $parse['rows'], 'errors' => $parse['errors'], 'removed_preview' => $removedPreview],
                sprintf('%s voters (%.1f%% of the live file) would be marked removed', number_format($removedPreview), 100 * $removedPreview / $live));
            return ['run_id' => $runId, 'status' => 'held'];
        }
        // 7. Apply
        $applied = Applier::apply($db, $runId, $cfg['full_snapshot'] ? [$cfg['county']] : []);
        // 8. Enrich
        $enrich = Enricher::run($db);
        // 9. Report
        $summary = ['rows' => $parse['rows'], 'errors' => $parse['errors']] + $applied + ['enrich' => $enrich];
        Reporter::finish($db, $runId, 'loaded', $summary);
        return ['run_id' => $runId, 'status' => 'loaded'] + $summary;
    }

    /** Approve a held run: continue from its staged rows (or re-parse from the archive if staging was cleared). */
    public function approve(int $runId, int $byUserId): array
    {
        $db = $this->db;
        $run = $db->one('SELECT * FROM import_runs WHERE id = :id', [':id' => $runId]);
        if (!$run || !in_array($run['status'], ['held', 'approved'], true)) {
            throw new \RuntimeException('Run is not held');
        }
        $db->exec("UPDATE import_runs SET approved_by = :u, status = 'approved' WHERE id = :id", [':u' => $byUserId, ':id' => $runId]);
        $staged = (int) $db->val('SELECT count(*) FROM staging_voters WHERE run_id = :r', [':r' => $runId]);
        if ($staged === 0) {
            $parse = (new ClarkVoterParser())->parse($db, Archive::absolute($run['raw_path']), $runId, self::SOURCES[$run['source']]['county']);
        } else {
            $parse = ['rows' => $staged, 'errors' => (int) $run['rows_error'], 'unknown_codes' => 0, 'columns_missing' => []];
        }
        try {
            return $this->diffApply($runId, $run['source'], $parse);
        } catch (\Throwable $e) {
            Reporter::finish($db, $runId, 'failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function reject(int $runId, int $byUserId): void
    {
        $this->db->exec("UPDATE import_runs SET status = 'rejected', approved_by = :u, finished_at = now() WHERE id = :id AND status IN ('held','approved')", [':u' => $byUserId, ':id' => $runId]);
        $this->db->exec('DELETE FROM staging_voters WHERE run_id = :r', [':r' => $runId]);
    }

    /** Rebuild from an archived raw file (new run row, same source). */
    public function rerun(int $fromRunId): array
    {
        $run = $this->db->one('SELECT * FROM import_runs WHERE id = :id', [':id' => $fromRunId]);
        if (!$run || !$run['raw_path']) {
            throw new \RuntimeException('Run has no archived file');
        }
        $newId = (int) $this->db->insert('import_runs', ['source' => $run['source'], 'status' => 'fetched', 'source_url' => $run['source_url'], 'sha256' => $run['sha256'], 'raw_path' => $run['raw_path'], 'summary' => json_encode(['rerun_of' => $fromRunId])]);
        try {
            return $this->processArchived($newId, $run['source'], Archive::absolute($run['raw_path']));
        } catch (\Throwable $e) {
            Reporter::finish($this->db, $newId, 'failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
