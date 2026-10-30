<?php
declare(strict_types=1);

namespace App\Services;

final class Geo
{
    /** Normalized key for grouping voters into households. */
    public static function addressKey(array $r): string
    {
        $parts = [$r['res_street_num'] ?? '', $r['res_direction'] ?? '', $r['res_street_name'] ?? '', $r['res_address_type'] ?? '', $r['res_unit'] ?? '', $r['res_city'] ?? '', substr((string) ($r['res_zip'] ?? ''), 0, 5)];
        $k = strtoupper(preg_replace('/\s+/', ' ', trim(implode(' ', array_map(fn($x) => trim((string) $x), $parts)))) ?? '');
        return $k === '' ? '' : $k;
    }

    /** Ray-casting point in GeoJSON Polygon / MultiPolygon. */
    public static function pointInGeoJson(float $lng, float $lat, array $geom): bool
    {
        $type = $geom['type'] ?? '';
        if ($type === 'Feature') {
            return self::pointInGeoJson($lng, $lat, $geom['geometry'] ?? []);
        }
        if ($type === 'Polygon') {
            return self::pointInPolygon($lng, $lat, $geom['coordinates'] ?? []);
        }
        if ($type === 'MultiPolygon') {
            foreach ($geom['coordinates'] ?? [] as $poly) {
                if (self::pointInPolygon($lng, $lat, $poly)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function pointInPolygon(float $x, float $y, array $rings): bool
    {
        if (!$rings) {
            return false;
        }
        if (!self::pointInRing($x, $y, $rings[0])) {
            return false;
        }
        for ($i = 1; $i < count($rings); $i++) {
            if (self::pointInRing($x, $y, $rings[$i])) {
                return false; // inside a hole
            }
        }
        return true;
    }

    private static function pointInRing(float $x, float $y, array $ring): bool
    {
        $inside = false;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = (float) $ring[$i][0]; $yi = (float) $ring[$i][1];
            $xj = (float) $ring[$j][0]; $yj = (float) $ring[$j][1];
            if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $inside = !$inside;
            }
        }
        return $inside;
    }

    /** Snake-order sort key so neighbouring houses land in the same turf (works without geocodes too). */
    public static function walkSortKey(array $h): string
    {
        if (!empty($h['lat']) && !empty($h['lng'])) {
            $row = (int) floor(((float) $h['lat']) / 0.004);     // ~450 m bands
            $col = (float) $h['lng'];
            if ($row % 2) {
                $col = -$col;
            }
            return sprintf('%010d|%015.8f', $row + 100000, $col + 1000);
        }
        $line = (string) ($h['address_line'] ?? '');
        $street = preg_replace('/^\s*\d+\S*\s*/', '', $line) ?? $line;
        preg_match('/^\s*(\d+)/', $line, $m);
        $num = (int) ($m[1] ?? 0);
        return sprintf('Z%s|%d|%08d', $street, $num % 2, $num);
    }
}
