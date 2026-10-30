<?php
declare(strict_types=1);

namespace App\Services\Ingest;

use App\Core\DB;
use App\Core\Log;

/**
 * Nevada Secretary of State statewide (NevVoter) list, uploaded manually until scripted download
 * is approved. Maps the SOS column names onto the same staging table and reuses diff/apply per county.
 * Column aliases cover the published eligible-voter-list layout; adjust config if the SOS changes it.
 */
final class SosParser
{
    private const ALIASES = [
        'id' => ['VOTERID', 'VOTER_ID', 'VOTER ID', 'SOS_VOTER_ID'],
        'county' => ['COUNTY'],
        'county_voter_id' => ['COUNTYVOTERID', 'COUNTY_VOTER_ID', 'COUNTY VOTER ID'],
        'first_name' => ['FIRSTNAME', 'FIRST_NAME'], 'middle_name' => ['MIDDLENAME', 'MIDDLE_NAME'], 'last_name' => ['LASTNAME', 'LAST_NAME'], 'suffix' => ['SUFFIX', 'NAME_SUFFIX'],
        'birth_date' => ['BIRTHDATE', 'BIRTH_DATE', 'DOB'], 'registration_date' => ['REGISTRATIONDATE', 'REGISTRATION_DATE'],
        'address1' => ['ADDRESS1', 'ADDRESS_1', 'RESIDENCE ADDRESS'], 'address2' => ['ADDRESS2', 'ADDRESS_2'], 'city' => ['CITY'], 'state' => ['STATE'], 'zip' => ['ZIP', 'ZIPCODE'],
        'phone' => ['PHONE', 'PHONE_NUM'], 'party' => ['PARTY', 'PARTY_REG'],
        'congress' => ['CONGRESSIONALDISTRICT', 'CONGRESS'], 'senate' => ['SENATEDISTRICT', 'SENATE'], 'assembly' => ['ASSEMBLYDISTRICT', 'ASSEMBLY'],
        'education' => ['EDUCATIONDISTRICT', 'EDUCATION'], 'regent' => ['REGENTDISTRICT', 'REGENT'], 'precinct' => ['REGISTEREDPRECINCT', 'PRECINCT', 'PRECINCT_NUM'],
        'status' => ['COUNTYSTATUS', 'STATUS'],
    ];

    public function __construct(private DB $db) {}

