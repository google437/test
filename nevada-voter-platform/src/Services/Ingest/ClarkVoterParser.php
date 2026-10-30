<?php
declare(strict_types=1);

namespace App\Services\Ingest;

use App\Core\DB;

/**
 * Streams a Clark County voter list CSV into staging_voters using COPY. Rows with a missing id or
 * unparseable birth year go to import_errors; unknown code values are counted for validation.
 */
final class ClarkVoterParser
{
    private array $layout;

    public function __construct(?array $layout = null)
    {
        $this->layout = $layout ?? require APP_ROOT . '/config/clark_layout.php';
    }

    /** @return array{rows:int, errors:int, unknown_codes:int, columns_missing:array} */
    public function parse(DB $db, string $path, int $runId, string $sourceCounty = 'clark'): array
    {
        $fh = CsvReader::open($path);
        $header = fgetcsv($fh);
        if ($header === false) {
            throw new \RuntimeException('Empty file');
        }
        $idx = CsvReader::headerIndex($header);
        $positional = false;
        if (CsvReader::col($idx, ['STATUS']) === null || CsvReader::col($idx, ['LAST_NAME']) === null) {
            // No header row: assume the documented positional layout (40 fields + 20 election/vote pairs).
            if (count($header) === 80) {
                $positional = true;
                $idx = $this->positionalIndex();
                rewind($fh);
            } else {
                throw new \RuntimeException('Unrecognized header row (' . count($header) . ' columns)');
            }
        }
        $missing = [];
        foreach ($this->layout['required'] as $req) {
            if (CsvReader::col($idx, [$req]) === null) {
                $missing[] = $req;
            }
        }
        $idCol = CsvReader::col($idx, $this->layout['id_fields']);
        if ($idCol === null) {
            $missing[] = 'IDNUMBER/REGISTRATION_NUM';
        }
        if ($missing) {
            fclose($fh);
            return ['rows' => 0, 'errors' => 0, 'unknown_codes' => 0, 'columns_missing' => $missing];
        }
        $map = [];
        foreach ($this->layout['fields'] as $field => $aliases) {
            $map[$field] = CsvReader::col($idx, $aliases);
        }
        $slots = [];
        for ($i = 1; $i <= $this->layout['vote_history_slots']; $i++) {
            $e = CsvReader::col($idx, ["ELECTION$i", "ELECTION_$i", "ELECTION $i"]);
            $v = CsvReader::col($idx, ["VOTE_TYPE$i", "VOTE_TYPE_$i", "VOTE TYPE $i", "VOTETYPE$i"]);
            if ($e !== null && $v !== null) {
                $slots[] = [$e, $v];
            }
        }
        $codes = $this->layout['codes'];
        $columns = ['run_id', 'line_no', 'source_county', 'county_voter_id', 'status', 'precinct', 'congress', 'assembly', 'senate', 'commission', 'education', 'regent', 'school', 'city', 'ward', 'township',
            'first_name', 'middle_name', 'last_name', 'suffix', 'sex', 'party', 'birth_year', 'phone', 'res_street_num', 'res_direction', 'res_street_name', 'res_address_type', 'res_unit',
            'res_city', 'res_state', 'res_zip', 'mail_address', 'mail_city', 'mail_state', 'mail_zip', 'activity_date', 'registration_date', 'language_pref', 'confidential', 'vote_pairs'];
        $errSt = $db->pdo->prepare('INSERT INTO import_errors (run_id, line_no, reason, raw) VALUES (:r, :l, :why, :raw)');
        $rows = 0;
        $errors = 0;
        $unknown = 0;
        $batch = [];
        $line = $positional ? 0 : 1;
        $get = fn(array $row, ?int $c): string => $c === null ? '' : trim((string) ($row[$c] ?? ''));
        while (($row = fgetcsv($fh)) !== false) {
            $line++;
            if (count($row) === 1 && ($row[0] === null || trim((string) $row[0]) === '')) {
                continue;
            }
            $id = $get($row, $idCol);
            $by = $get($row, $map['birth_year']);
            $status = strtoupper($get($row, $map['status']));
            $party = strtoupper($get($row, $map['party']));
            if ($id === '') {
                $errors++;
                if ($errors <= 1000) {
                    $errSt->execute([':r' => $runId, ':l' => $line, ':why' => 'missing voter id', ':raw' => json_encode(['status' => $status, 'precinct' => $get($row, $map['precinct'])])]);
                }
                continue;
            }
            $birthYear = ($by !== '' && ctype_digit($by) && (int) $by > 1890) ? (int) $by : null;
            if ($status !== '' && !in_array($status, $codes['status'], true)) {
                $unknown++;
                $status = $status === '' ? 'A' : substr($status, 0, 10);
            }
            if ($party !== '' && !in_array($party, $codes['party'], true)) {
                $unknown++;
            }
            $pairs = [];
            foreach ($slots as [$ec, $vc]) {
                $e = Elections::normalize($get($row, $ec));
                $v = strtoupper($get($row, $vc));
                if ($e === '') {
                    continue;
                }
                if (!Elections::isValid($e)) {
                    $unknown++;
                    continue;
                }
                if ($v !== '' && !in_array($v, $codes['vote_type'], true)) {
                    $unknown++;
                    $v = '';
                }
                $pairs[] = $e . ':' . $v;
            }
            $conf = strtoupper($get($row, $map['confidential'])) === 'Y';
            $batch[] = [
                $runId, $line, $sourceCounty, $id, $status === '' ? 'A' : $status, $get($row, $map['precinct']),
                $get($row, $map['congress']), $get($row, $map['assembly']), $get($row, $map['senate']), $get($row, $map['commission']), $get($row, $map['education']),
                $get($row, $map['regent']), $get($row, $map['school']), $get($row, $map['city']), $get($row, $map['ward']), $get($row, $map['township']),
                $get($row, $map['first_name']), $get($row, $map['middle_name']), $get($row, $map['last_name']), $get($row, $map['suffix']), strtoupper(substr($get($row, $map['sex']), 0, 1)),
                $party, $birthYear, $get($row, $map['phone']),
                $get($row, $map['res_street_num']), $get($row, $map['res_direction']), $get($row, $map['res_street_name']), $get($row, $map['res_address_type']), $get($row, $map['res_unit']),
                $get($row, $map['res_city']), $get($row, $map['res_state']), $get($row, $map['res_zip']),
                $get($row, $map['mail_address']), $get($row, $map['mail_city']), $get($row, $map['mail_state']), $get($row, $map['mail_zip']),
                CsvReader::parseDate($get($row, $map['activity_date'])), CsvReader::parseDate($get($row, $map['registration_date'])), $get($row, $map['language_pref']),
                $conf ? 't' : 'f', implode('|', $pairs),
            ];
            $rows++;
            if (count($batch) >= 2000) {
                $this->copy($db, $columns, $batch);
                $batch = [];
            }
        }
        if ($batch) {
            $this->copy($db, $columns, $batch);
        }
        fclose($fh);
        // Duplicate ids inside one file would break the diff: keep the last occurrence.
        $db->exec('DELETE FROM staging_voters s USING staging_voters s2 WHERE s.run_id = :r AND s2.run_id = :r AND s.county_voter_id = s2.county_voter_id AND s.line_no < s2.line_no', [':r' => $runId]);
        return ['rows' => $rows, 'errors' => $errors, 'unknown_codes' => $unknown, 'columns_missing' => []];
    }

