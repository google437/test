<?php
declare(strict_types=1);

namespace App\Services\Ingest;

use App\Core\DB;

/**
 * Applies a classified staging run to the live tables in one transaction: upsert voters, append
 * voter_changes, add vote history, mark dropped voters as removed (never delete).
 * Campaign data (contact_attempts, tags, notes) hangs off voters.id and is never touched here.
 */
final class Applier
{
    /** @param string[] $snapshotCounties counties for which the file is a full snapshot (absent voters get marked removed) */
    public static function apply(DB $db, int $runId, array $snapshotCounties): array
    {
        return $db->tx(function (DB $db) use ($runId, $snapshotCounties) {
            $p = [':r' => $runId];
            // 1. Geography lookups from the file itself.
            $db->exec("INSERT INTO precincts (county, number) SELECT DISTINCT s.source_county, s.precinct FROM staging_voters s WHERE s.run_id = :r AND COALESCE(s.precinct,'') <> '' ON CONFLICT DO NOTHING", $p);
            $layout = require APP_ROOT . '/config/clark_layout.php';
            foreach ($layout['district_columns'] as $col) {
                $db->exec("INSERT INTO districts (county, type, name) SELECT DISTINCT s.source_county, '$col', s.$col FROM staging_voters s WHERE s.run_id = :r AND COALESCE(s.$col,'') <> '' ON CONFLICT DO NOTHING", $p);
                $db->exec("INSERT INTO precinct_districts (precinct_id, district_id)
                           SELECT DISTINCT pr.id, d.id FROM staging_voters s
                           JOIN precincts pr ON pr.county = s.source_county AND pr.number = s.precinct
                           JOIN districts d ON d.county = s.source_county AND d.type = '$col' AND d.name = s.$col
                           WHERE s.run_id = :r AND COALESCE(s.$col,'') <> '' ON CONFLICT DO NOTHING", $p);
            }

            // 2. New voters.
            $new = $db->exec("INSERT INTO voters (source_county, county_voter_id, first_name, middle_name, last_name, suffix, sex, birth_year, party, status, registration_date, activity_date, phone, confidential, language_pref, precinct_id,
                    res_street_num, res_direction, res_street_name, res_address_type, res_unit, res_city, res_state, res_zip, mail_address, mail_city, mail_state, mail_zip, first_seen_run, last_seen_run)
                SELECT s.source_county, s.county_voter_id, s.first_name, s.middle_name, s.last_name, s.suffix, NULLIF(s.sex,''), s.birth_year, s.party, s.status, s.registration_date, s.activity_date, s.phone, COALESCE(s.confidential,false), s.language_pref, pr.id,
                    s.res_street_num, s.res_direction, s.res_street_name, s.res_address_type, s.res_unit, s.res_city, s.res_state, s.res_zip, s.mail_address, s.mail_city, s.mail_state, s.mail_zip, :r, :r
                FROM staging_voters s LEFT JOIN precincts pr ON pr.county = s.source_county AND pr.number = s.precinct
                WHERE s.run_id = :r AND s.diff_class = 'new'", $p);
            $db->exec("INSERT INTO voter_addresses (voter_id, address_line, city, state, zip, mail_address, mail_city, mail_state, mail_zip)
                SELECT v.id, btrim(concat_ws(' ', NULLIF(v.res_street_num,''), NULLIF(v.res_direction,''), NULLIF(v.res_street_name,''), NULLIF(v.res_address_type,''), NULLIF(v.res_unit,''))), v.res_city, v.res_state, v.res_zip, v.mail_address, v.mail_city, v.mail_state, v.mail_zip
                FROM voters v WHERE v.first_seen_run = :r", $p);

            // 3. Changed voters: record field-level changes first, then update.
            $db->exec("INSERT INTO voter_changes (voter_id, run_id, field, old_value, new_value)
                SELECT v.id, :r, f.field, f.old_value, f.new_value
                FROM staging_voters s JOIN voters v ON v.source_county = s.source_county AND v.county_voter_id = s.county_voter_id
                LEFT JOIN precincts pr ON pr.id = v.precinct_id
                CROSS JOIN LATERAL (VALUES
                    ('status', v.status, s.status), ('party', v.party, s.party), ('first_name', v.first_name, s.first_name), ('middle_name', v.middle_name, s.middle_name),
                    ('last_name', v.last_name, s.last_name), ('suffix', v.suffix, s.suffix), ('birth_year', v.birth_year::text, s.birth_year::text), ('phone', v.phone, s.phone),
                    ('precinct', pr.number, s.precinct), ('registration_date', v.registration_date::text, s.registration_date::text),
                    ('address', address_key(v.res_street_num, v.res_direction, v.res_street_name, v.res_address_type, v.res_unit, v.res_city, v.res_zip),
                                address_key(s.res_street_num, s.res_direction, s.res_street_name, s.res_address_type, s.res_unit, s.res_city, s.res_zip)),
                    ('mail_address', v.mail_address, s.mail_address)
                ) AS f(field, old_value, new_value)
                WHERE s.run_id = :r AND s.diff_class = 'changed' AND COALESCE(f.old_value,'') IS DISTINCT FROM COALESCE(f.new_value,'')", $p);
            // Address history: close the old row and open a new one when the address changed.
            $db->exec("UPDATE voter_addresses va SET valid_to = now() FROM voter_changes c WHERE c.run_id = :r AND c.field = 'address' AND va.voter_id = c.voter_id AND va.valid_to IS NULL", $p);
            $changed = $db->exec("UPDATE voters v SET
                    first_name = s.first_name, middle_name = s.middle_name, last_name = s.last_name, suffix = s.suffix, sex = NULLIF(s.sex,''), birth_year = s.birth_year, party = s.party, status = s.status,
                    registration_date = s.registration_date, activity_date = s.activity_date, phone = s.phone, confidential = COALESCE(s.confidential,false), language_pref = s.language_pref, precinct_id = pr.id,
                    res_street_num = s.res_street_num, res_direction = s.res_direction, res_street_name = s.res_street_name, res_address_type = s.res_address_type, res_unit = s.res_unit,
                    res_city = s.res_city, res_state = s.res_state, res_zip = s.res_zip, mail_address = s.mail_address, mail_city = s.mail_city, mail_state = s.mail_state, mail_zip = s.mail_zip,
                    household_id = CASE WHEN address_key(v.res_street_num, v.res_direction, v.res_street_name, v.res_address_type, v.res_unit, v.res_city, v.res_zip)
                                        IS DISTINCT FROM address_key(s.res_street_num, s.res_direction, s.res_street_name, s.res_address_type, s.res_unit, s.res_city, s.res_zip) THEN NULL ELSE v.household_id END,
                    lat = CASE WHEN address_key(v.res_street_num, v.res_direction, v.res_street_name, v.res_address_type, v.res_unit, v.res_city, v.res_zip)
                               IS DISTINCT FROM address_key(s.res_street_num, s.res_direction, s.res_street_name, s.res_address_type, s.res_unit, s.res_city, s.res_zip) THEN NULL ELSE v.lat END,
                    lng = CASE WHEN address_key(v.res_street_num, v.res_direction, v.res_street_name, v.res_address_type, v.res_unit, v.res_city, v.res_zip)
                               IS DISTINCT FROM address_key(s.res_street_num, s.res_direction, s.res_street_name, s.res_address_type, s.res_unit, s.res_city, s.res_zip) THEN NULL ELSE v.lng END,
                    removed_at = NULL, last_seen_run = :r, updated_at = now()
                FROM staging_voters s LEFT JOIN precincts pr ON pr.county = s.source_county AND pr.number = s.precinct
                WHERE s.run_id = :r AND s.diff_class = 'changed' AND v.source_county = s.source_county AND v.county_voter_id = s.county_voter_id", $p);
            $db->exec("INSERT INTO voter_addresses (voter_id, address_line, city, state, zip, mail_address, mail_city, mail_state, mail_zip)
                SELECT v.id, btrim(concat_ws(' ', NULLIF(v.res_street_num,''), NULLIF(v.res_direction,''), NULLIF(v.res_street_name,''), NULLIF(v.res_address_type,''), NULLIF(v.res_unit,''))), v.res_city, v.res_state, v.res_zip, v.mail_address, v.mail_city, v.mail_state, v.mail_zip
                FROM voters v WHERE v.id IN (SELECT voter_id FROM voter_changes WHERE run_id = :r AND field = 'address')", $p);

            // 4. Unchanged voters still count as seen.
            $db->exec("UPDATE voters v SET last_seen_run = :r FROM staging_voters s WHERE s.run_id = :r AND s.diff_class = 'unchanged' AND v.source_county = s.source_county AND v.county_voter_id = s.county_voter_id", $p);

            // 5. Dropped from a full snapshot: mark removed, keep the row and all campaign data.
            $removed = 0;
            if ($snapshotCounties) {
                $cp = $p;
                $in = DB::inList($snapshotCounties, $cp, 'sc');
                $db->exec("INSERT INTO voter_changes (voter_id, run_id, field, old_value, new_value)
                    SELECT v.id, :r, 'status', v.status, 'removed' FROM voters v WHERE v.source_county IN $in AND v.status <> 'removed'
                    AND NOT EXISTS (SELECT 1 FROM staging_voters s WHERE s.run_id = :r AND s.source_county = v.source_county AND s.county_voter_id = v.county_voter_id)", $cp);
                $removed = $db->exec("UPDATE voters v SET status = 'removed', removed_at = now(), updated_at = now() WHERE v.source_county IN $in AND v.status <> 'removed'
                    AND NOT EXISTS (SELECT 1 FROM staging_voters s WHERE s.run_id = :r AND s.source_county = v.source_county AND s.county_voter_id = v.county_voter_id)", $cp);
            }

            // 6. Vote history (insert new rows, update a changed vote type).
            $votes = $db->exec("INSERT INTO vote_history (voter_id, election_code, vote_type)
                SELECT DISTINCT ON (v.id, split_part(pair, ':', 1)) v.id, split_part(pair, ':', 1), NULLIF(split_part(pair, ':', 2), '')
                FROM staging_voters s JOIN voters v ON v.source_county = s.source_county AND v.county_voter_id = s.county_voter_id
                CROSS JOIN LATERAL unnest(string_to_array(s.vote_pairs, '|')) AS pair
                WHERE s.run_id = :r AND pair <> ''
                ON CONFLICT (voter_id, election_code) DO UPDATE SET vote_type = EXCLUDED.vote_type WHERE vote_history.vote_type IS DISTINCT FROM EXCLUDED.vote_type", $p);

            $unchanged = (int) $db->val("SELECT count(*) FROM staging_voters WHERE run_id = :r AND diff_class = 'unchanged'", $p);
            $db->exec('DELETE FROM staging_voters WHERE run_id = :r', $p);
            return ['new' => $new, 'changed' => $changed, 'unchanged' => $unchanged, 'removed' => $removed, 'votes' => $votes];
        });
    }
}
