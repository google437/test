<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Flash;
use App\Core\Request;
use App\Core\View;
use App\Services\TurfService;

final class TurfController
{
    public static function find(DB $db, int $id): array
    {
        $t = $db->one('SELECT t.*, l.name AS list_name, u.name AS assignee FROM turfs t JOIN lists l ON l.id = t.list_id LEFT JOIN users u ON u.id = t.assigned_user_id WHERE t.id = :id', [':id' => $id]);
        if (!$t) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Not found']);
            exit;
        }
        return $t;
    }

    public static function members(DB $db): array
    {
        return $db->all("SELECT u.id, u.name, m.role FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.org_id = :o AND u.status = 'active' ORDER BY u.name", [':o' => Auth::orgId()]);
    }

    public function index(Request $req): void
    {
        $db = DB::app();
        $list = ListController::find($db, (int) $req->params['id']);
        $turfs = $db->all('SELECT t.*, u.name AS assignee, (SELECT count(*) FROM contact_attempts ca WHERE ca.turf_id = t.id) AS attempts FROM turfs t LEFT JOIN users u ON u.id = t.assigned_user_id WHERE t.list_id = :l ORDER BY t.id', [':l' => (int) $list['id']]);
        $scripts = $db->all('SELECT id, name FROM scripts ORDER BY is_default DESC, name');
        View::render('turfs/index', ['title' => $list['name'] . ' turfs', 'list' => $list, 'turfs' => $turfs, 'members' => self::members($db), 'scripts' => $scripts]);
    }

    public function autoSplit(Request $req): void
    {
        $db = DB::app();
        $list = ListController::find($db, (int) $req->params['id']);
        $target = max(5, min(500, (int) $req->post('doors', 40)));
        $ids = TurfService::autoSplit($db, $list, $target, (int) Auth::orgId(), $req->int('script_id'));
        Audit::log('turf.auto_split', ['list_id' => $list['id'], 'doors' => $target, 'turfs' => count($ids)]);
        Flash::set('success', count($ids) . ' turfs created.');
        redirect('/lists/' . $list['id'] . '/turfs');
    }

    public function show(Request $req): void
    {
        $db = DB::app();
        $turf = self::find($db, (int) $req->params['id']);
        $walk = TurfService::walkData($db, (int) $turf['id']);
        $attempts = $db->one("SELECT count(*) AS n, count(*) FILTER (WHERE result = 'contacted') AS c FROM contact_attempts WHERE turf_id = :t", [':t' => (int) $turf['id']]);
        View::render('turfs/show', ['title' => $turf['name'], 'turf' => $turf, 'walk' => $walk, 'members' => self::members($db), 'attempts' => $attempts, 'mapStyle' => \App\Core\Config::get('MAP_STYLE_URL', 'https://tiles.openfreemap.org/styles/liberty')]);
    }

    public function assign(Request $req): void
    {
        $db = DB::app();
        $turf = self::find($db, (int) $req->params['id']);
        $uid = $req->int('user_id');
        if ($uid && !$db->val('SELECT 1 FROM memberships WHERE org_id = :o AND user_id = :u', [':o' => Auth::orgId(), ':u' => $uid])) {
            $uid = null;
        }
        $db->exec('UPDATE turfs SET assigned_user_id = :u, script_id = :s WHERE id = :id', [':u' => $uid, ':s' => $req->int('script_id') ?: null, ':id' => (int) $turf['id']]);
        Audit::log('turf.assign', ['turf_id' => $turf['id'], 'user_id' => $uid]);
        redirect((string) ($req->post('back') ?: '/turfs/' . $turf['id']));
    }

    public function status(Request $req): void
    {
        $db = DB::app();
        $turf = self::find($db, (int) $req->params['id']);
        $s = in_array($req->str('status'), ['open', 'in_progress', 'done'], true) ? $req->str('status') : 'open';
        $db->exec('UPDATE turfs SET status = :s WHERE id = :id', [':s' => $s, ':id' => (int) $turf['id']]);
        redirect((string) ($req->post('back') ?: '/turfs/' . $turf['id']));
    }

    public function delete(Request $req): void
    {
        $db = DB::app();
        $turf = self::find($db, (int) $req->params['id']);
        $db->exec('DELETE FROM turfs WHERE id = :id', [':id' => (int) $turf['id']]);
        Flash::set('success', 'Turf deleted.');
        redirect('/lists/' . $turf['list_id'] . '/turfs');
    }

    public function walksheet(Request $req): void
    {
        $db = DB::app();
        $turf = self::find($db, (int) $req->params['id']);
        $script = $turf['script_id'] ? $db->one('SELECT * FROM scripts WHERE id = :s', [':s' => (int) $turf['script_id']]) : $db->one("SELECT * FROM scripts WHERE channel IN ('door','any') ORDER BY is_default DESC LIMIT 1");
        $questions = $db->all('SELECT * FROM survey_questions WHERE active ORDER BY sort_order, id');
        Audit::log('turf.walksheet', ['turf_id' => $turf['id']]);
        View::render('turfs/walksheet', ['title' => 'Walk sheet: ' . $turf['name'], 'turf' => $turf, 'walk' => TurfService::walkData($db, (int) $turf['id']), 'script' => $script, 'questions' => $questions], null);
    }
}
