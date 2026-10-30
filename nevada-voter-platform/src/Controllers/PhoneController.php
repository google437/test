<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Flash;
use App\Core\Request;
use App\Core\View;
use App\Services\ListQuery;

final class PhoneController
{
    public function index(Request $req): void
    {
        $db = DB::app();
        $lists = $db->all('SELECT l.*, (SELECT count(*) FROM contact_attempts ca WHERE ca.list_id = l.id AND ca.channel = \'phone\') AS calls FROM lists l ORDER BY l.updated_at DESC');
        View::render('phone/index', ['title' => 'Phone bank', 'lists' => $lists]);
    }

    /** Next voter in the list with a phone number and no phone attempt yet. Random pick avoids two callers colliding. */
    public function next(Request $req): void
    {
        $db = DB::app();
        $list = ListController::find($db, (int) $req->params['list_id']);
        [$w, $p] = ListQuery::listWhere($list);
        $p[':lid'] = (int) $list['id'];
        $voter = $db->one('SELECT v.*, pr.number AS precinct_number' . ListQuery::FROM . "LEFT JOIN precincts pr ON pr.id = v.precinct_id WHERE $w AND COALESCE(v.phone,'') <> ''
            AND NOT EXISTS (SELECT 1 FROM contact_attempts ca WHERE ca.voter_id = v.id AND ca.channel = 'phone' AND (ca.list_id = :lid OR ca.result IN ('wrong_number','deceased','moved','refused')))
            ORDER BY random() LIMIT 1", $p);
        $remaining = (int) $db->val('SELECT count(*)' . ListQuery::FROM . "WHERE $w AND COALESCE(v.phone,'') <> '' AND NOT EXISTS (SELECT 1 FROM contact_attempts ca WHERE ca.voter_id = v.id AND ca.channel = 'phone' AND (ca.list_id = :lid OR ca.result IN ('wrong_number','deceased','moved','refused')))", $p);
        $script = $db->one("SELECT * FROM scripts WHERE channel IN ('phone','any') ORDER BY (channel = 'phone') DESC, is_default DESC LIMIT 1");
        $questions = $db->all('SELECT * FROM survey_questions WHERE active ORDER BY sort_order, id');
        $myCalls = (int) $db->val("SELECT count(*) FROM contact_attempts WHERE user_id = :u AND channel = 'phone' AND contacted_at::date = current_date", [':u' => Auth::userId()]);
        View::render('phone/call', ['title' => 'Phone bank: ' . $list['name'], 'list' => $list, 'voter' => $voter, 'remaining' => $remaining, 'script' => $script, 'questions' => $questions, 'myCalls' => $myCalls, 'results' => ['contacted', 'not_home', 'left_message', 'refused', 'wrong_number', 'moved', 'deceased']]);
    }

    public function record(Request $req): void
    {
        $db = DB::app();
        $list = ListController::find($db, (int) $req->params['list_id']);
        $id = VoterController::recordAttempt($db, $_POST + ['channel' => 'phone', 'list_id' => $list['id']], (int) Auth::orgId(), (int) Auth::userId());
        if (!$id) {
            Flash::set('error', 'Could not record that call.');
        }
        redirect('/phone/' . $list['id']);
    }
}
