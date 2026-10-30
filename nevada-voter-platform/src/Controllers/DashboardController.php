<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\View;
use App\Services\ExportService;

final class DashboardController
{
    public function index(Request $req): void
    {
        $db = DB::app();
        if (Auth::isStaff() && !Auth::org()) {
            redirect('/admin');
        }
        $orgId = Auth::orgId();
        $stats = $db->one("SELECT
            (SELECT count(*) FROM voters v WHERE v.status <> 'removed') AS voters,
            (SELECT count(DISTINCT household_id) FROM voters v WHERE v.status <> 'removed' AND household_id IS NOT NULL) AS doors,
            (SELECT count(*) FROM contact_attempts WHERE channel = 'door') AS knocks,
            (SELECT count(*) FROM contact_attempts WHERE channel = 'phone') AS calls,
            (SELECT count(*) FROM contact_attempts WHERE result = 'contacted') AS contacts,
            (SELECT count(*) FROM contact_attempts WHERE contacted_at > now() - interval '7 days') AS attempts_week,
            (SELECT count(*) FROM lists) AS lists,
            (SELECT count(*) FROM turfs WHERE status <> 'done') AS open_turfs,
            (SELECT count(*) FROM voters v WHERE v.status <> 'removed' AND v.voted_current) AS voted");
        $myTurfs = $db->all('SELECT t.*, l.name AS list_name FROM turfs t JOIN lists l ON l.id = t.list_id WHERE t.assigned_user_id = :u AND t.status <> \'done\' ORDER BY t.id DESC LIMIT 5', [':u' => Auth::userId()]);
        $recentLists = $db->all('SELECT * FROM lists ORDER BY updated_at DESC LIMIT 6');
        $lastRun = $db->one("SELECT * FROM import_runs WHERE status = 'loaded' AND source = 'clark-voters' ORDER BY finished_at DESC LIMIT 1");
        $window = $db->one('SELECT * FROM election_windows WHERE is_current ORDER BY election_date DESC LIMIT 1');
        $leaders = $db->all('SELECT u.name, count(*) AS attempts, count(*) FILTER (WHERE ca.result = \'contacted\') AS contacts FROM contact_attempts ca JOIN users u ON u.id = ca.user_id WHERE ca.contacted_at > now() - interval \'30 days\' GROUP BY u.name ORDER BY attempts DESC LIMIT 8');
        View::render('dashboard/index', compact('stats', 'myTurfs', 'recentLists', 'lastRun', 'window', 'leaders') + ['title' => 'Dashboard']);
    }

    public function reports(Request $req): void
    {
        $db = DB::app();
        $byPrecinct = $db->all("SELECT pr.number AS precinct, count(*) AS attempts, count(*) FILTER (WHERE ca.result = 'contacted') AS contacts,
                count(DISTINCT ca.voter_id) AS voters_reached
            FROM contact_attempts ca JOIN voters v ON v.id = ca.voter_id LEFT JOIN precincts pr ON pr.id = v.precinct_id
            GROUP BY pr.number ORDER BY attempts DESC LIMIT 100");
        $questions = $db->all('SELECT * FROM survey_questions WHERE active ORDER BY sort_order, id');
        $answers = [];
        foreach ($questions as $q) {
            $answers[$q['id']] = $db->all('SELECT answer, count(*) AS n FROM survey_responses WHERE question_id = :q GROUP BY answer ORDER BY n DESC', [':q' => (int) $q['id']]);
        }
        $supportByPrecinct = $db->all("SELECT pr.number AS precinct, round(avg(sr.answer::numeric), 2) AS avg_support, count(*) AS n
            FROM survey_responses sr JOIN survey_questions q ON q.id = sr.question_id AND q.qtype = 'scale'
            JOIN contact_attempts ca ON ca.id = sr.contact_attempt_id JOIN voters v ON v.id = ca.voter_id LEFT JOIN precincts pr ON pr.id = v.precinct_id
            WHERE sr.answer ~ '^[0-9]+$' GROUP BY pr.number ORDER BY n DESC LIMIT 100");
        $results = $db->all('SELECT channel, result, count(*) AS n FROM contact_attempts GROUP BY channel, result ORDER BY channel, n DESC');
        $leaders = $db->all("SELECT u.name, count(*) AS attempts, count(*) FILTER (WHERE ca.result = 'contacted') AS contacts, count(DISTINCT date(ca.contacted_at)) AS days_active
            FROM contact_attempts ca JOIN users u ON u.id = ca.user_id GROUP BY u.name ORDER BY attempts DESC LIMIT 25");
        $daily = $db->all("SELECT date(contacted_at) AS d, count(*) AS n, count(*) FILTER (WHERE result = 'contacted') AS c FROM contact_attempts WHERE contacted_at > now() - interval '30 days' GROUP BY 1 ORDER BY 1");
        View::render('reports/index', compact('byPrecinct', 'questions', 'answers', 'supportByPrecinct', 'results', 'leaders', 'daily') + ['title' => 'Reports']);
    }

    public function surveyExport(Request $req): void
    {
        ExportService::surveyCsv(DB::app(), (int) Auth::orgId());
    }
}