    private function positionalIndex(): array
    {
        $names = ['STATUS', 'PRECINCT_NUM', 'CONGRESS', 'ASSEMBLY', 'SENATE', 'COMMISSION', 'EDUCATION', 'REGENT', 'SCHOOL', 'CITY', 'WARD', 'TOWNSHIP', 'FIRST_NAME', 'MIDDLE_NAME', 'LAST_NAME', 'NAME_SUFFIX', 'SEX', 'PARTY_REG', 'BIRTH_YEAR', 'PHONE_NUM',
            'RES_STREET_NUM', 'RES_DIRECTION', 'RES_STREET_NAME', 'RES_ADDRESS_TYPE', 'RES_UNIT', 'RES_CITY', 'RES_STATE', 'RES_ZIP_CODE', 'MAIL_ADDRESS', 'MAIL_CITY', 'MAIL_STATE', 'MAIL_ZIP_CODE',
            'ACTIVITY_DATE', 'REGISTRATION_DATE', 'REGISTRATION_NUM', 'LANGUAGE_PREF', 'POLLING_CODE', 'CONFIDENTIAL_FLAG', 'ID_NOT_REQD', 'AFFIDAVIT'];
        for ($i = 1; $i <= 20; $i++) {
            $names[] = "ELECTION$i";
            $names[] = "VOTE_TYPE$i";
        }
        return array_flip($names);
    }

    private function copy(DB $db, array $columns, array $batch): void
    {
        $lines = [];
        foreach ($batch as $r) {
            $lines[] = implode("\t", array_map([self::class, 'copyEscape'], $r));
        }
        if (!$db->pdo->pgsqlCopyFromArray('staging_voters', $lines, "\t", '\\\\N', implode(',', $columns))) {
            throw new \RuntimeException('COPY into staging failed');
        }
    }

    public static function copyEscape(mixed $v): string
    {
        if ($v === null) {
            return '\\N';
        }
        $s = (string) $v;
        return str_replace(["\\", "\t", "\n", "\r"], ['\\\\', '\\t', '\\n', '\\r'], $s);
    }
}
