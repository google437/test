<?php
declare(strict_types=1);

namespace App\Services\Ingest;

use App\Core\DB;

/** Post-load derived data: households, elections, vote counts, current-election flags. */
final class Enricher
{
    public static function run(DB $db): array
    {
        $t0 = microtime(true);
        $hh = self::households($db);
        Elections::syncFromHistory($db);
        self::deriveVoteFields($db);
        self::deriveCurrentFlags($db);
        return ['households_assigned' => $hh, 'seconds' => round(microtime(true) - $t0, 1)];
    }

    public static function households(DB $db): int
    {
        $key = 'address_key(v.res_street_num, v.res_direction, v.res_street_name, v.res_address_type, v.res_unit, v.res_city, v.res_zip)';
        $db->exec("INSERT INTO households (county, address_key, address_line, city, zip)
            SELECT DISTINCT ON ($key) v.source_county, $key,
                   btrim(concat_ws(' ', NULLIF(v.res_street_num,''), NULLIF(v.res_direction,''), NULLIF(v.res_street_name,''), NULLIF(v.res_address_type,''), CASE WHEN COALESCE(v.res_unit,'') <> '' THEN '#' || v.res_unit END)),
                   v.res_city, left(v.res_zip, 5)
            FROM voters v WHERE v.household_id IS NULL AND v.status <> 'removed' AND $key <> ''
            ON CONFLICT (address_key) DO NOTHING");
        $n = $db->exec("UPDATE voters v SET household_id = h.id, lat = COALESCE(v.lat, h.lat), lng = COALESCE(v.lng, h.lng)
            FROM households h WHERE v.household_id IS NULL AND v.status <> 'removed' AND h.address_key = $key");
        $db->exec("UPDATE households h SET voter_count = c.n FROM (SELECT household_id, count(*) AS n FROM voters WHERE status <> 'removed' AND household_id IS NOT NULL GROUP BY household_id) c
                   WHERE c.household_id = h.id AND h.voter_count <> c.n");
        $db->exec("UPDATE households h SET voter_count = 0 WHERE voter_count <> 0 AND NOT EXISTS (SELECT 1 FROM voters v WHERE v.household_id = h.id AND v.status <> 'removed')");
        return $n;
    }

    public static function deriveVoteFields(DB $db): void
    {
        $db->pdo->beginTransaction();
        try {
            $db->exec("CREATE TEMP TABLE vh_agg ON COMMIT DROP AS
                WITH rg AS (SELECT code FROM elections WHERE type = 'general' AND election_date <= current_date ORDER BY election_date DESC LIMIT 4),
                     rp AS (SELECT code FROM elections WHERE type = 'primary' AND election_date <= current_date ORDER BY election_date DESC LIMIT 4)
                SELECT vh.voter_id,
                       count(*) FILTER (WHERE vh.election_code IN (SELECT code FROM rg)) AS g,
                       count(*) FILTER (WHERE vh.election_code IN (SELECT code FROM rp)) AS p,
                       (array_agg(vh.election_code ORDER BY e.election_date DESC NULLS LAST))[1] AS last_code
                FROM vote_history vh LEFT JOIN elections e ON e.code = vh.election_code GROUP BY vh.voter_id");
            $db->exec("UPDATE voters v SET vote_count_general = a.g, vote_count_primary = a.p, last_voted_election = a.last_code
                FROM vh_agg a WHERE a.voter_id = v.id AND (v.vote_count_general <> a.g OR v.vote_count_primary <> a.p OR v.last_voted_election IS DISTINCT FROM a.last_code)");
            $db->pdo->commit();
        } catch (\Throwable $e) {
            $db->pdo->rollBack();
            throw $e;
        }
    }

    /** Flags for the current election window (mail requested/returned, early voted, voted). */
    public static function deriveCurrentFlags(DB $db): void
    {
        $code = $db->val('SELECT election_code FROM election_windows WHERE is_current ORDER BY election_date DESC LIMIT 1');
        if (!$code) {
            return;
        }
        $db->exec("UPDATE voters v SET mail_requested_current = COALESCE(b.mail_requested,false), mail_returned_current = COALESCE(b.mail_returned,false),
                early_voted_current = (b.ev_voted_on IS NOT NULL), voted_current = (COALESCE(b.mail_returned,false) OR b.ev_voted_on IS NOT NULL OR vh.vote_type IS NOT NULL)
            FROM voters v2 LEFT JOIN ballot_activity b ON b.voter_id = v2.id AND b.election_code = :c LEFT JOIN vote_history vh ON vh.voter_id = v2.id AND vh.election_code = :c
            WHERE v.id = v2.id AND (v.mail_requested_current <> COALESCE(b.mail_requested,false) OR v.mail_returned_current <> COALESCE(b.mail_returned,false)
               OR v.early_voted_current <> (b.ev_voted_on IS NOT NULL) OR v.voted_current <> (COALESCE(b.mail_returned,false) OR b.ev_voted_on IS NOT NULL OR vh.vote_type IS NOT NULL))", [':c' => $code]);
    }
}