    public function run(string $file, string $onlyCounty = ''): array
    {
        $db = $this->db;
        if (!is_file($file)) {
            throw new \RuntimeException('File not found');
        }
        $sha = hash_file('sha256', $file);
        $runId = (int) $db->insert('import_runs', ['source' => 'sos-statewide', 'status' => 'fetched', 'source_url' => basename($file), 'sha256' => $sha]);
        try {
            $raw = Archive::store('sos-statewide', $file, $sha);
            $db->exec('UPDATE import_runs SET raw_path = :p WHERE id = :id', [':p' => $raw, ':id' => $runId]);
            $counties = $this->stage($file, $runId, $onlyCounty);
            $db->exec("UPDATE import_runs SET status = 'parsed', rows_total = (SELECT count(*) FROM staging_voters WHERE run_id = :r) WHERE id = :r", [':r' => $runId]);
            $diff = Differ::classify($db, $runId);
            $totals = ['new' => 0, 'changed' => 0, 'unchanged' => 0, 'removed' => 0, 'votes' => 0];
            // Each county present in the file is treated as a full snapshot for that county.
            $totals = Applier::apply($db, $runId, $counties);
            $enrich = Enricher::run($db);
            $summary = ['rows' => (int) $db->val('SELECT rows_total FROM import_runs WHERE id = :r', [':r' => $runId]), 'counties' => $counties, 'errors' => 0] + $totals + ['enrich' => $enrich];
            Reporter::finish($db, $runId, 'loaded', $summary);
            return ['run_id' => $runId, 'status' => 'loaded'] + $summary;
        } catch (\Throwable $e) {
            Log::error("SOS run #$runId failed: " . $e->getMessage());
            Reporter::finish($db, $runId, 'failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /** @return string[] counties present */
    private function stage(string $file, int $runId, string $onlyCounty): array
    {
        $fh = CsvReader::open($file);
        $idx = CsvReader::headerIndex(fgetcsv($fh) ?: []);
        $c = [];
        foreach (self::ALIASES as $k => $al) {
            $c[$k] = CsvReader::col($idx, $al);
        }
        if ($c['id'] === null || $c['county'] === null || $c['last_name'] === null) {
            throw new \RuntimeException('SOS file is missing VoterID, County or LastName columns');
        }
        $columns = ['run_id', 'line_no', 'source_county', 'county_voter_id', 'status', 'precinct', 'congress', 'assembly', 'senate', 'education', 'regent', 'first_name', 'middle_name', 'last_name', 'suffix', 'party', 'birth_year', 'phone',
            'res_street_num', 'res_street_name', 'res_unit', 'res_city', 'res_state', 'res_zip', 'registration_date', 'confidential', 'vote_pairs'];
        $g = fn(array $r, ?int $i): string => $i === null ? '' : trim((string) ($r[$i] ?? ''));
        $counties = [];
        $batch = [];
        $line = 1;
        while (($r = fgetcsv($fh)) !== false) {
            $line++;
            $county = strtolower($g($r, $c['county']));
            if ($county === '' || ($onlyCounty !== '' && $county !== strtolower($onlyCounty))) {
                continue;
            }
            $counties[$county] = true;
            $addr = $g($r, $c['address1']);
            preg_match('/^(\d+\S*)\s+(.*)$/', $addr, $m);
            $bd = CsvReader::parseDate($g($r, $c['birth_date']));
            $party = strtoupper($g($r, $c['party']));
            $party = match (true) {
                str_starts_with($party, 'DEM') => 'DEM', str_starts_with($party, 'REP') => 'REP', str_starts_with($party, 'NON') || $party === 'NP' => 'NP',
                str_starts_with($party, 'LIB') => 'LIB', str_starts_with($party, 'IND') || $party === 'INA' || $party === 'IAP' => 'INA', $party === '' => '', default => 'OTH',
            };
            $status = strtoupper(substr($g($r, $c['status']), 0, 1)) ?: 'A';
            $cvid = $g($r, $c['county_voter_id']) ?: $g($r, $c['id']);
            $batch[] = [$runId, $line, $county, $cvid, $status, $g($r, $c['precinct']), $g($r, $c['congress']), $g($r, $c['assembly']), $g($r, $c['senate']), $g($r, $c['education']), $g($r, $c['regent']),
                $g($r, $c['first_name']), $g($r, $c['middle_name']), $g($r, $c['last_name']), $g($r, $c['suffix']), $party, $bd ? (int) substr($bd, 0, 4) : null, $g($r, $c['phone']),
                $m[1] ?? '', $m[2] ?? $addr, $g($r, $c['address2']), $g($r, $c['city']), $g($r, $c['state']) ?: 'NV', $g($r, $c['zip']), CsvReader::parseDate($g($r, $c['registration_date'])), 'f', ''];
            if (count($batch) >= 2000) {
                $this->copy($columns, $batch);
                $batch = [];
            }
        }
        if ($batch) {
            $this->copy($columns, $batch);
        }
        fclose($fh);
        return array_keys($counties);
    }

    private function copy(array $columns, array $batch): void
    {
        $lines = array_map(fn($r) => implode("\t", array_map([ClarkVoterParser::class, 'copyEscape'], $r)), $batch);
        if (!$this->db->pdo->pgsqlCopyFromArray('staging_voters', $lines, "\t", '\\\\N', implode(',', $columns))) {
            throw new \RuntimeException('COPY into staging failed');
        }
    }
}
