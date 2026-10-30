<?php $f = $list['filters']; $canOrg = App\Core\Auth::hasRole('organizer'); $canMgr = App\Core\Auth::hasRole('manager'); ?>
<div class="page-head">
  <h1><?= e($list['name']) ?> <span class="pill"><?= $list['is_frozen'] ? 'Snapshot ' . fmt_date($list['frozen_at']) : 'Dynamic' ?></span><?= $list['chase_mode'] ? '<span class="pill warn">Chase mode</span>' : '' ?></h1>
  <div class="actions">
    <a class="btn" href="/lists/<?= (int) $list['id'] ?>/map">Map</a>
    <a class="btn" href="/lists/<?= (int) $list['id'] ?>/turfs">Turfs (<?= count($turfs) ?>)</a>
    <a class="btn" href="/phone/<?= (int) $list['id'] ?>">Phone bank</a>
    <?php if ($canOrg): ?><a class="btn" href="/lists/<?= (int) $list['id'] ?>/edit">Edit filters</a><?php endif; ?>
    <?php if ($canOrg && !$list['is_frozen']): ?><form method="post" action="/lists/<?= (int) $list['id'] ?>/freeze" class="inline"><?= csrf_field() ?><button class="btn">Freeze snapshot</button></form><?php endif; ?>
    <?php if ($canMgr): ?><a class="btn" href="/lists/<?= (int) $list['id'] ?>/export.csv" title="Logged and capped">Export CSV</a>
    <form method="post" action="/lists/<?= (int) $list['id'] ?>/delete" class="inline" data-confirm='Delete this list and its turfs?'><?= csrf_field() ?><button class="btn danger">Delete</button></form><?php endif; ?>
  </div>
</div>
<div class="tiles small">
  <div class="tile"><div class="n"><?= fmt_num($counts['voters']) ?></div><div class="l">Voters</div></div>
  <div class="tile"><div class="n"><?= fmt_num($counts['doors']) ?></div><div class="l">Doors</div></div>
</div>
<details class="card"><summary>Filters</summary>
  <dl class="kv">
  <?php foreach ($f as $k => $v): ?>
    <dt><?= e(str_replace('_', ' ', $k)) ?></dt>
    <dd><?php if ($k === 'district_ids'): echo e(implode(', ', array_map(fn($id) => isset($districtNames[$id]) ? ucfirst($districtNames[$id]['type']) . ' ' . $districtNames[$id]['name'] : $id, (array) $v)));
        elseif (is_array($v)): echo e(implode(', ', $v)); elseif (is_bool($v)): echo $v ? 'yes' : 'no'; else: echo e((string) $v); endif; ?></dd>
  <?php endforeach; ?>
  <?php if (!$f): ?><dd class="muted">No filters: everyone in your licensed districts.</dd><?php endif; ?>
  </dl>
</details>
<table class="tbl">
<thead><tr><th>Name</th><th>Age</th><th>Party</th><th>Status</th><th>Address</th><th>Precinct</th><th class="r">Gen / Pri (of 4)</th><th>Phone</th><th>Voted</th></tr></thead>
<tbody>
<?php foreach ($members as $v): ?>
<tr><td><a href="/voters/<?= (int) $v['id'] ?>"><?= e(trim($v['last_name'] . ', ' . $v['first_name'] . ' ' . $v['middle_name'])) ?></a></td><td><?= e((string) age_from_year($v['birth_year'] ? (int) $v['birth_year'] : null)) ?></td>
<td><span class="party p-<?= e(strtolower((string) $v['party'])) ?>"><?= e((string) $v['party']) ?></span></td><td><?= e(status_label($v['status'])) ?></td>
<td><?= e(voter_address($v)) ?><?= $v['res_city'] ? ', ' . e($v['res_city']) : '' ?></td><td><?= e((string) $v['precinct_number']) ?></td>
<td class="r"><?= (int) $v['vote_count_general'] ?> / <?= (int) $v['vote_count_primary'] ?></td><td><?= e((string) $v['phone']) ?></td><td><?= $v['voted_current'] ? '✔' : '' ?></td></tr>
<?php endforeach; ?>
<?php if (!$members): ?><tr><td colspan="9" class="muted">No voters match.</td></tr><?php endif; ?>
</tbody></table>
<?php $pages = (int) ceil(((int) $counts['voters']) / $per); if ($pages > 1): ?>
<nav class="pager">
  <?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>">‹ Prev</a><?php endif; ?>
  <span>Page <?= $page ?> of <?= $pages ?></span>
  <?php if ($page < $pages): ?><a href="?page=<?= $page + 1 ?>">Next ›</a><?php endif; ?>
</nav>
<?php endif; ?>
