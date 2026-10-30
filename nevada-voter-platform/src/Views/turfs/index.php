<div class="page-head"><h1><?= e($list['name']) ?>: turfs</h1><div class="actions"><a class="btn" href="/lists/<?= (int) $list['id'] ?>">Back to list</a><a class="btn" href="/lists/<?= (int) $list['id'] ?>/map">Draw on map</a></div></div>
<?php if (App\Core\Auth::hasRole('organizer')): ?>
<form method="post" action="/lists/<?= (int) $list['id'] ?>/turfs/auto" class="card inline-form"><?= csrf_field() ?>
  <strong>Auto-split</strong>
  <label>About <input type="number" name="doors" value="40" min="5" max="500" style="width:5em"> doors per turf</label>
  <label>Script <select name="script_id"><option value="">Default</option><?php foreach ($scripts as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></label>
  <button class="btn primary">Create turfs</button>
  <span class="muted small">Neighbouring households are grouped by location (or by street when not geocoded).</span>
</form>
<?php endif; ?>
<table class="tbl"><thead><tr><th>Turf</th><th class="r">Doors</th><th class="r">Voters</th><th class="r">Attempts</th><th>Status</th><th>Assigned to</th><th></th></tr></thead><tbody>
<?php foreach ($turfs as $t): ?>
<tr><td><a href="/turfs/<?= (int) $t['id'] ?>"><?= e($t['name']) ?></a><?= $t['geom_geojson'] ? ' <span class="muted small">(drawn)</span>' : '' ?></td><td class="r"><?= (int) $t['door_count'] ?></td><td class="r"><?= (int) $t['voter_count'] ?></td><td class="r"><?= (int) $t['attempts'] ?></td><td><?= e(result_label($t['status'])) ?></td>
<td><?php if (App\Core\Auth::hasRole('organizer')): ?><form method="post" action="/turfs/<?= (int) $t['id'] ?>/assign" class="inline"><?= csrf_field() ?><input type="hidden" name="back" value="/lists/<?= (int) $list['id'] ?>/turfs"><input type="hidden" name="script_id" value="<?= (int) $t['script_id'] ?>">
  <select name="user_id" data-autosubmit><option value="">— unassigned —</option><?php foreach ($members as $m): ?><option value="<?= (int) $m['id'] ?>" <?= (int) $t['assigned_user_id'] === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?> (<?= e($m['role']) ?>)</option><?php endforeach; ?></select></form><?php else: echo e((string) $t['assignee']); endif; ?></td>
<td><a class="btn small" href="/turfs/<?= (int) $t['id'] ?>/walksheet" target="_blank">Walk sheet</a> <a class="btn small" href="/canvass/turf/<?= (int) $t['id'] ?>">Canvass</a></td></tr>
<?php endforeach; ?>
<?php if (!$turfs): ?><tr><td colspan="7" class="muted">No turfs yet.</td></tr><?php endif; ?>
</tbody></table>
