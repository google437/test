<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\View;

final class CanvassController
{
    public function index(Request $req): void
    {
        $db = DB::app();
        $mine = $db->all("SELECT t.*, l.name AS list_name, (SELECT count(*) FROM contact_attempts ca WHERE ca.turf_id = t.id) AS attempts FROM turfs t JOIN lists l ON l.id = t.list_id WHERE t.assigned_user_id = :u AND t.status <> 'done' ORDER BY t.id", [':u' => Auth::userId()]);
        $unassigned = Auth::hasRole('organizer') ? $db->all("SELECT t.*, l.name AS list_name FROM turfs t JOIN lists l ON l.id = t.list_id WHERE t.assigned_user_id IS NULL AND t.status <> 'done' ORDER BY t.id LIMIT 50", []) : [];
        View::render('canvass/index', ['title' => 'Canvass', 'mine' => $mine, 'unassigned' => $unassigned], 'layout_mobile');
    }

    public function turf(Request $req): void
    {
        $db = DB::app();
        $turf = TurfController::find($db, (int) $req->params['id']);
        View::render('canvass/turf', ['title' => $turf['name'], 'turf' => $turf, 'mapStyle' => \App\Core\Config::get('MAP_STYLE_URL', 'https://tiles.openfreemap.org/styles/liberty')], 'layout_mobile');
    }
}
