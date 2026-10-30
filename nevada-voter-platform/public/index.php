<?php
declare(strict_types=1);

// Built-in server (php -S ... public/index.php): serve real files directly.
if (PHP_SAPI === 'cli-server') {
    $f = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($f)) {
        return false;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Controllers\AdminController;
use App\Controllers\ApiController;
use App\Controllers\AuthController;
use App\Controllers\CanvassController;
use App\Controllers\DashboardController;
use App\Controllers\ListController;
use App\Controllers\OrgSettingsController;
use App\Controllers\PhoneController;
use App\Controllers\TurfController;
use App\Controllers\VoterController;
use App\Core\Request;
use App\Core\Router;

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com https://unpkg.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://unpkg.com; img-src 'self' data: blob: https:; connect-src 'self' https://tiles.openfreemap.org https://*.openfreemap.org; worker-src 'self' blob:; manifest-src 'self'; frame-ancestors 'none'");

$r = new Router();
$none = ['auth' => 'none'];
$pending = ['auth' => 'mfa-pending'];
$staff = ['auth' => 'staff'];
$api = ['csrf' => false];

// Auth
$r->get('/login', [AuthController::class, 'loginForm'], $none);
$r->post('/login', [AuthController::class, 'login'], $none);
$r->get('/mfa', [AuthController::class, 'mfaForm'], $pending);
$r->post('/mfa', [AuthController::class, 'mfa'], $pending);
$r->get('/mfa/setup', [AuthController::class, 'mfaSetupForm'], $pending);
$r->post('/mfa/setup', [AuthController::class, 'mfaSetup'], $pending);
$r->post('/logout', [AuthController::class, 'logout'], $pending);
$r->get('/no-org', [AuthController::class, 'noOrg'], $pending);
$r->post('/switch-org', [AuthController::class, 'switchOrg']);
$r->get('/settings/mfa', [AuthController::class, 'settingsMfa']);
$r->post('/settings/mfa', [AuthController::class, 'settingsMfaReset']);

// Dashboard and reports
$r->get('/', [DashboardController::class, 'index']);
$r->get('/reports', [DashboardController::class, 'reports']);
$r->get('/reports/survey.csv', [DashboardController::class, 'surveyExport'], ['role' => 'manager']);

// Lists
$r->get('/lists', [ListController::class, 'index']);
$r->get('/lists/new', [ListController::class, 'form'], ['role' => 'organizer']);
$r->post('/lists', [ListController::class, 'store'], ['role' => 'organizer']);
$r->get('/lists/{id}', [ListController::class, 'show']);
$r->get('/lists/{id}/edit', [ListController::class, 'form'], ['role' => 'organizer']);
$r->post('/lists/{id}', [ListController::class, 'update'], ['role' => 'organizer']);
$r->post('/lists/{id}/freeze', [ListController::class, 'freeze'], ['role' => 'organizer']);
$r->post('/lists/{id}/delete', [ListController::class, 'delete'], ['role' => 'manager']);
$r->get('/lists/{id}/export.csv', [ListController::class, 'export'], ['role' => 'manager']);
$r->get('/lists/{id}/map', [ListController::class, 'map']);
$r->get('/lists/{id}/turfs', [TurfController::class, 'index']);
$r->post('/lists/{id}/turfs/auto', [TurfController::class, 'autoSplit'], ['role' => 'organizer']);

// Turfs
$r->get('/turfs/{id}', [TurfController::class, 'show']);
$r->post('/turfs/{id}/assign', [TurfController::class, 'assign'], ['role' => 'organizer']);
$r->post('/turfs/{id}/status', [TurfController::class, 'status']);
$r->post('/turfs/{id}/delete', [TurfController::class, 'delete'], ['role' => 'organizer']);
$r->get('/turfs/{id}/walksheet', [TurfController::class, 'walksheet']);

// Voters
$r->get('/voters', [VoterController::class, 'search']);
$r->get('/voters/{id}', [VoterController::class, 'show']);
$r->post('/voters/{id}/contact', [VoterController::class, 'contact']);
$r->post('/voters/{id}/tag', [VoterController::class, 'tag']);
$r->post('/voters/{id}/note', [VoterController::class, 'note']);

// Canvass (PWA) and phone bank
$r->get('/canvass', [CanvassController::class, 'index']);
$r->get('/canvass/turf/{id}', [CanvassController::class, 'turf']);
$r->get('/phone', [PhoneController::class, 'index']);
$r->get('/phone/{list_id}', [PhoneController::class, 'next']);
$r->post('/phone/{list_id}/record', [PhoneController::class, 'record']);

// Org settings (surveys, scripts, team)
$r->get('/settings/surveys', [OrgSettingsController::class, 'surveys'], ['role' => 'manager']);
$r->post('/settings/surveys', [OrgSettingsController::class, 'surveyStore'], ['role' => 'manager']);
$r->post('/settings/surveys/{id}/toggle', [OrgSettingsController::class, 'surveyToggle'], ['role' => 'manager']);
$r->get('/settings/scripts', [OrgSettingsController::class, 'scripts'], ['role' => 'manager']);
$r->post('/settings/scripts', [OrgSettingsController::class, 'scriptStore'], ['role' => 'manager']);
$r->post('/settings/scripts/{id}/delete', [OrgSettingsController::class, 'scriptDelete'], ['role' => 'manager']);
$r->get('/settings/team', [OrgSettingsController::class, 'team'], ['role' => 'admin']);
$r->post('/settings/team', [OrgSettingsController::class, 'teamStore'], ['role' => 'admin']);
$r->post('/settings/team/{id}/remove', [OrgSettingsController::class, 'teamRemove'], ['role' => 'admin']);

// JSON API (session cookie + X-CSRF-Token header)
$r->post('/api/lists/count', [ApiController::class, 'count'], $api);
$r->get('/api/lists/{id}/geojson', [ApiController::class, 'listGeoJson']);
$r->post('/api/lists/{id}/turfs/polygon', [ApiController::class, 'turfFromPolygon'], $api + ['role' => 'organizer']);
$r->get('/api/turfs/{id}', [ApiController::class, 'turf']);
$r->post('/api/sync', [ApiController::class, 'sync'], $api);
$r->get('/api/precincts', [ApiController::class, 'precincts']);
$r->get('/api/me', [ApiController::class, 'me']);

// Admin console (Envision staff)
$r->get('/admin', [AdminController::class, 'index'], $staff);
$r->get('/admin/imports', [AdminController::class, 'imports'], $staff);
$r->get('/admin/imports/{id}', [AdminController::class, 'importShow'], $staff);
$r->post('/admin/imports/{id}/approve', [AdminController::class, 'approve'], $staff);
$r->post('/admin/imports/{id}/reject', [AdminController::class, 'reject'], $staff);
$r->get('/admin/orgs', [AdminController::class, 'orgs'], $staff);
$r->get('/admin/orgs/new', [AdminController::class, 'orgForm'], $staff);
$r->post('/admin/orgs', [AdminController::class, 'orgStore'], $staff);
$r->get('/admin/orgs/{id}', [AdminController::class, 'orgForm'], $staff);
$r->post('/admin/orgs/{id}', [AdminController::class, 'orgStore'], $staff);
$r->get('/admin/users', [AdminController::class, 'users'], $staff);
$r->post('/admin/users', [AdminController::class, 'userStore'], $staff);
$r->post('/admin/users/{id}/toggle', [AdminController::class, 'userToggle'], $staff);
$r->post('/admin/users/{id}/reset-mfa', [AdminController::class, 'userResetMfa'], $staff);
$r->get('/admin/audit', [AdminController::class, 'audit'], $staff);
$r->get('/admin/windows', [AdminController::class, 'windows'], $staff);
$r->post('/admin/windows', [AdminController::class, 'windowStore'], $staff);
$r->post('/admin/settings', [AdminController::class, 'settingsStore'], $staff);

$r->dispatch(new Request());
