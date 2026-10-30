<?php
declare(strict_types=1);

namespace App\Services\Ingest;

use App\Core\Config;
use App\Core\DB;
use App\Core\Log;

/**
 * Clark County inactivated and canceled voter files. These files match on IDNUMBER /
 * REGISTRATION_NUM when present, otherwise on exact name + birth year + precinct.
 * Inactivated voters become status I; canceled voters are marked removed (never deleted).
 */
final class StatusFiles
{
    public function __construct(private DB $db) {}

    public function run(string $source, ?string $file = null, bool $force = false): array
    {
        $db = $this->db;
        $urlEnv = $source === 'clark-inactive' ? 'CLARK_INACTIVE_URL' : 'CLARK_CANCELED_URL';
        $url = $file ?? Config::get($urlEnv);
        if (!$url) {
            return ['status' => 'skipped', 'reason' => "$urlEnv not configured"];
        }
        $newStatus = $source === 'clark-inactive' ? 'I' : 'removed';
        $runId = (int) $db->insert('import_runs', ['source' => $source, 'status' => 'started', 'source_url' => $url]);
        $db->exec('INSERT INTO source_state (source, last_attempt_at) VALUES (:s, now()) ON CONFLICT (source) DO UPDATE SET last_attempt_at = now()', [':s' => $source]);
        $tmp = null;
        try {
            if ($file === null) {
                $f = new Fetcher();
                $head = $f->head($url);
                $state = $db->one('SELECT * FROM source_state WHERE source = :s', [':s' => $source]);
                if (!$force && $state && ($head['etag'] || $head['last_modified']) && $head['etag'] === $state['etag'] && $head['last_modified'] === $state['last_modified']) {
                    Reporter::finish($db, $runId, 'no_change', ['reason' => 'headers unchanged']);
                    return ['run_id' => $runId, 'status' => 'no_change'];
                }
                $tmp = tempnam(APP_ROOT . '/storage/tmp', 'dl') . (str_ends_with(strtolower($url), '.zip') ? '.zip' : '.csv');
                $sha = $f->download($url, $tmp);
                $db->exec('UPDATE source_state SET etag = :e, last_modified = :lm WHERE source = :s', [':e' => $head['etag'], ':lm' => $head['last_modified'], ':s' => $source]);
            } else {
                $tmp = $file;
                $sha = hash_file('sha256', $file);
            }
            if (!$force && $db->val("SELECT 1 FROM import_runs WHERE source = :s AND sha256 = :sha AND status = 'loaded' AND id <> :id", [':s' => $source, ':sha' => $sha, ':id' => $runId])) {
                Reporter::finish($db, $runId, 'no_change', ['reason' => 'identical file']);
                return ['run_id' => $runId, 'status' => 'no_change'];
            }
            $raw = Archive::store($source, $tmp, $sha);
            $db->exec('UPDATE import_runs SET sha256 = :sha, raw_path = :p, status = :st WHERE id = :id', [':sha' => $sha, ':p' => $raw, ':st' => 'fetched', ':id' => $runId]);
            $res = $this->parse(Archive::absolute($raw), $runId, $newStatus);
            Enricher::households($db);
            Reporter::finish($db, $runId, 'loaded', $res);
            return ['run_id' => $runId, 'status' => 'loaded'] + $res;
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

    private function parse(string $path, int $runId, string $newStatus): array
    {
        $fh = CsvReader::open($path);
        $idx = CsvReader::headerIndex(fgetcsv($fh) ?: []);
        $cId = CsvReader::col($idx, ['IDNUMBER', 'REGISTRATION_NUM', 'VOTER_ID']);
        $cFirst = CsvReader::col($idx, ['FIRST_NAME']);
        $cLast = CsvReader::col($idx, ['LAST_NAME']);
        $cBy = CsvReader::col($idx, ['BIRTH_YEAR']);
        $cPre = CsvReader::col($idx, ['PRECINCT_NUM', 'PRECINCT']);
        if ($cId === null && ($cFirst === null || $cLast === null)) {
            throw new \RuntimeException('Status file has neither an id column nor name columns');
        }
        $byId = $this->db->pdo->prepare("SELECT id, status FROM voters WHERE source_county = 'clark' AND county_voter_id = :id");
        $byName = $this->db->pdo->prepare("SELECT v.id, v.status FROM voters v LEFT JOIN precincts p ON p.id = v.precinct_id
            WHERE v.source_county = 'clark' AND upper(v.first_name) = upper(:f) AND upper(v.last_name) = upper(:l) AND (:by::int IS NULL OR v.birth_year = :by::int) AND (:pr::text IS NULL OR p.number = :pr::text)");
        $upd = $this->db->pdo->prepare("UPDATE voters SET status = :s, removed_at = CASE WHEN :s = 'removed' THEN now() ELSE removed_at END, updated_at = now() WHERE id = :id");
        $chg = $this->db->pdo->prepare('INSERT INTO voter_changes (voter_id, run_id, field, old_value, new_value) VALUES (:v, :r, :f, :o, :n)');
        $err = $this->db->pdo->prepare('INSERT INTO import_errors (run_id, line_no, reason, raw) VALUES (:r, :l, :why, :raw)');
        $rows = 0; $updated = 0; $unmatched = 0; $line = 1;
        $this->db->pdo->beginTransaction();
        while (($r = fgetcsv($fh)) !== false) {
            $line++;
            $rows++;
            $match = null;
            if ($cId !== null && trim((string) ($r[$cId] ?? '')) !== '') {
                $byId->execute([':id' => trim((string) $r[$cId])]);
                $match = $byId->fetch() ?: null;
            } elseif ($cFirst !== null && $cLast !== null) {
                $byName->execute([':f' => trim((string) $r[$cFirst]), ':l' => trim((string) $r[$cLast]), ':by' => $cBy !== null && ctype_digit(trim((string) $r[$cBy])) ? (int) $r[$cBy] : null, ':pr' => $cPre !== null ? (trim((string) $r[$cPre]) ?: null) : null]);
                $all = $byName->fetchAll();
                $match = count($all) === 1 ? $all[0] : null;
            }
            if (!$match) {
                $unmatched++;
                if ($unmatched <= 1000) {
                    $err->execute([':r' => $runId, ':l' => $line, ':why' => 'no unique match in voter file', ':raw' => json_encode(['line' => $line])]);
                }
                continue;
            }
            if ($match['status'] !== $newStatus) {
                $chg->execute([':v' => $match['id'], ':r' => $runId, ':f' => 'status', ':o' => $match['status'], ':n' => $newStatus]);
                $upd->execute([':s' => $newStatus, ':id' => $match['id']]);
                $updated++;
            }
        }
        $this->db->pdo->commit();
        fclose($fh);
        $this->db->exec('UPDATE import_runs SET rows_total = :n, rows_error = :e, rows_changed = :c WHERE id = :id', [':n' => $rows, ':e' => $unmatched, ':c' => $updated, ':id' => $runId]);
        return ['rows' => $rows, 'changed' => $updated, 'errors' => $unmatched];
    }
}
