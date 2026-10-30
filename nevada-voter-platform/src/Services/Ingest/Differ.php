<?php
declare(strict_types=1);

namespace App\Services\Ingest;

use App\Core\DB;

/** Classifies each staged row as new, changed or unchanged against the live voters table. */
final class Differ
{
    public const TRACKED = ['status', 'party', 'first_name', 'middle_name', 'last_name', 'suffix', 'birth_year', 'phone', 'precinct', 'address', 'mail_address'];

    public static function classify(DB $db, int $runId): array
    {
        $db->exec("UPDATE staging_voters s SET diff_class = 'new' WHERE s.run_id = :r
                   AND NOT EXISTS (SELECT 1 FROM voters v WHERE v.source_county = s.source_county AND v.county_voter_id = s.county_voter_id)", [':r' => $runId]);
        $db->exec("UPDATE staging_voters s SET diff_class = CASE WHEN
                     (v.status, COALESCE(v.party,''), COALESCE(v.first_name,''), COALESCE(v.middle_name,''), COALESCE(v.last_name,''), COALESCE(v.suffix,''), v.birth_year, COALESCE(v.phone,''),
                      COALESCE(pr.number,''), address_key(v.res_street_num, v.res_direction, v.res_street_name, v.res_address_type, v.res_unit, v.res_city, v.res_zip), COALESCE(v.mail_address,''), COALESCE(v.registration_date::text,''))
                     IS DISTINCT FROM
                     (s.status, COALESCE(s.party,''), COALESCE(s.first_name,''), COALESCE(s.middle_name,''), COALESCE(s.last_name,''), COALESCE(s.suffix,''), s.birth_year, COALESCE(s.phone,''),
                      COALESCE(s.precinct,''), address_key(s.res_street_num, s.res_direction, s.res_street_name, s.res_address_type, s.res_unit, s.res_city, s.res_zip), COALESCE(s.mail_address,''), COALESCE(s.registration_date::text,''))
                   THEN 'changed' ELSE 'unchanged' END
                   FROM voters v LEFT JOIN precincts pr ON pr.id = v.precinct_id
                   WHERE s.run_id = :r AND s.diff_class IS NULL AND v.source_county = s.source_county AND v.county_voter_id = s.county_voter_id", [':r' => $runId]);
        $c = $db->all('SELECT diff_class, count(*) AS n FROM staging_voters WHERE run_id = :r GROUP BY diff_class', [':r' => $runId]);
        $out = ['new' => 0, 'changed' => 0, 'unchanged' => 0];
        foreach ($c as $r) {
            $out[$r['diff_class']] = (int) $r['n'];
        }
        return $out;
    }

    /** Voters in the live table that are absent from a full snapshot. */
    public static function countRemoved(DB $db, int $runId, string $county): int
    {
        return (int) $db->val("SELECT count(*) FROM voters v WHERE v.source_county = :c AND v.status <> 'removed'
            AND NOT EXISTS (SELECT 1 FROM staging_voters s WHERE s.run_id = :r AND s.source_county = v.source_county AND s.county_voter_id = v.county_voter_id)", [':c' => $county, ':r' => $runId]);
    }
}
