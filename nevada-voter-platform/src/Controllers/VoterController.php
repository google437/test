<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Flash;
use App\Core\RateLimit;
use App\Core\Request;
use App\Core\View;
use App\Services\ListQuery;

final class VoterController
{
    public const RESULTS = ['contacted', 'not_home', 'refused', 'moved', 'deceased', 'wrong_number', 'left_message'];

    public static function find(DB $db, int $id): array
    {
        $v = $db->one('SELECT v.*, pr.number AS precinct_number, h.voter_count AS hh_size, h.address_line AS hh_address FROM voters v LEFT JOIN precincts pr ON pr.id = v.precinct_id LEFT JOIN households h ON h.id = v.household_id WHERE v.id = :id', [':id' => $id]);
        if (!$v) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Not found']);
            exit;
        }
        return $v;
    }

    public function search(Request $req): void
    {
        $db = DB::app();
        $q = $req->str('q');
        $rows = [];
        if ($q !== '') {
            if (!RateLimit::allow('search:' . Auth::userId(), 120, 600)) {
                Flash::set('error', 'Search rate limit reached. Try again in a few minutes.');
                redirect('/voters');
            }
            [$w, $p] = ListQuery::where(['name_search' => $q]);
            $p[':lim'] = 50;
            $rows = $db->all('SELECT v.*, pr.number AS precinct_number' . ListQuery::FROM . 'LEFT JOIN precincts pr ON pr.id = v.precinct_id WHERE ' . $w . ' ORDER BY v.last_name, v.first_name LIMIT :lim', $p);
            Audit::log('voter.search', ['chars' => strlen($q), 'results' => count($rows)]);
        }
        View::render('voters/search', ['title' => 'Find a voter', 'q' => $q, 'rows' => $rows]);
    }

    public function show(Request $req): void
    {
        $db = DB::app();
        $v = self::find($db, (int) $req->params['id']);
        $history = $db->all('SELECT vh.*, e.election_date, e.name FROM vote_history vh LEFT JOIN elections e ON e.code = vh.election_code WHERE vh.voter_id = :v ORDER BY e.election_date DESC NULLS LAST', [':v' => (int) $v['id']]);
        $ballot = $db->all('SELECT * FROM ballot_activity WHERE voter_id = :v ORDER BY election_code DESC', [':v' => (int) $v['id']]);
        $changes = $db->all('SELECT * FROM voter_changes WHERE voter_id = :v ORDER BY changed_at DESC LIMIT 50', [':v' => (int) $v['id']]);
        $attempts = $db->all('SELECT ca.*, u.name AS user_name FROM contact_attempts ca LEFT JOIN users u ON u.id = ca.user_id WHERE ca.voter_id = :v ORDER BY ca.contacted_at DESC', [':v' => (int) $v['id']]);
        $responses = $db->all('SELECT sr.*, q.question FROM survey_responses sr JOIN survey_questions q ON q.id = sr.question_id WHERE sr.contact_attempt_id IN (SELECT id FROM contact_attempts WHERE voter_id = :v) ORDER BY sr.id DESC', [':v' => (int) $v['id']]);
        $tags = $db->all('SELECT tag FROM voter_tags WHERE voter_id = :v ORDER BY tag', [':v' => (int) $v['id']]);
        $notes = $db->all('SELECT n.*, u.name AS user_name FROM notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.voter_id = :v ORDER BY n.created_at DESC', [':v' => (int) $v['id']]);
        $household = $v['household_id'] ? $db->all("SELECT id, first_name, last_name, birth_year, party, status FROM voters WHERE household_id = :h AND id <> :v AND status <> 'removed' ORDER BY last_name, first_name", [':h' => (int) $v['household_id'], ':v' => (int) $v['id']]) : [];
        $districts = $db->all('SELECT d.type, d.name FROM precinct_districts pd JOIN districts d ON d.id = pd.district_id WHERE pd.precinct_id = :p ORDER BY d.type', [':p' => (int) ($v['precinct_id'] ?? 0)]);
        $questions = $db->all('SELECT * FROM survey_questions WHERE active ORDER BY sort_order, id');
        $scripts = $db->all('SELECT * FROM scripts ORDER BY is_default DESC, name');
        View::render('voters/show', compact('v', 'history', 'ballot', 'changes', 'attempts', 'responses', 'tags', 'notes', 'household', 'districts', 'questions', 'scripts') + ['title' => trim($v['first_name'] . ' ' . $v['last_name']), 'results' => self::RESULTS]);
    }

    /** Record a contact attempt (door or phone) plus survey answers. Shared with the sync API. */
    public static function recordAttempt(DB $db, array $in, int $orgId, int $userId): ?int
    {
        $voterId = (int) ($in['voter_id'] ?? 0);
        $result = (string) ($in['result'] ?? '');
        if ($voterId <= 0 || !in_array($result, self::RESULTS, true)) {
            return null;
        }
        if (!$db->val('SELECT 1 FROM voters WHERE id = :v', [':v' => $voterId])) {
            return null; // outside licensed districts (RLS) or unknown
        }
        $channel = in_array($in['channel'] ?? '', ['door', 'phone', 'text'], true) ? $in['channel'] : 'door';
        $clientId = isset($in['client_id']) && $in['client_id'] !== '' ? substr((string) $in['client_id'], 0, 64) : null;
        return (int) $db->tx(function (DB $db) use ($in, $orgId, $userId, $voterId, $result, $channel, $clientId) {
            if ($clientId) {
                $existing = $db->val('SELECT id FROM contact_attempts WHERE org_id = :o AND client_id = :c', [':o' => $orgId, ':c' => $clientId]);
                if ($existing) {
                    return (int) $existing; // idempotent replay from the offline queue
                }
            }
            $contactedAt = !empty($in['contacted_at']) && strtotime((string) $in['contacted_at']) ? date('c', strtotime((string) $in['contacted_at'])) : date('c');
            $id = (int) $db->insert('contact_attempts', [
                'org_id' => $orgId, 'voter_id' => $voterId, 'user_id' => $userId, 'turf_id' => !empty($in['turf_id']) ? (int) $in['turf_id'] : null, 'list_id' => !empty($in['list_id']) ? (int) $in['list_id'] : null,
                'channel' => $channel, 'result' => $result, 'notes' => isset($in['notes']) ? substr(trim((string) $in['notes']), 0, 2000) : null,
                'lat' => isset($in['lat']) && $in['lat'] !== '' ? (float) $in['lat'] : null, 'lng' => isset($in['lng']) && $in['lng'] !== '' ? (float) $in['lng'] : null,
                'client_id' => $clientId, 'contacted_at' => $contactedAt,
            ]);
            foreach ((array) ($in['answers'] ?? []) as $qid => $answer) {
                $answer = is_array($answer) ? implode(', ', $answer) : trim((string) $answer);
                if ($answer === '' || !$db->val('SELECT 1 FROM survey_questions WHERE id = :q', [':q' => (int) $qid])) {
                    continue;
                }
                $db->insert('survey_responses', ['org_id' => $orgId, 'contact_attempt_id' => $id, 'question_id' => (int) $qid, 'answer' => substr($answer, 0, 500)], '');
            }
            if ($result === 'moved' || $result === 'deceased') {
                $db->exec('INSERT INTO voter_tags (org_id, voter_id, tag, created_by) VALUES (:o, :v, :t, :u) ON CONFLICT DO NOTHING', [':o' => $orgId, ':v' => $voterId, ':t' => $result, ':u' => $userId]);
            }
            if (!empty($in['turf_id'])) {
                $db->exec("UPDATE turfs SET status = 'in_progress' WHERE id = :t AND status = 'open'", [':t' => (int) $in['turf_id']]);
            }
            return $id;
        });
    }

    public function contact(Request $req): void
    {
        $db = DB::app();
        $v = self::find($db, (int) $req->params['id']);
        $id = self::recordAttempt($db, $_POST + ['voter_id' => $v['id']], (int) Auth::orgId(), (int) Auth::userId());
        Flash::set($id ? 'success' : 'error', $id ? 'Contact recorded.' : 'Could not record that contact.');
        redirect((string) ($req->post('back') ?: '/voters/' . $v['id']));
    }

    public function tag(Request $req): void
    {
        $db = DB::app();
        $v = self::find($db, (int) $req->params['id']);
        $tag = strtolower(preg_replace('/[^a-z0-9 _-]/i', '', $req->str('tag')) ?? '');
        if ($req->bool('remove')) {
            $db->exec('DELETE FROM voter_tags WHERE voter_id = :v AND tag = :t', [':v' => (int) $v['id'], ':t' => $tag]);
        } elseif ($tag !== '') {
            $db->exec('INSERT INTO voter_tags (org_id, voter_id, tag, created_by) VALUES (:o, :v, :t, :u) ON CONFLICT DO NOTHING', [':o' => Auth::orgId(), ':v' => (int) $v['id'], ':t' => substr($tag, 0, 40), ':u' => Auth::userId()]);
        }
        redirect('/voters/' . $v['id']);
    }

    public function note(Request $req): void
    {
        $db = DB::app();
        $v = self::find($db, (int) $req->params['id']);
        $body = $req->str('body');
        if ($body !== '') {
            $db->insert('notes', ['org_id' => Auth::orgId(), 'voter_id' => (int) $v['id'], 'user_id' => Auth::userId(), 'body' => substr($body, 0, 4000)]);
        }
        redirect('/voters/' . $v['id']);
    }
}
