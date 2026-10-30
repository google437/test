<div class="page-head"><h1>Lists</h1><div class="actions"><?php if (App\Core\Auth::hasRole('organizer')): ?><a class="btn primary" href="/lists/new">New list</a><?php endif; ?></div></div>
<table class="tbl">
<thead><tr><th>Name</th><th class="r">Voters</th><th class="r">Doors</th><th>Type</th><th class="r">Turfs</th><th>Updated</th><th>By</th></tr></thead>
<tbody>
<?php foreach ($lists as $l): ?>
<tr><td><a href="/lists/<?= (int) $l['id'] ?>"><?= e($l['name']) ?></a></td><td class="r"><?= fmt_num($l['member_count']) ?></td><td class="r"><?= fmt_num($l['door_count']) ?></td>
<td><?= $l['is_frozen'] ? 'Snapshot' : 'Dynamic' ?><?= $l['chase_mode'] ? ' · chase' : '' ?></td><td class="r"><?= (int) $l['turf_count'] ?></td><td><?= fmt_dt($l['updated_at']) ?></td><td><?= e($l['creator']) ?></td></tr>
<?php endforeach; ?>
<?php if (!$lists): ?><tr><td colspan="7" class="muted">No lists yet.</td></tr><?php endif; ?>
</tbody></table>
