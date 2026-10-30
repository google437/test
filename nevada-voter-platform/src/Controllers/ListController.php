<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Flash;
use App\Core\Request;
use App\Core\View;
use App\Services\ExportService;
use App\Services\ListQuery;

final class ListController
{
    public static function find(DB $db, int $id): array
    {
        $l = $db->one('SELECT * FROM lists WHERE id = :id', [':id' => $id]);
        if (!$l) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Not found']);
            exit;
        }
        $l['filters'] = json_decode((string) $l['filters'], true) ?: [];
        return $l;
    }

    /** Lookups the list builder needs (licensed districts, precincts, elections, tags, questions). */
    public static function lookups(DB $db): array
    {
        $staff = Auth::isStaff();
        $districts = $staff
            ? $db->all('SELECT d.* FROM districts d ORDER BY d.type, length(d.name), d.name')
            : $db->all('SELECT d.* FROM districts d JOIN org_districts od ON od.district_id = d.id WHERE od.org_id = :o ORDER BY d.type, length(d.name), d.name', [':o' => Auth::orgId()]);
        $precincts = $db->all('SELECT DISTINCT p.id, p.number FROM precincts p JOIN voters v ON v.precinct_id = p.id ORDER BY p.number LIMIT 2000');
        $elections = $db->all('SELECT * FROM elections ORDER BY election_date DESC');
        $tags = $db->all('SELECT tag, count(*) AS n FROM voter_tags GROUP BY tag ORDER BY n DESC LIMIT 100');
        $questions = $db->all('SELECT * FROM survey_questions WHERE active ORDER BY sort_order, id');
        return compact('districts', 'precincts', 'elections', 'tags', 'questions');
    }

    public function index(Request $req): void
    {
        $db = DB::app();
        $lists = $db->all('SELECT l.*, u.name AS creator, (SELECT count(*) FROM turfs t WHERE t.list_id = l.id) AS turf_count FROM lists l LEFT JOIN users u ON u.id = l.created_by ORDER BY l.updated_at DESC');
        View::render('lists/index', ['title' => 'Lists', 'lists' => $lists]);
    }

    public function form(Request $req): void
    {
        $db = DB::app();
        $list = isset($req->params['id']) ? self::find($db, (int) $req->params['id']) : ['id' => null, 'name' => '', 'filters' => [], 'chase_mode' => false, 'is_frozen' => false];
        View::render('lists/form', ['title' => $list['id'] ? 'Edit list' : 'New list', 'list' => $list] + self::lookups($db));
    }

    public function store(Request $req): void
    {
        $db = DB::app();
        $filters = ListQuery::sanitize($_POST);
        $id = (int) $db->insert('lists', ['org_id' => Auth::orgId(), 'name' => $req->str('name') ?: 'Untitled list', 'filters' => json_encode($filters), 'chase_mode' => $req->bool('chase_mode'), 'created_by' => Auth::userId()]);
        ListQuery::refreshCounts($db, self::find($db, $id));
        Audit::log('list.create', ['list_id' => $id, 'filters' => $filters]);
        if ($req->bool('freeze')) {
            $this->doFreeze($db, $id);
        }
        Flash::set('success', 'List saved.');
        redirect("/lists/$id");
    }

    public function update(Request $req): void
    {
        $db = DB::app();
        $list = self::find($db, (int) $req->params['id']);
        $filters = ListQuery::sanitize($_POST);
        $db->exec('UPDATE lists SET name = :n, filters = :f, chase_mode = :c, updated_at = now() WHERE id = :id', [':n' => $req->str('name') ?: $list['name'], ':f' => json_encode($filters), ':c' => $req->bool('chase_mode'), ':id' => (int) $list['id']]);
        if (!$list['is_frozen']) {
            ListQuery::refreshCounts($db, self::find($db, (int) $list['id']));
        }
        Audit::log('list.update', ['list_id' => $list['id'], 'filters' => $filters]);
        Flash::set('success', 'List updated.');
        redirect('/lists/' . $list['id']);
    }

    public function show(Request $req): void
    {
        $db = DB::app();
        $list = self::find($db, (int) $req->params['id']);
        $page = max(1, (int) $req->get('page', 1));
        $per = 50;
        $counts = ListQuery::listCounts($db, $list);
        $members = ListQuery::members($db, $list, $per, ($page - 1) * $per);
        $turfs = $db->all('SELECT t.*, u.name AS assignee FROM turfs t LEFT JOIN users u ON u.id = t.assigned_user_id WHERE t.list_id = :l ORDER BY t.id', [':l' => (int) $list['id']]);
        $lookups = self::lookups($db);
        View::render('lists/show', ['title' => $list['name'], 'list' => $list, 'counts' => $counts, 'members' => $members, 'page' => $page, 'per' => $per, 'turfs' => $turfs, 'districtNames' => array_column($lookups['districts'], null, 'id'), 'questions' => $lookups['questions']]);
    }

    private function doFreeze(DB $db, int $id): void
    {
        $list = self::find($db, $id);
        if ($list['is_frozen']) {
            return;
        }
        [$w, $p] = ListQuery::where($list['filters'], (bool) $list['chase_mode']);
        $p[':lid'] = $id;
        $p[':org'] = Auth::orgId();
        $db->tx(function (DB $db) use ($w, $p, $id) {
            $db->exec('DELETE FROM list_members WHERE list_id = :lid', [':lid' => $id]);
            $db->exec('INSERT INTO list_members (list_id, voter_id, org_id) SELECT :lid, v.id, :org' . ListQuery::FROM . 'WHERE ' . $w, $p);
            $db->exec('UPDATE lists SET is_frozen = true, frozen_at = now(), updated_at = now() WHERE id = :lid', [':lid' => $id]);
        });
        ListQuery::refreshCounts($db, self::find($db, $id));
        Audit::log('list.freeze', ['list_id' => $id]);
    }

    public function freeze(Request $req): void
    {
        $this->doFreeze(DB::app(), (int) $req->params['id']);
        Flash::set('success', 'List frozen as a snapshot.');
        redirect('/lists/' . (int) $req->params['id']);
    }

    public function delete(Request $req): void
    {
        $db = DB::app();
        $list = self::find($db, (int) $req->params['id']);
        $db->exec('DELETE FROM lists WHERE id = :id', [':id' => (int) $list['id']]);
        Audit::log('list.delete', ['list_id' => $list['id'], 'name' => $list['name']]);
        Flash::set('success', 'List deleted.');
        redirect('/lists');
    }

    public function export(Request $req): void
    {
        $db = DB::app();
        ExportService::listCsv($db, self::find($db, (int) $req->params['id']));
    }

    public function map(Request $req): void
    {
        $db = DB::app();
        $list = self::find($db, (int) $req->params['id']);
        $scripts = $db->all('SELECT id, name FROM scripts ORDER BY is_default DESC, name');
        View::render('lists/map', ['title' => $list['name'] . ' map', 'list' => $list, 'scripts' => $scripts, 'mapStyle' => \App\Core\Config::get('MAP_STYLE_URL', 'https://tiles.openfreemap.org/styles/liberty')]);
    }
}
