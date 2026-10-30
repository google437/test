<div class="page-head"><h1><?= e($turf['name']) ?> <span class="pill"><?= e(result_label($turf['status'])) ?></span></h1>
  <div class="actions"><a class="btn" href="/lists/<?= (int) $turf['list_id'] ?>/turfs">All turfs</a><a class="btn" href="/turfs/<?= (int) $turf['id'] ?>/walksheet" target="_blank">Print walk sheet</a><a class="btn primary" href="/canvass/turf/<?= (int) $turf['id'] ?>">Open in canvass app</a>
  <?php if (App\Core\Auth::hasRole('organizer')): ?><form method="post" action="/turfs/<?= (int) $turf['id'] ?>/delete" class="inline" data-confirm='Delete this turf?'><?= csrf_field() ?><button class="btn danger">Delete</button></form><?php endif; ?></div></div>
<div class="tiles small"><div class="tile"><div class="n"><?= (int) $turf['door_count'] ?></div><div class="l">Doors</div></div><div class="tile"><div class="n"><?= (int) $turf['voter_count'] ?></div><div class="l">Voters</div></div><div class="tile"><div class="n"><?= (int) $attempts['n'] ?></div><div class="l">Attempts</div></div><div class="tile"><div class="n"><?= (int) $attempts['c'] ?></div><div class="l">Conversations</div></div></div>
<div class="cols">
<section class="card">
  <h2>Assignment</h2>
  <?php if (App\Core\Auth::hasRole('organizer')): ?>
  <form method="post" action="/turfs/<?= (int) $turf['id'] ?>/assign" class="inline-form"><?= csrf_field() ?><input type="hidden" name="script_id" value="<?= (int) $turf['script_id'] ?>">
    <label>Canvasser <select name="user_id"><option value="">— unassigned —</option><?php foreach ($members as $m): ?><option value="<?= (int) $m['id'] ?>" <?= (int) $turf['assigned_user_id'] === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?></select></label><button class="btn">Assign</button></form>
  <?php else: ?><p>Assigned to <?= e($turf['assignee'] ?: 'nobody') ?>.</p><?php endif; ?>
  <form method="post" action="/turfs/<?= (int) $turf['id'] ?>/status" class="inline-form"><?= csrf_field() ?><label>Status <select name="status"><?php foreach (['open', 'in_progress', 'done'] as $s): ?><option value="<?= $s ?>" <?= $turf['status'] === $s ? 'selected' : '' ?>><?= result_label($s) ?></option><?php endforeach; ?></select></label><button class="btn">Update</button></form>
</section>
<section class="card"><h2>Map</h2><div id="map" class="map small" data-style="<?= e($mapStyle) ?>" data-turf="/api/turfs/<?= (int) $turf['id'] ?>"></div></section>
</div>
<table class="tbl"><thead><tr><th>#</th><th>Address</th><th>Voters</th><th>Last result</th></tr></thead><tbody>
<?php foreach ($walk as $h): ?><tr><td><?= (int) $h['walk_order'] ?></td><td><?= e((string) $h['address_line']) ?>, <?= e((string) $h['city']) ?></td>
<td><?php foreach ($h['voters'] as $vv): ?><a href="/voters/<?= (int) $vv['id'] ?>"><?= e($vv['name']) ?></a> <span class="muted small"><?= e((string) $vv['party']) ?>, <?= e((string) $vv['age']) ?><?= $vv['voted'] ? ', voted' : '' ?></span><br><?php endforeach; ?></td>
<td><?php foreach ($h['voters'] as $vv): ?><?= e(result_label($vv['last_result'] ?? '')) ?><br><?php endforeach; ?></td></tr><?php endforeach; ?>
</tbody></table>
<link rel="stylesheet" href="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css"><script src="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js"></script><script src="/assets/map.js" defer></script>
