<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Database-backed sessions (revocable, 12 hour lifetime), MFA gate, org context for RLS.
 */
final class Auth
{
    public const ROLES = ['volunteer' => 1, 'organizer' => 2, 'manager' => 3, 'admin' => 4];
    private static ?array $session = null;
    private static ?array $user = null;
    private static ?array $org = null;
    private static bool $loaded = false;

    public static function secureCookies(): bool
    {
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || str_starts_with((string) Config::get('APP_URL', ''), 'https://');
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        $sid = $_COOKIE['nvvp_sid'] ?? null;
        if (!$sid || !preg_match('/^[a-f0-9]{64}$/', $sid)) {
            return;
        }
        $db = DB::app();
        $s = $db->one('SELECT * FROM sessions WHERE id = :id AND revoked_at IS NULL AND expires_at > now()', [':id' => $sid]);
        if (!$s) {
            return;
        }
        $u = $db->one("SELECT * FROM users WHERE id = :id AND status = 'active'", [':id' => (int) $s['user_id']]);
        if (!$u) {
            return;
        }
        self::$session = $s;
        self::$user = $u;
        if ($s['org_id']) {
            self::$org = $db->one('SELECT * FROM orgs WHERE id = :id', [':id' => (int) $s['org_id']]);
            if (self::$org && self::$org['status'] !== 'active' && !$u['is_staff']) {
                self::$org = null; // access ends when the contract ends
            }
        }
        if (random_int(1, 20) === 1) {
            $db->exec('UPDATE sessions SET last_seen_at = now() WHERE id = :id', [':id' => $sid]);
        }
        $db->setContext(self::$org ? (int) self::$org['id'] : null, (int) $u['id'], (bool) $u['is_staff']);
    }

    public static function sessionId(): ?string { self::load(); return self::$session['id'] ?? null; }
    public static function user(): ?array { self::load(); return self::$user; }
    public static function org(): ?array { self::load(); return self::$org; }
    public static function userId(): ?int { $u = self::user(); return $u ? (int) $u['id'] : null; }
    public static function orgId(): ?int { $o = self::org(); return $o ? (int) $o['id'] : null; }
    public static function isStaff(): bool { $u = self::user(); return (bool) ($u['is_staff'] ?? false); }
    public static function mfaPassed(): bool { self::load(); return (bool) (self::$session['mfa_passed'] ?? false); }

    public static function role(): ?string
    {
        $u = self::user();
        $o = self::org();
        if (!$u || !$o) {
            return $u && $u['is_staff'] ? 'admin' : null;
        }
        if ($u['is_staff']) {
            return 'admin';
        }
        return DB::app()->val('SELECT role FROM memberships WHERE org_id = :o AND user_id = :u', [':o' => (int) $o['id'], ':u' => (int) $u['id']]);
    }

    public static function hasRole(string $min): bool
    {
        $r = self::role();
        return $r !== null && (self::ROLES[$r] ?? 0) >= (self::ROLES[$min] ?? 99);
    }

    public static function require(Request $req, string $level, ?string $role): void
    {
        $u = self::user();
        if (!$u) {
            if ($req->wantsJson()) {
                json_out(['error' => 'unauthenticated'], 401);
            }
            redirect('/login?next=' . rawurlencode($req->path));
        }
        if ($level === 'mfa-pending') {
            return;
        }
        if (!self::mfaPassed()) {
            if ($req->wantsJson()) {
                json_out(['error' => 'mfa_required'], 401);
            }
            redirect($u['mfa_enabled'] ? '/mfa' : '/mfa/setup');
        }
        if ($level === 'staff' && !$u['is_staff']) {
            self::deny($req);
        }
        if ($level === 'user' && !$u['is_staff'] && !self::org()) {
            // Member of no active org: show a friendly page rather than leaking anything.
            if ($req->path !== '/no-org' && $req->path !== '/logout' && $req->path !== '/settings/mfa') {
                redirect('/no-org');
            }
        }
        if ($role && !self::hasRole($role)) {
            self::deny($req);
        }
    }

    private static function deny(Request $req): never
    {
        if ($req->wantsJson()) {
            json_out(['error' => 'forbidden'], 403);
        }
        http_response_code(403);
        View::render('errors/403', ['title' => 'Not allowed']);
        exit;
    }

    /** Step 1 of login: password verified; MFA still pending. */
    public static function startSession(array $user, Request $req): string
    {
        $db = DB::app();
        $id = bin2hex(random_bytes(32));
        $orgId = null;
        if (!$user['is_staff']) {
            $orgId = $db->val("SELECT m.org_id FROM memberships m JOIN orgs o ON o.id = m.org_id WHERE m.user_id = :u AND o.status = 'active' ORDER BY m.org_id LIMIT 1", [':u' => (int) $user['id']]);
        }
        $hours = Config::int('SESSION_HOURS', 12);
        $db->insert('sessions', [
            'id' => $id, 'user_id' => (int) $user['id'], 'org_id' => $orgId ? (int) $orgId : null, 'mfa_passed' => false,
            'ip' => $req->ip(), 'user_agent' => $req->userAgent(), 'expires_at' => date('c', time() + $hours * 3600),
        ], '');
        setcookie('nvvp_sid', $id, ['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => self::secureCookies(), 'expires' => time() + $hours * 3600]);
        $_COOKIE['nvvp_sid'] = $id;
        self::$loaded = false;
        return $id;
    }

    public static function passMfa(): void
    {
        $sid = self::sessionId();
        if ($sid) {
            DB::app()->exec('UPDATE sessions SET mfa_passed = true WHERE id = :id', [':id' => $sid]);
            DB::app()->exec('UPDATE users SET last_login_at = now() WHERE id = :id', [':id' => self::userId()]);
            self::$session['mfa_passed'] = true;
        }
    }

    public static function switchOrg(int $orgId): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        $db = DB::app();
        $ok = $u['is_staff'] || $db->val("SELECT 1 FROM memberships m JOIN orgs o ON o.id = m.org_id WHERE m.user_id = :u AND m.org_id = :o AND o.status = 'active'", [':u' => (int) $u['id'], ':o' => $orgId]);
        if (!$ok) {
            return false;
        }
        $db->exec('UPDATE sessions SET org_id = :o WHERE id = :id', [':o' => $orgId, ':id' => self::sessionId()]);
        return true;
    }

    public static function logout(): void
    {
        $sid = self::sessionId();
        if ($sid) {
            DB::app()->exec('UPDATE sessions SET revoked_at = now() WHERE id = :id', [':id' => $sid]);
        }
        setcookie('nvvp_sid', '', ['path' => '/', 'expires' => 1]);
        self::$session = self::$user = self::$org = null;
    }

    public static function revokeAll(int $userId): void
    {
        DB::app()->exec('UPDATE sessions SET revoked_at = now() WHERE user_id = :u AND revoked_at IS NULL', [':u' => $userId]);
    }
}
