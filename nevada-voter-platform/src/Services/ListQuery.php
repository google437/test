<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Turns a saved filter array into SQL against the voter layer. Every query already runs under
 * row-level security, so results are limited to the org's licensed districts regardless of filters.
 */
final class ListQuery
{
    public const FILTER_KEYS = ['district_ids', 'precinct_ids', 'parties', 'statuses', 'sex', 'age_min', 'age_max', 'reg_from', 'reg_to',
        'gen_min', 'pri_min', 'voted_election', 'vote_method', 'mail_requested', 'mail_returned', 'early_voted', 'hh_min', 'hh_max',
        'has_phone', 'tags', 'survey_q', 'survey_answers', 'exclude_results', 'not_contacted_days', 'chase', 'name_search'];

    public static function sanitize(array $in): array
    {
        $f = [];
        foreach (['district_ids', 'precinct_ids', 'parties', 'statuses', 'tags', 'survey_answers', 'exclude_results'] as $k) {
            $v = $in[$k] ?? [];
            if (is_string($v)) {
                $v = array_filter(array_map('trim', explode(',', $v)));
            }
            if (is_array($v) && $v) {
                $f[$k] = array_values(array_map('strval', $v));
            }
        }
        foreach (['age_min', 'age_max', 'gen_min', 'pri_min', 'hh_min', 'hh_max', 'not_contacted_days', 'survey_q'] as $k) {
            if (isset($in[$k]) && $in[$k] !== '' && $in[$k] !== null) {
                $f[$k] = (int) $in[$k];
            }
        }
        foreach (['reg_from', 'reg_to', 'voted_election', 'vote_method', 'sex', 'name_search'] as $k) {
            if (!empty($in[$k]) && is_string($in[$k])) {
                $f[$k] = trim($in[$k]);
            }
        }
        foreach (['mail_requested', 'mail_returned', 'early_voted', 'has_phone', 'chase'] as $k) {
            if (isset($in[$k]) && $in[$k] !== '' && $in[$k] !== null) {
                $f[$k] = in_array($in[$k], [true, 1, '1', 'true', 'yes', 'on'], true) ? true : (in_array($in[$k], ['0', 'false', 'no', false, 0], true) ? false : null);
                if ($f[$k] === null) {
                    unset($f[$k]);
                }
            }
        }
        return $f;
    }

