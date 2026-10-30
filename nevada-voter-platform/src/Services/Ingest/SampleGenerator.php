<?php
declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * Generates a synthetic Clark County voter file in the documented layout, so the whole pipeline can
 * run in development and in tests without the real file. Deterministic for a given seed.
 * Pass $mutate to produce a "next week" file: some new voters, some changes, some drops.
 */
final class SampleGenerator
{
    private const STREETS = ['DESERT INN', 'FLAMINGO', 'TROPICANA', 'SAHARA', 'CHARLESTON', 'RAINBOW', 'DECATUR', 'JONES', 'EASTERN', 'PECOS', 'MARYLAND', 'SUNSET', 'WARM SPRINGS', 'RUSSELL', 'SPRING MOUNTAIN', 'ALTA', 'OAKEY', 'BONANZA', 'LAKE MEAD', 'CRAIG'];
    private const TYPES = ['RD', 'AVE', 'BLVD', 'DR', 'ST', 'LN', 'WAY', 'CT'];
    private const FIRST = ['MARIA', 'JAMES', 'LINDA', 'ROBERT', 'ANA', 'MICHAEL', 'SUSAN', 'DAVID', 'CARLOS', 'JENNIFER', 'JOSE', 'KAREN', 'DANIEL', 'ROSA', 'KEVIN', 'ANGELA', 'BRIAN', 'LAURA', 'MARK', 'ELENA', 'THOMAS', 'PATRICIA', 'LUIS', 'EMILY', 'TYLER', 'GRACE', 'HENRY', 'NICOLE', 'OMAR', 'PRIYA'];
    private const LAST = ['GARCIA', 'SMITH', 'JOHNSON', 'MARTINEZ', 'WILLIAMS', 'BROWN', 'LOPEZ', 'JONES', 'HERNANDEZ', 'MILLER', 'DAVIS', 'GONZALEZ', 'WILSON', 'ANDERSON', 'NGUYEN', 'TAYLOR', 'MOORE', 'LEE', 'PEREZ', 'THOMPSON', 'KIM', 'WHITE', 'HARRIS', 'SANCHEZ', 'CLARK', 'RAMIREZ', 'LEWIS', 'ROBINSON', 'WALKER', 'YOUNG'];
    private const CITIES = ['LAS VEGAS' => ['89101', '89102', '89104', '89106', '89107', '89108', '89110', '89117', '89119', '89121', '89123', '89128', '89129', '89130', '89134', '89135', '89139', '89141', '89143', '89144', '89145', '89146', '89147', '89148', '89149'], 'HENDERSON' => ['89002', '89011', '89012', '89014', '89015', '89052', '89074'], 'NORTH LAS VEGAS' => ['89030', '89031', '89032', '89081', '89084', '89085', '89086']];

    public static function header(): array
    {
        $h = ['STATUS', 'PRECINCT_NUM', 'CONGRESS', 'ASSEMBLY', 'SENATE', 'COMMISSION', 'EDUCATION', 'REGENT', 'SCHOOL', 'CITY', 'WARD', 'TOWNSHIP', 'FIRST_NAME', 'MIDDLE_NAME', 'LAST_NAME', 'NAME_SUFFIX', 'SEX', 'PARTY_REG', 'BIRTH_YEAR', 'PHONE_NUM',
            'RES_STREET_NUM', 'RES_DIRECTION', 'RES_STREET_NAME', 'RES_ADDRESS_TYPE', 'RES_UNIT', 'RES_CITY', 'RES_STATE', 'RES_ZIP_CODE', 'MAIL_ADDRESS', 'MAIL_CITY', 'MAIL_STATE', 'MAIL_ZIP_CODE',
            'ACTIVITY_DATE', 'REGISTRATION_DATE', 'REGISTRATION_NUM', 'LANGUAGE_PREF', 'POLLING_CODE', 'CONFIDENTIAL_FLAG', 'ID_NOT_REQD', 'AFFIDAVIT'];
        for ($i = 1; $i <= 20; $i++) {
            $h[] = "ELECTION$i";
            $h[] = "VOTE_TYPE$i";
        }
        return $h;
    }

