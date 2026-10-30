<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\DB;
use App\Core\Log;

/** US Census batch geocoder (free, up to 10,000 addresses per request) with an optional paid fallback. */
final class Geocoder
{
    private const CENSUS_URL = 'https://geocoding.geo.census.gov/geocoder/locations/addressbatch';

    public static function run(DB $db, int $limit = 10000, bool $retryMisses = false): array
    {
        $where = $retryMisses ? "h.lat IS NULL" : "h.lat IS NULL AND COALESCE((SELECT v.geocode_quality FROM voters v WHERE v.household_id = h.id LIMIT 1),'') <> 'miss'";
        $rows = $db->all("SELECT h.id, h.address_line, h.city, h.zip FROM households h WHERE $where AND h.address_line <> '' ORDER BY h.id LIMIT :l", [':l' => min($limit, 10000)]);
        if (!$rows) {
            return ['sent' => 0, 'matched' => 0];
        }
        $tmp = tempnam(APP_ROOT . '/storage/tmp', 'geo');
        $fh = fopen($tmp, 'w');
        foreach ($rows as $r) {
            fputcsv($fh, [$r['id'], $r['address_line'], $r['city'], 'NV', $r['zip']]);
        }
        fclose($fh);
        $ch = curl_init(self::CENSUS_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 900,
            CURLOPT_POSTFIELDS => ['addressFile' => new \CURLFile($tmp, 'text/csv', 'addresses.csv'), 'benchmark' => 'Public_AR_Current'],
            CURLOPT_USERAGENT => Config::get('INGEST_USER_AGENT', 'NevadaVoterPlatform/0.1'),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        @unlink($tmp);
        if ($resp === false || $code !== 200) {
            Log::error("Census geocoder HTTP $code");
            return ['sent' => count($rows), 'matched' => 0, 'error' => "HTTP $code"];
        }
        $matched = 0;
        $misses = [];
        $upd = $db->pdo->prepare('UPDATE households SET lat = :lat, lng = :lng WHERE id = :id');
        $updV = $db->pdo->prepare('UPDATE voters SET lat = :lat, lng = :lng, geocode_quality = :q WHERE household_id = :id');
        foreach (explode("\n", $resp) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $c = str_getcsv($line);
            // id, input address, match flag, match type, matched address, "lng,lat", tiger line id, side
            if (($c[2] ?? '') === 'Match' && !empty($c[5])) {
                [$lng, $lat] = array_map('floatval', explode(',', $c[5]));
                $upd->execute([':lat' => $lat, ':lng' => $lng, ':id' => (int) $c[0]]);
                $updV->execute([':lat' => $lat, ':lng' => $lng, ':q' => strtolower($c[3] ?? 'match'), ':id' => (int) $c[0]]);
                $matched++;
            } else {
                $misses[] = (int) $c[0];
            }
        }
        if ($misses) {
            $fallback = Config::get('GEOCODER_FALLBACK_URL');
            $fallbackMatched = $fallback ? self::fallback($db, $rows, $misses) : 0;
            $matched += $fallbackMatched;
            $still = array_slice($misses, $fallbackMatched);
            foreach (array_chunk($still, 500) as $chunk) {
                $p = [];
                $db->exec("UPDATE voters SET geocode_quality = 'miss' WHERE household_id IN " . DB::inList($chunk, $p, 'm'), $p);
            }
        }
        return ['sent' => count($rows), 'matched' => $matched];
    }

    /**
     * Optional paid fallback. Expects a GET endpoint returning JSON {lat, lng} for ?q=<address>&key=<key>.
     * Adjust to the vendor you license; this is deliberately minimal.
     */
    private static function fallback(DB $db, array $rows, array $missIds): int
    {
        $byId = array_column($rows, null, 'id');
        $url = Config::get('GEOCODER_FALLBACK_URL');
        $key = Config::get('GEOCODER_FALLBACK_KEY', '');
        $n = 0;
        foreach ($missIds as $id) {
            $r = $byId[$id] ?? null;
            if (!$r) {
                continue;
            }
            $q = rawurlencode(trim("{$r['address_line']}, {$r['city']}, NV {$r['zip']}"));
            $json = @file_get_contents("$url?q=$q&key=$key");
            $d = $json ? json_decode($json, true) : null;
            if (!empty($d['lat']) && !empty($d['lng'])) {
                $db->exec('UPDATE households SET lat = :lat, lng = :lng WHERE id = :id', [':lat' => (float) $d['lat'], ':lng' => (float) $d['lng'], ':id' => $id]);
                $db->exec("UPDATE voters SET lat = :lat, lng = :lng, geocode_quality = 'fallback' WHERE household_id = :id", [':lat' => (float) $d['lat'], ':lng' => (float) $d['lng'], ':id' => $id]);
                $n++;
            }
        }
        return $n;
    }
}
