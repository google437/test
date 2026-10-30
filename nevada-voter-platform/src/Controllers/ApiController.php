<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\RateLimit;
use App\Core\Request;
use App\Services\ListQuery;
use App\Services\TurfService;

final class ApiController
{
    public function me(Request $req): void
    {
        $u = Auth::user();
        json_out(['user' => ['id' => $u['id'], 'name' => $u['name']], 'org' => Auth::org()['name'] ?? null, 'role' => Auth::role()]);
    }

    /** Live counts for the list builder. */
    public function count(Request $req): void
    {
        if (!RateLimit::allow('count:' . Auth::userId(), 300, 600)) {
            json_out(['error' => 'rate_limited'], 429);
        }
        $f = ListQuery::sanitize($req->json());
        $c = ListQuery::counts(DB::app(), $f, !empty($f['chase']));
        json_out(['voters' => (int) $c['voters'], 'doors' => (int) $c['doors']]);
    }

    public function precincts(Request $req): void
    {
        $db = DB::app();
        $ids = array_map('intval', explode(',', (string) $req->get('districts', '')));
        $ids = array_filter($ids);
        if ($ids) {
            $p = [];
            $rows = $db->all('SELECT DISTINCT p.id, p.number FROM precincts p JOIN precinct_districts pd ON pd.precinct_id = p.id WHERE pd.district_id IN ' . DB::inList($ids, $p, 'd') . ' ORDER BY p.number', $p);
        } else {
            $rows = $db->all('SELECT DISTINCT p.id, p.number FROM precincts p JOIN voters v ON v.precinct_id = p.id ORDER BY p.number LIMIT 2000');
        }
        json_out($rows);
    }

    /** Households of a list as GeoJSON points (for the map). */
    public function listGeoJson(Request $req): void
    {
        $db = DB::app();
        $list = ListController::find($db, (int) $req->params['id']);
        $hh = TurfService::listHouseholds($db, $list);
        $features = [];
        $missing = 0;
        foreach ($hh as $h) {
            if ($h['lat'] === null || $h['lng'] === null) {
                $missing++;
                continue;
            }
            $features[] = ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [(float) $h['lng'], (float) $h['lat']]], 'properties' => ['id' => (int) $h['id'], 'address' => $h['address_line'], 'voters' => (int) $h['voters']]];
        }
        $turfs = $db->all('SELECT id, name, geom_geojson, door_count FROM turfs WHERE list_id = :l AND geom_geojson IS NOT NULL', [':l' => (int) $list['id']]);
        json_out(['type' => 'FeatureCollection', 'features' => $features, 'missing_geocode' => $missing,
            'turfs' => array_map(fn($t) => ['type' => 'Feature', 'geometry' => json_decode((string) $t['geom_geojson'], true), 'properties' => ['id' => (int) $t['id'], 'name' => $t['name'], 'doors' => (int) $t['door_count']]], $turfs)]);
    }

    public function turfFromPolygon(Request $req): void
    {
        $db = DB::app();
        $list = ListController::find($db, (int) $req->params['id']);
        $in = $req->json();
        $geom = $in['geometry'] ?? null;
        if (!is_array($geom) || ($geom['type'] ?? '') !== 'Polygon') {
            json_out(['error' => 'geometry must be a GeoJSON Polygon'], 422);
        }
        $name = substr(trim((string) ($in['name'] ?? '')), 0, 100) ?: $list['name'] . ' drawn turf';
        $id = TurfService::fromPolygon($db, $list, (int) Auth::orgId(), $name, $geom, !empty($in['script_id']) ? (int) $in['script_id'] : null);
        $t = $db->one('SELECT id, name, door_count, voter_count FROM turfs WHERE id = :id', [':id' => $id]);
        Audit::log('turf.polygon', ['list_id' => $list['id'], 'turf_id' => $id, 'doors' => $t['door_count']]);
        json_out($t);
    }

    /** Everything the offline canvass app needs for one turf. */
    public function turf(Request $req): void
    {
        $db = DB::app();
        $turf = TurfController::find($db, (int) $req->params['id']);
        $script = $turf['script_id'] ? $db->one('SELECT name, body FROM scripts WHERE id = :s', [':s' => (int) $turf['script_id']]) : $db->one("SELECT name, body FROM scripts WHERE channel IN ('door','any') ORDER BY is_default DESC LIMIT 1");
        $questions = $db->all('SELECT id, question, qtype, options FROM survey_questions WHERE active ORDER BY sort_order, id');
        foreach ($questions as &$q) {
            $q['options'] = json_decode((string) $q['options'], true) ?: [];
        }
        json_out(['turf' => ['id' => (int) $turf['id'], 'name' => $turf['name'], 'list_id' => (int) $turf['list_id'], 'status' => $turf['status'], 'door_count' => (int) $turf['door_count'], 'geom' => $turf['geom_geojson'] ? json_decode((string) $turf['geom_geojson'], true) : null],
            'households' => TurfService::walkData($db, (int) $turf['id']), 'script' => $script, 'questions' => $questions, 'results' => ['contacted', 'not_home', 'refused', 'moved', 'deceased'], 'fetched_at' => date('c')]);
    }

    /** Offline queue sync: array of attempts, each with a client_id for idempotency. */
    public function sync(Request $req): void
    {
        $db = DB::app();
        $items = $req->json()['attempts'] ?? [];
        if (!is_array($items) || count($items) > 500) {
            json_out(['error' => 'bad_request'], 422);
        }
        $ok = [];
        $failed = [];
        foreach ($items as $it) {
            if (!is_array($it) || empty($it['client_id'])) {
                continue;
            }
            try {
                $id = VoterController::recordAttempt($db, $it + ['channel' => 'door'], (int) Auth::orgId(), (int) Auth::userId());
                if ($id) {
                    $ok[] = (string) $it['client_id'];
                } else {
                    $failed[] = ['client_id' => (string) $it['client_id'], 'reason' => 'rejected'];
                }
            } catch (\Throwable $e) {
                $failed[] = ['client_id' => (string) $it['client_id'], 'reason' => 'error'];
            }
        }
        if ($ok) {
            Audit::log('canvass.sync', ['count' => count($ok)]);
        }
        json_out(['synced' => $ok, 'failed' => $failed]);
    }
}
