<?php
declare(strict_types=1);

namespace App\Services\Ingest;

use App\Core\Config;
use App\Core\DB;
use App\Core\Log;

/**
 * Election-window feeds: the mail ballot return file and the early vote return file.
 * Both key on IDNUMBER and an election code; results land in ballot_activity and drive the
 * "already voted" flag used by chase lists.
 */
final class BallotFiles
{
    public function __construct(private DB $db) {}

    public function run(string $source, ?string $file = null, bool $force = false): array
    {
        $db = $this->db;
        $urlEnv = $source === 'clark-mail' ? 'CLARK_MAIL_URL' : 'CLARK_EV_URL';
        $url = $file ?? Config::get($urlEnv);
        if (!$url) {
            return ['status' => 'skipped', 'reason' => "$urlEnv not configured"];
        }
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
            $res = $source === 'clark-mail' ? $this->parseMail(Archive::absolute($raw), $runId) : $this->parseEv(Archive::absolute($raw), $runId);
            $db->exec('UPDATE import_runs SET rows_total = :n, rows_error = :e WHERE id = :id', [':n' => $res['rows'], ':e' => $res['errors'], ':id' => $runId]);
            Enricher::deriveCurrentFlags($db);
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

    private function parseMail(string $path, int $runId): array
    {
        $layout = (require APP_ROOT . '/config/clark_layout.php')['mail'];
        $fh = CsvReader::open($path);
        $idx = CsvReader::headerIndex(fgetcsv($fh) ?: []);
        $cId = CsvReader::col($idx, $layout['id']);
        $cEl = CsvReader::col($idx, $layout['election']);
        if ($cId === null || $cEl === null) {
            throw new \RuntimeException('Mail ballot file is missing IDNUMBER or ELECTION_CODE');
        }
        $cMailed = CsvReader::col($idx, $layout['mailed']);
        $cRecv = CsvReader::col($idx, $layout['received']);
        $cCode = CsvReader::col($idx, $layout['return_code']);
        $st = $this->db->pdo->prepare("INSERT INTO ballot_activity (voter_id, election_code, mail_requested, mail_sent_date, mail_returned, mail_returned_date, return_code, source_run)
            SELECT v.id, :el, true, :sent, :ret, :retd, :code, :run FROM voters v WHERE v.source_county = 'clark' AND v.county_voter_id = :cvid
            ON CONFLICT (voter_id, election_code) DO UPDATE SET mail_requested = true, mail_sent_date = COALESCE(EXCLUDED.mail_sent_date, ballot_activity.mail_sent_date),
                mail_returned = EXCLUDED.mail_returned OR ballot_activity.mail_returned, mail_returned_date = COALESCE(EXCLUDED.mail_returned_date, ballot_activity.mail_returned_date),
                return_code = COALESCE(EXCLUDED.return_code, ballot_activity.return_code), source_run = EXCLUDED.source_run, updated_at = now()");
        $rows = 0;
        $errors = 0;
        $unmatched = 0;
        $this->db->pdo->beginTransaction();
        while (($r = fgetcsv($fh)) !== false) {
            $id = trim((string) ($r[$cId] ?? ''));
            $el = Elections::normalize((string) ($r[$cEl] ?? ''));
            if ($id === '' || !Elections::isValid($el)) {
                $errors++;
                continue;
            }
            Elections::ensure($this->db, $el);
            $recv = CsvReader::parseDate($cRecv === null ? null : $r[$cRecv] ?? null);
            $st->execute([':el' => $el, ':sent' => CsvReader::parseDate($cMailed === null ? null : $r[$cMailed] ?? null), ':ret' => $recv !== null ? 't' : 'f', ':retd' => $recv,
                ':code' => $cCode === null ? null : (trim((string) $r[$cCode]) ?: null), ':run' => $runId, ':cvid' => $id]);
            if ($st->rowCount() === 0) {
                $unmatched++;
            }
            $rows++;
        }
        $this->db->pdo->commit();
        fclose($fh);
        return ['rows' => $rows, 'errors' => $errors, 'unmatched' => $unmatched];
    }

    private function parseEv(string $path, int $runId): array
    {
        $layout = (require APP_ROOT . '/config/clark_layout.php')['ev'];
        $fh = CsvReader::open($path);
        $idx = CsvReader::headerIndex(fgetcsv($fh) ?: []);
        $cId = CsvReader::col($idx, $layout['id']);
        $cEl = CsvReader::col($idx, $layout['election']);
        if ($cId === null || $cEl === null) {
            throw new \RuntimeException('Early vote file is missing IDNUMBER or ELECTION CODE');
        }
        $cSite = CsvReader::col($idx, $layout['site']);
        $cWhen = CsvReader::col($idx, $layout['voted_at']);
        $st = $this->db->pdo->prepare("INSERT INTO ballot_activity (voter_id, election_code, ev_voted_on, ev_site, source_run)
            SELECT v.id, :el, :when, :site, :run FROM voters v WHERE v.source_county = 'clark' AND v.county_voter_id = :cvid
            ON CONFLICT (voter_id, election_code) DO UPDATE SET ev_voted_on = COALESCE(EXCLUDED.ev_voted_on, ballot_activity.ev_voted_on, now()), ev_site = COALESCE(EXCLUDED.ev_site, ballot_activity.ev_site), source_run = EXCLUDED.source_run, updated_at = now()");
        $rows = 0;
        $errors = 0;
        $unmatched = 0;
        $this->db->pdo->beginTransaction();
        while (($r = fgetcsv($fh)) !== false) {
            $id = trim((string) ($r[$cId] ?? ''));
            $el = Elections::normalize((string) ($r[$cEl] ?? ''));
            if ($id === '' || !Elections::isValid($el)) {
                $errors++;
                continue;
            }
            Elections::ensure($this->db, $el);
            $when = $cWhen === null ? null : CsvReader::parseDateTime($r[$cWhen] ?? null);
            $st->execute([':el' => $el, ':when' => $when ?? date('Y-m-d H:i:s'), ':site' => $cSite === null ? null : (trim((string) $r[$cSite]) ?: null), ':run' => $runId, ':cvid' => $id]);
            if ($st->rowCount() === 0) {
                $unmatched++;
            }
            $rows++;
        }
        $this->db->pdo->commit();
        fclose($fh);
        return ['rows' => $rows, 'errors' => $errors, 'unmatched' => $unmatched];
    }
}