    /** Writes a zip containing voters.csv; returns row count. */
    public static function write(string $zipPath, int $rows = 5000, int $seed = 42, bool $mutate = false): int
    {
        mt_srand($seed);
        $elections = ['26P', '24G', '24P', '22G', '22P', '20G', '20P', '18G', '18P', '16G'];
        $anchor = strtotime('2026-10-01');
        $tmpCsv = tempnam(dirname($zipPath), 'smp');
        $fh = fopen($tmpCsv, 'w');
        fputcsv($fh, self::header());
        $n = 0;
        $cities = array_keys(self::CITIES);
        $lastKey = null;
        $hhLeft = 0;
        $hh = [];
        for ($i = 1; $i <= $rows + ($mutate ? intdiv($rows, 50) : 0); $i++) {
            $isNew = $i > $rows;                              // mutate: ~2% brand new voters
            $drop = $mutate && !$isNew && $i % 97 === 0;      // mutate: ~1% dropped from the file (row is still generated so the random sequence stays aligned)
            if ($hhLeft <= 0) {                               // new household every 1-3 voters
                $hhLeft = mt_rand(1, 3);
                $city = $cities[mt_rand(0, 2)];
                $zips = self::CITIES[$city];
                $precinctNum = mt_rand(1, 60);
                $hh = ['num' => (string) mt_rand(100, 9999), 'dir' => ['', 'N', 'S', 'E', 'W'][mt_rand(0, 4)], 'street' => self::STREETS[mt_rand(0, 19)], 'type' => self::TYPES[mt_rand(0, 7)],
                    'unit' => mt_rand(0, 4) === 0 ? (string) mt_rand(1, 300) : '', 'city' => $city, 'zip' => $zips[mt_rand(0, count($zips) - 1)], 'precinct' => sprintf('%04d', $precinctNum),
                    'congress' => (string) (1 + $precinctNum % 3 + ($precinctNum % 3 === 1 ? 2 : 0)), 'assembly' => (string) (1 + $precinctNum % 6), 'senate' => (string) (1 + $precinctNum % 4),
                    'commission' => chr(65 + $precinctNum % 7), 'education' => '3', 'regent' => (string) (1 + $precinctNum % 5), 'school' => chr(65 + $precinctNum % 7),
                    'phonebase' => mt_rand(0, 2) === 0 ? '' : '702' . mt_rand(2000000, 9999999)];
            }
            $hhLeft--;
            $party = ['DEM', 'DEM', 'REP', 'REP', 'NP', 'NP', 'NP', 'LIB', 'INA', 'OTH'][mt_rand(0, 9)];
            $by = mt_rand(1935, 2008);
            $status = mt_rand(0, 9) === 0 ? 'I' : 'A';
            $conf = mt_rand(0, 199) === 0;
            $hist = [];
            foreach ($elections as $e) {
                $yr = 2000 + (int) substr($e, 0, 2);
                if ($yr - $by < 18) {
                    continue;
                }
                $prob = $e[2] === 'G' ? 0.65 : 0.3;
                if ($by < 1960) {
                    $prob += 0.2;
                }
                if (mt_rand() / mt_getrandmax() < $prob) {
                    $hist[] = [$e, ['P', 'M', 'M', 'E', 'E', 'F', 'Z'][mt_rand(0, 6)]];
                }
            }
            $first = self::FIRST[mt_rand(0, 29)];
            $last = $hhLeft > 0 || mt_rand(0, 1) ? ($lastKey ?? self::LAST[mt_rand(0, 29)]) : self::LAST[mt_rand(0, 29)];
            $lastKey = $last;
            if ($mutate && !$isNew) {
                if ($i % 23 === 0) {
                    $party = $party === 'NP' ? 'DEM' : 'NP';     // ~4% party changes
                }
                if ($i % 41 === 0) {
                    $status = $status === 'A' ? 'I' : 'A';        // ~2% status changes
                }
                if ($i % 71 === 0) {
                    $hh['num'] = (string) ((int) $hh['num'] + 2);  // ~1% moved
                }
                if ($i % 5 === 0 && !in_array('26P', array_column($hist, 0), true) && $by <= 2008) {
                    $hist[] = ['26P', 'M'];                       // new vote history
                }
            }
            $row = [$status, $hh['precinct'], $hh['congress'], $hh['assembly'], $hh['senate'], $hh['commission'], $hh['education'], $hh['regent'], $hh['school'], $hh['city'] === 'LAS VEGAS' ? 'LV' : ($hh['city'] === 'HENDERSON' ? 'HEN' : 'NLV'), (string) (1 + mt_rand(0, 5)), 'LV',
                $first, chr(65 + mt_rand(0, 25)), $last, mt_rand(0, 30) === 0 ? 'JR' : '', ['M', 'F', 'U'][mt_rand(0, 2)], $party, (string) $by, $conf ? '' : $hh['phonebase'],
                $conf ? '' : $hh['num'], $conf ? '' : $hh['dir'], $conf ? '' : $hh['street'], $conf ? '' : $hh['type'], $conf ? '' : $hh['unit'], $conf ? '' : $hh['city'], $conf ? '' : 'NV', $conf ? '' : $hh['zip'],
                '', '', '', '', date('m/d/Y', mt_rand(strtotime('2024-01-01'), $anchor)), date('m/d/Y', mt_rand(strtotime(($by + 18) . '-01-01'), min($anchor, strtotime(($by + 18) . '-01-01') + 86400 * 365 * 20))), (string) (1000000 + $i), ['', 'ENGLISH', 'SPANISH'][mt_rand(0, 2)], '', $conf ? 'Y' : '', mt_rand(0, 9) === 0 ? 'Y' : '', ''];
            for ($k = 0; $k < 20; $k++) {
                $row[] = $hist[$k][0] ?? '';
                $row[] = $hist[$k][1] ?? '';
            }
            if ($drop) {
                continue;
            }
            fputcsv($fh, $row);
            $n++;
        }
        fclose($fh);
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create zip');
        }
        $zip->addFile($tmpCsv, 'COUNTY_all_TV.csv');
        $zip->close();
        unlink($tmpCsv);
        return $n;
    }

    /** Companion mail ballot and early vote files for the sample voters (ids are 1000001..). */
    public static function writeBallotFiles(string $mailCsv, string $evCsv, int $rows, string $code = '26G', int $seed = 7): void
    {
        mt_srand($seed);
        $m = fopen($mailCsv, 'w');
        fputcsv($m, ['IDNUMBER', 'VOTER_NAME', 'STREETNUMBER', 'STREETPREDIR', 'STREETNAME', 'STREETTYPE', 'UNIT', 'CITY', 'STATE', 'ZIP', 'PRECINCT', 'VOTER_REG_PARTY', 'BALLOT_PARTY', 'ELECTION_CODE', 'REQUEST_SOURCE', 'REQUEST_DATE', 'BALLOT_MAIL_DATE', 'BALLOT_RECEIVE_DATE', 'RETURN_CODE']);
        $e = fopen($evCsv, 'w');
        fputcsv($e, ['IDNUMBER', 'NAME', 'PRECINCT', 'PARTY', 'PARTY ABBR', 'CONGRESS', 'ASSEMBLY', 'SENATE', 'COMMISSION', 'EDUCATION', 'REGENT', 'SCHOOL', 'WARD', 'TOWNSHIP', 'STATUS', 'EV SITE', 'ELECTION CODE', 'ACTIVITY DATE']);
        for ($i = 1; $i <= $rows; $i++) {
            $id = (string) (1000000 + $i);
            $r = mt_rand(0, 9);
            if ($r < 4) {
                $returned = mt_rand(0, 1) ? date('m/d/Y', time() - mt_rand(0, 10) * 86400) : '';
                fputcsv($m, [$id, 'SAMPLE VOTER', '', '', '', '', '', '', 'NV', '', '', '', 'NA', $code, 'NA', 'NA', date('m/d/Y', time() - 20 * 86400), $returned, $returned ? 'AC' : '']);
            } elseif ($r === 4) {
                fputcsv($e, [$id, 'SAMPLE VOTER', '', 'NA', 'NA', '', '', '', '', '', '', '', '', '', 'A', 'GALLERIA MALL', $code . 'E', date('m/d/Y H:i', time() - mt_rand(0, 5) * 86400)]);
            }
        }
        fclose($m);
        fclose($e);
    }
}
