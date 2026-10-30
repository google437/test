<div class="page-head"><h1>Audit log</h1><?php require __DIR__ . '/_nav.php'; ?></div>
<form method="get" class="inline-form"><label>Action <input name="action" value="<?= e((string) ($_GET['action'] ?? '')) ?>" placeholder="export., auth., list."></label><label>Org <select name="org_id"><option value="">All</option><?php foreach ($orgs as $o): ?><option value="<?= (int) $o['id'] ?>" <?= (int) ($_GET['org_id'] ?? 0) === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?></select></label><label>User ID <input name="user_id" value="<?= e((string) ($_GET['user_id'] ?? '')) ?>" style="width:5em"></label><button class="btn">Filter</button></form>
<table class="tbl compact"><thead><tr><th>When</th><th>User</th><th>Org</th><th>Action</th><th>Details</th><th>IP</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td class="nowrap"><?= fmt_dt($r['created_at']) ?></td><td><?= e((string) $r['email']) ?></td><td><?= e((string) $r['org_name']) ?></td><td><?= e($r['action']) ?></td><td class="small mono"><?= e((string) $r['details']) ?></td><td class="small"><?= e((string) $r['ip']) ?></td></tr><?php endforeach; ?>
</tbody></table>
<h2>Exports</h2>
<table class="tbl compact"><thead><tr><th>When</th><th>User</th><th>Org</th><th>Kind</th><th class="r">Rows</th><th>Filter</th><th>IP</th></tr></thead><tbody>
<?php foreach ($exports as $x): ?><tr><td class="nowrap"><?= fmt_dt($x['created_at']) ?></td><td><?= e((string) $x['email']) ?></td><td><?= e((string) $x['org_name']) ?></td><td><?= e($x['kind']) ?></td><td class="r"><?= fmt_num($x['row_count']) ?></td><td class="small mono"><?= e((string) $x['filters']) ?></td><td class="small"><?= e((string) $x['ip']) ?></td></tr><?php endforeach; ?>
</tbody></table>
