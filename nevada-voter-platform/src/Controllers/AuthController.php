<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Flash;
use App\Core\RateLimit;
use App\Core\Request;
use App\Core\Totp;
use App\Core\View;

final class AuthController
{
    public function loginForm(Request $req): void
    {
        if (Auth::user() && Auth::mfaPassed()) {
            redirect('/');
        }
        View::render('auth/login', ['title' => 'Sign in', 'next' => (string) $req->get('next', '/')], 'layout_auth');
    }

    public function login(Request $req): void
    {
        $email = strtolower($req->str('email'));
        $password = (string) $req->post('password', '');
        if (!RateLimit::allow('login:' . $req->ip(), 10, 600) || !RateLimit::allow('login:' . $email, 8, 600)) {
            Flash::set('error', 'Too many attempts. Wait a few minutes and try again.');
            redirect('/login');
        }
        $db = DB::app();
        $user = $db->one("SELECT * FROM users WHERE email = :e AND status = 'active'", [':e' => $email]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            Audit::log('auth.failed', ['email_hash' => substr(hash('sha256', $email), 0, 12)], null, null);
            Flash::set('error', 'Email or password is incorrect.');
            redirect('/login');
        }
        Auth::startSession($user, $req);
        $next = (string) $req->post('next', '/');
        if (!str_starts_with($next, '/') || str_starts_with($next, '//')) {
            $next = '/';
        }
        setcookie('nvvp_next', $next, ['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        redirect($user['mfa_enabled'] ? '/mfa' : '/mfa/setup');
    }

    public function mfaForm(Request $req): void
    {
        $u = Auth::user();
        if (!$u['mfa_enabled']) {
            redirect('/mfa/setup');
        }
        View::render('auth/mfa', ['title' => 'Two-factor code'], 'layout_auth');
    }

    public function mfa(Request $req): void
    {
        $u = Auth::user();
        if (!RateLimit::allow('mfa:' . $u['id'], 6, 300)) {
            Flash::set('error', 'Too many codes tried. Wait five minutes.');
            redirect('/mfa');
        }
        $secret = Crypto::decrypt((string) $u['totp_secret_enc']);
        if (!Totp::verify($secret, $req->str('code'))) {
            Flash::set('error', 'That code did not match. Try the next one from your app.');
            redirect('/mfa');
        }
        Auth::passMfa();
        Audit::log('auth.login', []);
        $next = $_COOKIE['nvvp_next'] ?? '/';
        setcookie('nvvp_next', '', ['path' => '/', 'expires' => 1]);
        redirect(str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : '/');
    }

    public function mfaSetupForm(Request $req): void
    {
        $u = Auth::user();
        if ($u['mfa_enabled']) {
            redirect('/mfa');
        }
        // Pending secret kept in a signed cookie until confirmed.
        $secret = Totp::generateSecret();
        $sig = Crypto::sign('mfa|' . $secret . '|' . $u['id']);
        setcookie('nvvp_mfa_pending', $secret . '.' . $sig, ['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => Auth::secureCookies()]);
        View::render('auth/mfa_setup', ['title' => 'Set up two-factor authentication', 'secret' => $secret, 'uri' => Totp::uri($secret, $u['email'], Config::get('APP_NAME', 'NV Voter Platform'))], 'layout_auth');
    }

    public function mfaSetup(Request $req): void
    {
        $u = Auth::user();
        [$secret, $sig] = array_pad(explode('.', (string) ($_COOKIE['nvvp_mfa_pending'] ?? ''), 2), 2, '');
        if (!$secret || !hash_equals(Crypto::sign('mfa|' . $secret . '|' . $u['id']), $sig)) {
            Flash::set('error', 'Setup expired. Start again.');
            redirect('/mfa/setup');
        }
        if (!Totp::verify($secret, $req->str('code'))) {
            Flash::set('error', 'Code did not match. Scan the QR code again and enter the current code.');
            redirect('/mfa/setup');
        }
        DB::app()->exec('UPDATE users SET totp_secret_enc = :s, mfa_enabled = true WHERE id = :id', [':s' => Crypto::encrypt($secret), ':id' => (int) $u['id']]);
        setcookie('nvvp_mfa_pending', '', ['path' => '/', 'expires' => 1]);
        Auth::passMfa();
        Audit::log('auth.mfa_enrolled', []);
        Flash::set('success', 'Two-factor authentication is on.');
        redirect('/');
    }

    public function logout(Request $req): void
    {
        Audit::log('auth.logout', []);
        Auth::logout();
        redirect('/login');
    }

    public function noOrg(Request $req): void
    {
        View::render('auth/no_org', ['title' => 'No active campaign'], 'layout_auth');
    }

    public function switchOrg(Request $req): void
    {
        if (Auth::switchOrg((int) $req->post('org_id'))) {
            Audit::log('auth.switch_org', ['org_id' => (int) $req->post('org_id')]);
        }
        redirect('/');
    }

    public function settingsMfa(Request $req): void
    {
        View::render('settings/mfa', ['title' => 'Security']);
    }

    public function settingsMfaReset(Request $req): void
    {
        $u = Auth::user();
        if (!password_verify((string) $req->post('password', ''), $u['password_hash'])) {
            Flash::set('error', 'Password is incorrect.');
            redirect('/settings/mfa');
        }
        DB::app()->exec('UPDATE users SET totp_secret_enc = NULL, mfa_enabled = false WHERE id = :id', [':id' => (int) $u['id']]);
        Audit::log('auth.mfa_reset', []);
        Auth::revokeAll((int) $u['id']);
        redirect('/login');
    }
}
