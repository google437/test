<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Flash;
use App\Core\Request;
use App\Core\View;
use App\Services\Ingest\Elections;

/** Envision staff console: import runs, org onboarding, users, audit log, election windows. */
final class AdminController
{
    public function index(Request $req): void
    {
        $db = DB::app();
        $health = $db->all("SELECT s.source, s.last_attempt_at, s.last_change_at, r.status AS last_status, r.rows_total, r.finished_at, r.hold_reason
            FROM source_state s LEFT JOIN import_runs r ON r.id = s.last_run_id ORDER BY s.source");
        $held = $db->all("SELECT * FROM import_runs WHERE status IN ('held','approved') ORDER BY id DESC");
        $counts = $db->one("SELECT (SELECT count(*) FROM voters WHERE status <> 'removed') AS voters, (SELECT count(*) FROM voters WHERE status = 'removed') AS removed,
            (SELECT count(*) FROM households) AS households, (SELECT count(*) FROM households WHERE lat IS NOT NULL) AS geocoded,
            (SELECT count(*) FROM orgs WHERE status = 'active') AS orgs, (SELECT count(*) FROM users WHERE status = 'active') AS users,
            (SELECT count(*) FROM exports WHERE created_at > now() - interval '7 days') AS exports_week,
            (SELECT count(*) FROM import_runs WHERE status = 'failed' AND started_at > now() - interval '7 days') AS failed_week");
        $recent = $db->all('SELECT * FROM import_runs ORDER BY id DESC LIMIT 10');
        $orgs = $db->all("SELECT id, name, status FROM orgs ORDER BY name");
        $published = $db->val("SELECT value FROM settings WHERE key = 'clark_published_total'");
        View::render('admin/index', compact('health', 'held', 'counts', 'recent', 'orgs', 'published') + ['title' => 'Admin console']);
    }

    public function imports(Request $req): void
    {
        $db = DB::app();
        $source = $req->str('source');
        $p = [':lim' => 100];
        $w = '1=1';
        if ($source !== '') {
            $w .= ' AND source = :s';
            $p[':s'] = $source;
        }
        $runs = $db->all("SELECT * FROM import_runs WHERE $w ORDER BY id DESC LIMIT :lim", $p);
        $sources = $db->all('SELECT DISTINCT source FROM import_runs ORDER BY source');
        View::render('admin/imports', ['title' => 'Import runs', 'runs' => $runs, 'sources' => $sources, 'source' => $source]);
    }

    public function importShow(Request $req): void
    {
        $db = DB::app();
        $run = $db->one('SELECT * FROM import_runs WHERE id = :id', [':id' => (int) $req->params['id']]);
        if (!$run) {
            redirect('/admin/imports');
        }
        $errors = $db->all('SELECT * FROM import_errors WHERE run_id = :r ORDER BY id LIMIT 200', [':r' => (int) $run['id']]);
        $errorCount = (int) $db->val('SELECT count(*) FROM import_errors WHERE run_id = :r', [':r' => (int) $run['id']]);
        $changes = $db->all('SELECT field, count(*) AS n FROM voter_changes WHERE run_id = :r GROUP BY field ORDER BY n DESC', [':r' => (int) $run['id']]);
        $approver = $run['approved_by'] ? $db->val('SELECT name FROM users WHERE id = :u', [':u' => (int) $run['approved_by']]) : null;
        View::render('admin/import', ['title' => 'Import run #' . $run['id'], 'run' => $run, 'errors' => $errors, 'errorCount' => $errorCount, 'changes' => $changes, 'approver' => $approver, 'summary' => json_decode((string) $run['summary'], true) ?: []]);
    }

    /** Approval marks the run; the scheduler (owner connection) applies it within 15 minutes. */
    public function approve(Request $req): void
    {
        $db = DB::app();
        $n = $db->exec("UPDATE import_runs SET status = 'approved', approved_by = :u WHERE id = :id AND status = 'held'", [':u' => Auth::userId(), ':id' => (int) $req->params['id']]);
        Audit::log('import.approve', ['run_id' => (int) $req->params['id']]);
        Flash::set($n ? 'success' : 'error', $n ? 'Run approved. The ingestion worker will apply it on its next pass (within 15 minutes), or run: php bin/ingest run-due' : 'Run is not held.');
        redirect('/admin/imports/' . (int) $req->params['id']);
    }

    public function reject(Request $req): void
    {
        $db = DB::app();
        $db->exec("UPDATE import_runs SET status = 'rejected', approved_by = :u, finished_at = now() WHERE id = :id AND status IN ('held','approved')", [':u' => Auth::userId(), ':id' => (int) $req->params['id']]);
        Audit::log('import.reject', ['run_id' => (int) $req->params['id']]);
        Flash::set('success', 'Run rejected. Staged rows are discarded on the worker\'s next pass.');
        redirect('/admin/imports/' . (int) $req->params['id']);
    }

    public function orgs(Request $req): void
    {
        $db = DB::app();
        $orgs = $db->all("SELECT o.*, (SELECT count(*) FROM memberships m WHERE m.org_id = o.id) AS members,
            (SELECT string_agg(d.type || ' ' || d.name, ', ' ORDER BY d.type, d.name) FROM org_districts od JOIN districts d ON d.id = od.district_id WHERE od.org_id = o.id) AS districts
            FROM orgs o ORDER BY o.status, o.name");
        View::render('admin/orgs', ['title' => 'Organizations', 'orgs' => $orgs]);
    }

    public function orgForm(Request $req): void
    {
        $db = DB::app();
        $org = isset($req->params['id']) ? $db->one('SELECT * FROM orgs WHERE id = :id', [':id' => (int) $req->params['id']]) : ['id' => null, 'name' => '', 'type' => 'candidate_committee', 'committee_id' => '', 'use_attestation_at' => null, 'attested_by' => '', 'contract_start' => date('Y-m-d'), 'contract_end' => '', 'status' => 'active', 'data_retention_until' => ''];
        if (!$org) {
            redirect('/admin/orgs');
        }
        $districts = $db->all("SELECT d.*, (SELECT count(*) FROM precinct_districts pd JOIN voters v ON v.precinct_id = pd.precinct_id WHERE pd.district_id = d.id AND v.status <> 'removed') AS voters FROM districts d ORDER BY d.type, length(d.name), d.name");
        $licensed = $org['id'] ? array_map('intval', array_column($db->all('SELECT district_id FROM org_districts WHERE org_id = :o', [':o' => (int) $org['id']]), 'district_id')) : [];
        $members = $org['id'] ? $db->all('SELECT u.id, u.email, u.name, u.mfa_enabled, m.role FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.org_id = :o ORDER BY u.name', [':o' => (int) $org['id']]) : [];
        View::render('admin/org_form', ['title' => $org['id'] ? 'Edit organization' : 'New organization', 'org' => $org, 'districts' => $districts, 'licensed' => $licensed, 'members' => $members]);
    }

    public function orgStore(Request $req): void
    {
        $db = DB::app();
        $id = isset($req->params['id']) ? (int) $req->params['id'] : null;
        $data = [
            'name' => $req->str('name'), 'type' => $req->str('type'), 'committee_id' => $req->str('committee_id') ?: null,
            'attested_by' => $req->str('attested_by') ?: null, 'contract_start' => $req->str('contract_start') ?: null, 'contract_end' => $req->str('contract_end') ?: null,
            'status' => in_array($req->str('status'), ['active', 'ended', 'archived'], true) ? $req->str('status') : 'active', 'data_retention_until' => $req->str('data_retention_until') ?: null,
        ];
        if ($data['name'] === '') {
            Flash::set('error', 'Name is required.');
            redirect($id ? "/admin/orgs/$id" : '/admin/orgs/new');
        }
        $db->tx(function (DB $db) use (&$id, $data, $req) {
            if ($id) {
                $db->update('orgs', $data, 'id = :id', [':id' => $id]);
            } else {
                $id = (int) $db->insert('orgs', $data);
            }
            if ($req->bool('attest')) {
                $db->exec('UPDATE orgs SET use_attestation_at = COALESCE(use_attestation_at, now()) WHERE id = :id', [':id' => $id]);
            } elseif ($req->bool('clear_attest')) {
                $db->exec('UPDATE orgs SET use_attestation_at = NULL WHERE id = :id', [':id' => $id]);
            }
            $db->exec('DELETE FROM org_districts WHERE org_id = :o', [':o' => $id]);
            foreach (array_unique(array_map('intval', $req->arr('district_ids'))) as $d) {
                $db->exec('INSERT INTO org_districts (org_id, district_id) VALUES (:o, :d)', [':o' => $id, ':d' => $d]);
            }
        });
        Audit::log('org.save', ['org_id' => $id, 'districts' => array_map('intval', $req->arr('district_ids')), 'status' => $data['status']], $id);
        Flash::set('success', 'Organization saved.');
        redirect("/admin/orgs/$id");
    }

    public function users(Request $req): void
    {
        $db = DB::app();
        $users = $db->all("SELECT u.*, (SELECT string_agg(o.name || ' (' || m.role || ')', ', ') FROM memberships m JOIN orgs o ON o.id = m.org_id WHERE m.user_id = u.id) AS orgs FROM users u ORDER BY u.is_staff DESC, u.name");
        $orgs = $db->all("SELECT id, name FROM orgs WHERE status = 'active' ORDER BY name");
        View::render('admin/users', ['title' => 'Users', 'users' => $users, 'orgs' => $orgs, 'roles' => array_keys(Auth::ROLES)]);
    }

    public function userStore(Request $req): void
    {
        $db = DB::app();
        $email = strtolower($req->str('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Enter a valid email address.');
            redirect('/admin/users');
        }
        $user = $db->one('SELECT * FROM users WHERE email = :e', [':e' => $email]);
        $temp = null;
        if (!$user) {
            $temp = bin2hex(random_bytes(6));
            $uid = (int) $db->insert('users', ['email' => $email, 'name' => $req->str('name') ?: $email, 'password_hash' => password_hash($temp, PASSWORD_DEFAULT), 'is_staff' => $req->bool('is_staff'), 'must_change_password' => true]);
        } else {
            $uid = (int) $user['id'];
            if ($req->bool('is_staff')) {
                $db->exec('UPDATE users SET is_staff = true WHERE id = :id', [':id' => $uid]);
            }
        }
        if ($req->int('org_id')) {
            $role = isset(Auth::ROLES[$req->str('role')]) ? $req->str('role') : 'volunteer';
            $db->exec('INSERT INTO memberships (org_id, user_id, role) VALUES (:o, :u, :r) ON CONFLICT (org_id, user_id) DO UPDATE SET role = :r', [':o' => $req->int('org_id'), ':u' => $uid, ':r' => $role]);
        }
        Audit::log('user.save', ['user_id' => $uid, 'org_id' => $req->int('org_id'), 'staff' => $req->bool('is_staff')]);
        Flash::set('success', $temp ? "User created. Temporary password (shown once): $temp" : 'User updated.');
        redirect('/admin/users');
    }

    public function userToggle(Request $req): void
    {
        $db = DB::app();
        $uid = (int) $req->params['id'];
        if ($uid !== Auth::userId()) {
            $db->exec("UPDATE users SET status = CASE WHEN status = 'active' THEN 'disabled' ELSE 'active' END WHERE id = :id", [':id' => $uid]);
            Auth::revokeAll($uid);
            Audit::log('user.toggle', ['user_id' => $uid]);
        }
        redirect('/admin/users');
    }

    public function userResetMfa(Request $req): void
    {
        $uid = (int) $req->params['id'];
        DB::app()->exec('UPDATE users SET totp_secret_enc = NULL, mfa_enabled = false WHERE id = :id', [':id' => $uid]);
        Auth::revokeAll($uid);
        Audit::log('user.reset_mfa', ['user_id' => $uid]);
        Flash::set('success', 'MFA reset; the user will enroll again at next login.');
        redirect('/admin/users');
    }

    public function audit(Request $req): void
    {
        $db = DB::app();
        $p = [':lim' => 200];
        $w = ['1=1'];
        if ($req->str('action') !== '') {
            $w[] = 'a.action LIKE :act';
            $p[':act'] = $req->str('action') . '%';
        }
        if ($req->int('org_id')) {
            $w[] = 'a.org_id = :o';
            $p[':o'] = $req->int('org_id');
        }
        if ($req->int('user_id')) {
            $w[] = 'a.user_id = :u';
            $p[':u'] = $req->int('user_id');
        }
        $rows = $db->all('SELECT a.*, u.email, o.name AS org_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN orgs o ON o.id = a.org_id WHERE ' . implode(' AND ', $w) . ' ORDER BY a.id DESC LIMIT :lim', $p);
        $exports = $db->all('SELECT e.*, u.email, o.name AS org_name FROM exports e LEFT JOIN users u ON u.id = e.user_id LEFT JOIN orgs o ON o.id = e.org_id ORDER BY e.id DESC LIMIT 50');
        View::render('admin/audit', ['title' => 'Audit log', 'rows' => $rows, 'exports' => $exports, 'orgs' => $db->all('SELECT id, name FROM orgs ORDER BY name')]);
    }

    public function windows(Request $req): void
    {
        $db = DB::app();
        View::render('admin/windows', ['title' => 'Election windows', 'windows' => $db->all('SELECT * FROM election_windows ORDER BY election_date DESC'), 'elections' => $db->all('SELECT * FROM elections ORDER BY election_date DESC')]);
    }

    public function windowStore(Request $req): void
    {
        $db = DB::app();
        $code = Elections::normalize($req->str('election_code'));
        if (!Elections::isValid($code) || $req->str('election_date') === '') {
            Flash::set('error', 'Election code (like 26G) and election date are required.');
            redirect('/admin/windows');
        }
        $db->tx(function (DB $db) use ($req, $code) {
            if ($req->bool('is_current')) {
                $db->exec('UPDATE election_windows SET is_current = false');
            }
            $db->insert('election_windows', ['name' => $req->str('name') ?: $code, 'election_code' => $code, 'election_date' => $req->str('election_date'), 'registration_close' => $req->str('registration_close') ?: null,
                'early_vote_start' => $req->str('early_vote_start') ?: null, 'early_vote_end' => $req->str('early_vote_end') ?: null, 'is_current' => $req->bool('is_current')]);
            $db->exec('INSERT INTO elections (code, election_date, type, name) VALUES (:c, :d, :t, :n) ON CONFLICT (code) DO UPDATE SET election_date = :d', [':c' => $code, ':d' => $req->str('election_date'), ':t' => $code[2] === 'P' ? 'primary' : 'general', ':n' => $req->str('name') ?: $code]);
        });
        Audit::log('window.save', ['code' => $code]);
        Flash::set('success', 'Election window saved. Current-election flags refresh on the worker\'s next enrich pass.');
        redirect('/admin/windows');
    }

    public function settingsStore(Request $req): void
    {
        $db = DB::app();
        foreach (['clark_published_total'] as $k) {
            $v = $req->str($k);
            $db->exec('INSERT INTO settings (key, value) VALUES (:k, :v) ON CONFLICT (key) DO UPDATE SET value = :v, updated_at = now()', [':k' => $k, ':v' => $v === '' ? null : preg_replace('/\D/', '', $v)]);
        }
        Audit::log('settings.save', ['keys' => ['clark_published_total']]);
        redirect('/admin');
    }
}