    /** @return array{0:string,1:array} where clause (without WHERE) and params */
    public static function where(array $f, bool $chase = false): array
    {
        $w = ['1=1'];
        $p = [];
        $statuses = $f['statuses'] ?? [];
        if ($statuses) {
            $w[] = 'v.status IN ' . DB::inList($statuses, $p, 'st');
        } else {
            $w[] = "v.status <> 'removed'";
        }
        if (!empty($f['district_ids'])) {
            $w[] = 'v.precinct_id IN (SELECT precinct_id FROM precinct_districts WHERE district_id IN ' . DB::inList(array_map('intval', $f['district_ids']), $p, 'd') . ')';
        }
        if (!empty($f['precinct_ids'])) {
            $w[] = 'v.precinct_id IN ' . DB::inList(array_map('intval', $f['precinct_ids']), $p, 'pc');
        }
        if (!empty($f['parties'])) {
            $w[] = 'v.party IN ' . DB::inList($f['parties'], $p, 'pa');
        }
        if (!empty($f['sex'])) {
            $w[] = 'v.sex = :sex';
            $p[':sex'] = $f['sex'];
        }
        $year = (int) date('Y');
        if (isset($f['age_min'])) {
            $w[] = 'v.birth_year <= :by_max';
            $p[':by_max'] = $year - $f['age_min'];
        }
        if (isset($f['age_max'])) {
            $w[] = 'v.birth_year >= :by_min';
            $p[':by_min'] = $year - $f['age_max'];
        }
        if (!empty($f['reg_from'])) {
            $w[] = 'v.registration_date >= :reg_from';
            $p[':reg_from'] = $f['reg_from'];
        }
        if (!empty($f['reg_to'])) {
            $w[] = 'v.registration_date <= :reg_to';
            $p[':reg_to'] = $f['reg_to'];
        }
        if (isset($f['gen_min'])) {
            $w[] = 'v.vote_count_general >= :gen_min';
            $p[':gen_min'] = $f['gen_min'];
        }
        if (isset($f['pri_min'])) {
            $w[] = 'v.vote_count_primary >= :pri_min';
            $p[':pri_min'] = $f['pri_min'];
        }
        if (!empty($f['voted_election'])) {
            $sub = 'EXISTS (SELECT 1 FROM vote_history vh WHERE vh.voter_id = v.id AND vh.election_code = :ve';
            $p[':ve'] = $f['voted_election'];
            if (!empty($f['vote_method'])) {
                $sub .= ' AND vh.vote_type = :vm';
                $p[':vm'] = $f['vote_method'];
            }
            $w[] = $sub . ')';
        }
        foreach (['mail_requested' => 'mail_requested_current', 'mail_returned' => 'mail_returned_current', 'early_voted' => 'early_voted_current'] as $k => $col) {
            if (isset($f[$k])) {
                $w[] = "v.$col = " . ($f[$k] ? 'true' : 'false');
            }
        }
        if (isset($f['hh_min'])) {
            $w[] = 'h.voter_count >= :hh_min';
            $p[':hh_min'] = $f['hh_min'];
        }
        if (isset($f['hh_max'])) {
            $w[] = 'h.voter_count <= :hh_max';
            $p[':hh_max'] = $f['hh_max'];
        }
        if (isset($f['has_phone'])) {
            $w[] = $f['has_phone'] ? "COALESCE(v.phone,'') <> ''" : "COALESCE(v.phone,'') = ''";
        }
        if (!empty($f['tags'])) {
            $w[] = 'EXISTS (SELECT 1 FROM voter_tags t WHERE t.voter_id = v.id AND t.tag IN ' . DB::inList($f['tags'], $p, 'tg') . ')';
        }
        if (!empty($f['survey_q']) && !empty($f['survey_answers'])) {
            $w[] = 'EXISTS (SELECT 1 FROM survey_responses sr WHERE sr.question_id = :sq AND sr.answer IN ' . DB::inList($f['survey_answers'], $p, 'sa')
                . ' AND sr.contact_attempt_id IN (SELECT id FROM contact_attempts ca WHERE ca.voter_id = v.id))';
            $p[':sq'] = $f['survey_q'];
        }
        if (!empty($f['exclude_results'])) {
            $w[] = 'NOT EXISTS (SELECT 1 FROM contact_attempts ca WHERE ca.voter_id = v.id AND ca.result IN ' . DB::inList($f['exclude_results'], $p, 'xr') . ')';
        }
        if (isset($f['not_contacted_days'])) {
            $w[] = "NOT EXISTS (SELECT 1 FROM contact_attempts ca WHERE ca.voter_id = v.id AND ca.contacted_at > now() - make_interval(days => :ncd))";
            $p[':ncd'] = $f['not_contacted_days'];
        }
        if (!empty($f['name_search'])) {
            $w[] = "(upper(v.last_name) LIKE upper(:nm) OR upper(v.first_name) LIKE upper(:nm) OR upper(v.first_name || ' ' || v.last_name) LIKE upper(:nm))";
            $p[':nm'] = '%' . $f['name_search'] . '%';
        }
        if ($chase || !empty($f['chase'])) {
            $w[] = 'v.voted_current = false';
        }
        return [implode(' AND ', $w), $p];
    }

    public const FROM = ' FROM voters v LEFT JOIN households h ON h.id = v.household_id ';

    public static function counts(DB $db, array $f, bool $chase = false): array
    {
        [$w, $p] = self::where($f, $chase);
        return $db->one('SELECT count(*) AS voters, count(DISTINCT v.household_id) AS doors' . self::FROM . 'WHERE ' . $w, $p) ?: ['voters' => 0, 'doors' => 0];
    }

    /** Where clause for a saved list (frozen lists use their member snapshot). */
    public static function listWhere(array $list): array
    {
        if ($list['is_frozen']) {
            $p = [':lid' => (int) $list['id']];
            $w = 'v.id IN (SELECT voter_id FROM list_members WHERE list_id = :lid)';
            if ($list['chase_mode']) {
                $w .= ' AND v.voted_current = false';
            }
            return [$w, $p];
        }
        $f = is_array($list['filters']) ? $list['filters'] : (json_decode((string) $list['filters'], true) ?: []);
        return self::where($f, (bool) $list['chase_mode']);
    }

    public static function members(DB $db, array $list, int $limit = 100, int $offset = 0, string $order = 'v.last_name, v.first_name'): array
    {
        [$w, $p] = self::listWhere($list);
        $p[':lim'] = $limit;
        $p[':off'] = $offset;
        return $db->all('SELECT v.*, h.voter_count AS hh_size, pr.number AS precinct_number' . self::FROM
            . ' LEFT JOIN precincts pr ON pr.id = v.precinct_id WHERE ' . $w . " ORDER BY $order LIMIT :lim OFFSET :off", $p);
    }

    public static function listCounts(DB $db, array $list): array
    {
        [$w, $p] = self::listWhere($list);
        return $db->one('SELECT count(*) AS voters, count(DISTINCT v.household_id) AS doors' . self::FROM . 'WHERE ' . $w, $p) ?: ['voters' => 0, 'doors' => 0];
    }

    public static function refreshCounts(DB $db, array $list): void
    {
        $c = self::listCounts($db, $list);
        $db->exec('UPDATE lists SET member_count = :m, door_count = :d, updated_at = now() WHERE id = :id', [':m' => (int) $c['voters'], ':d' => (int) $c['doors'], ':id' => (int) $list['id']]);
    }
}
