<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Flash;
use App\Core\Request;
use App\Core\View;

final class OrgSettingsController
{
    public function surveys(Request $req): void
    {
        $db = DB::app();
        View::render('settings/surveys', ['title' => 'Survey questions', 'questions' => $db->all('SELECT q.*, (SELECT count(*) FROM survey_responses r WHERE r.question_id = q.id) AS responses FROM survey_questions q ORDER BY q.sort_order, q.id')]);
    }

    public function surveyStore(Request $req): void
    {
        $db = DB::app();
        $type = in_array($req->str('qtype'), ['scale', 'choice', 'yesno', 'text'], true) ? $req->str('qtype') : 'choice';
        $options = match ($type) {
            'scale' => ['1', '2', '3', '4', '5'],
            'yesno' => ['Yes', 'No'],
            'text' => [],
            default => array_values(array_filter(array_map('trim', explode("\n", $req->str('options'))))),
        };
        if ($req->str('question') !== '') {
            $db->insert('survey_questions', ['org_id' => Auth::orgId(), 'question' => substr($req->str('question'), 0, 300), 'qtype' => $type, 'options' => json_encode($options), 'sort_order' => $req->int('sort_order', 0)]);
            Audit::log('survey.question_create', ['question' => $req->str('question')]);
        }
        redirect('/settings/surveys');
    }

    public function surveyToggle(Request $req): void
    {
        DB::app()->exec('UPDATE survey_questions SET active = NOT active WHERE id = :id', [':id' => (int) $req->params['id']]);
        redirect('/settings/surveys');
    }

    public function scripts(Request $req): void
    {
        View::render('settings/scripts', ['title' => 'Scripts', 'scripts' => DB::app()->all('SELECT * FROM scripts ORDER BY is_default DESC, name')]);
    }

    public function scriptStore(Request $req): void
    {
        $db = DB::app();
        if ($req->str('name') !== '' && $req->str('body') !== '') {
            if ($req->bool('is_default')) {
                $db->exec('UPDATE scripts SET is_default = false');
            }
            $db->insert('scripts', ['org_id' => Auth::orgId(), 'name' => substr($req->str('name'), 0, 100), 'channel' => in_array($req->str('channel'), ['door', 'phone', 'any'], true) ? $req->str('channel') : 'any', 'body' => substr($req->str('body'), 0, 10000), 'is_default' => $req->bool('is_default')]);
        }
        redirect('/settings/scripts');
    }

    public function scriptDelete(Request $req): void
    {
        DB::app()->exec('DELETE FROM scripts WHERE id = :id', [':id' => (int) $req->params['id']]);
        redirect('/settings/scripts');
    }

    public function team(Request $req): void
    {
        $db = DB::app();
        $members = $db->all('SELECT u.id, u.email, u.name, u.mfa_enabled, u.status, u.last_login_at, m.role FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.org_id = :o ORDER BY m.role, u.name', [':o' => Auth::orgId()]);
        View::render('settings/team', ['title' => 'Team', 'members' => $members, 'roles' => array_keys(Auth::ROLES)]);
    }

    /** Org admins add their own volunteers; staff can also do this from the admin console. */
    public function teamStore(Request $req): void
    {
        $db = DB::app();
        $email = strtolower($req->str('email'));
        $role = isset(Auth::ROLES[$req->str('role')]) ? $req->str('role') : 'volunteer';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Enter a valid email address.');
            redirect('/settings/team');
        }
        $user = $db->one('SELECT * FROM users WHERE email = :e', [':e' => $email]);
        $tempPassword = null;
        if (!$user) {
            $tempPassword = bin2hex(random_bytes(6));
            $uid = (int) $db->insert('users', ['email' => $email, 'name' => $req->str('name') ?: $email, 'password_hash' => password_hash($tempPassword, PASSWORD_DEFAULT), 'must_change_password' => true]);
        } else {
            $uid = (int) $user['id'];
        }
        $db->exec('INSERT INTO memberships (org_id, user_id, role) VALUES (:o, :u, :r) ON CONFLICT (org_id, user_id) DO UPDATE SET role = :r', [':o' => Auth::orgId(), ':u' => $uid, ':r' => $role]);
        Audit::log('team.add', ['user_id' => $uid, 'role' => $role]);
        Flash::set('success', $tempPassword ? "Account created. Temporary password (share securely, shown once): $tempPassword" : 'Member added.');
        redirect('/settings/team');
    }

    public function teamRemove(Request $req): void
    {
        $db = DB::app();
        $uid = (int) $req->params['id'];
        if ($uid !== Auth::userId()) {
            $db->exec('DELETE FROM memberships WHERE org_id = :o AND user_id = :u', [':o' => Auth::orgId(), ':u' => $uid]);
            Audit::log('team.remove', ['user_id' => $uid]);
        }
        redirect('/settings/team');
    }
}
