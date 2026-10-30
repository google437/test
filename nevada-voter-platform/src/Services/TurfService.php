<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class TurfService
{
    /** Households (with coordinates and door counts) that belong to a list. */
    public static function listHouseholds(DB $db, array $list): array
    {
        [$w, $p] = ListQuery::listWhere($list);
        return $db->all('SELECT h.id, h.address_line, h.city, h.zip, h.lat, h.lng, count(v.id) AS voters' . ListQuery::FROM
            . 'WHERE ' . $w . ' AND v.household_id IS NOT NULL GROUP BY h.id ORDER BY h.id', $p);
    }

    /** Split a list into turfs of roughly $target doors each. Returns created turf ids. */
    public static function autoSplit(DB $db, array $list, int $target, int $orgId, ?int $scriptId = null): array
    {
        $hh = self::listHouseholds($db, $list);
        usort($hh, fn($a, $b) => strcmp(Geo::walkSortKey($a), Geo::walkSortKey($b)));
        $chunks = $target > 0 ? array_chunk($hh, $target) : [$hh];
        $ids = [];
        $existing = (int) $db->val('SELECT count(*) FROM turfs WHERE list_id = :l', [':l' => (int) $list['id']]);
        foreach ($chunks as $i => $chunk) {
            $ids[] = self::create($db, $list, $orgId, sprintf('%s turf %d', $list['name'], $existing + $i + 1), null, $chunk, $scriptId);
        }
        return $ids;
    }

    /** Create a turf from a drawn GeoJSON polygon. */
    public static function fromPolygon(DB $db, array $list, int $orgId, string $name, array $geojson, ?int $scriptId = null): int
    {
        $hh = array_values(array_filter(self::listHouseholds($db, $list), fn($h) => $h['lat'] !== null && $h['lng'] !== null && Geo::pointInGeoJson((float) $h['lng'], (float) $h['lat'], $geojson)));
        usort($hh, fn($a, $b) => strcmp(Geo::walkSortKey($a), Geo::walkSortKey($b)));
        return self::create($db, $list, $orgId, $name, $geojson, $hh, $scriptId);
    }

    private static function create(DB $db, array $list, int $orgId, string $name, ?array $geojson, array $households, ?int $scriptId): int
    {
        return (int) $db->tx(function (DB $db) use ($list, $orgId, $name, $geojson, $households, $scriptId) {
            $id = (int) $db->insert('turfs', [
                'org_id' => $orgId, 'list_id' => (int) $list['id'], 'name' => $name,
                'geom_geojson' => $geojson ? json_encode($geojson) : null, 'script_id' => $scriptId,
                'door_count' => count($households), 'voter_count' => array_sum(array_map(fn($h) => (int) $h['voters'], $households)),
            ]);
            $st = $db->pdo->prepare('INSERT INTO turf_households (turf_id, household_id, org_id, walk_order) VALUES (:t, :h, :o, :w)');
            foreach ($households as $i => $h) {
                $st->execute([':t' => $id, ':h' => (int) $h['id'], ':o' => $orgId, ':w' => $i + 1]);
            }
            return $id;
        });
    }

    /** Households and their voters for a turf, in walk order, with this org's latest contact result. */
    public static function walkData(DB $db, int $turfId): array
    {
        $rows = $db->all('SELECT th.walk_order, h.id AS household_id, h.address_line, h.city, h.zip, h.lat, h.lng,
                v.id AS voter_id, v.first_name, v.last_name, v.birth_year, v.party, v.sex, v.phone, v.status, v.res_unit, v.voted_current,
                (SELECT ca.result FROM contact_attempts ca WHERE ca.voter_id = v.id ORDER BY ca.contacted_at DESC LIMIT 1) AS last_result
             FROM turf_households th JOIN households h ON h.id = th.household_id
             JOIN voters v ON v.household_id = h.id AND v.status <> \'removed\'
             WHERE th.turf_id = :t ORDER BY th.walk_order, v.last_name, v.first_name', [':t' => $turfId]);
        $out = [];
        foreach ($rows as $r) {
            $hid = (int) $r['household_id'];
            if (!isset($out[$hid])) {
                $out[$hid] = ['household_id' => $hid, 'walk_order' => (int) $r['walk_order'], 'address_line' => $r['address_line'], 'city' => $r['city'], 'zip' => $r['zip'], 'lat' => $r['lat'], 'lng' => $r['lng'], 'voters' => []];
            }
            $out[$hid]['voters'][] = [
                'id' => (int) $r['voter_id'], 'name' => trim($r['first_name'] . ' ' . $r['last_name']), 'age' => age_from_year($r['birth_year'] ? (int) $r['birth_year'] : null),
                'party' => $r['party'], 'sex' => $r['sex'], 'phone' => $r['phone'], 'unit' => $r['res_unit'], 'voted' => (bool) $r['voted_current'], 'last_result' => $r['last_result'],
            ];
        }
        return array_values($out);
    }
}
