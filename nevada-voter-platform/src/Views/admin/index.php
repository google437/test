<div class="page-head"><h1>Admin console</h1><?php require __DIR__ . '/_nav.php'; ?></div>
<?php if ($held): ?><div class="flash flash-error"><strong><?= count($held) ?> import run(s) held for review.</strong> <?php foreach ($held as $h): ?><a href="/admin/imports/<?= (int) $h['id'] ?>">#<?= (int) $h['id'] ?> <?= e($h['source']) ?></a> <?php endforeach; ?></div><?php endif; ?>
<div class="tiles">
  <div class="tile"><div class="n"><?= fmt_num($counts['voters']) ?></div><div class="l">Voters on file</div></div>
  <div class="tile"><div class="n"><?= fmt_num($counts['removed']) ?></div><div class="l">Marked removed</div></div>
  <div class="tile"><div class="n"><?= fmt_num($counts['households']) ?></div><div class="l">Households</div></div>
  <div class="tile"><div class="n"><?= $counts['households'] ? round(100 * $counts['geocoded'] / $counts['households'], 1) : 0 ?>%</div><div class="l">Geocoded</div></div>
  <div class="tile"><div class="n"><?= fmt_num($counts['orgs']) ?></div><div class="l">Active orgs</div></div>
  <div class="tile"><div class="n"><?= fmt_num($counts['users']) ?></div><div class="l">Active users</div></div>
  <div class="tile"><div class="n"><?= fmt_num($counts['exports_week']) ?></div><div class="l">Exports, 7 days</div></div>
  <div class="tile <?= $counts['failed_week'] ? 'bad' : '' ?>"><div class="n"><?= fmt_num($counts['failed_week']) ?></div><div class="l">Failed runs, 7 days</div></div>
</div>
<div class="cols">
<section class="card wide"><h2>Source health</h2>
<table class="tbl"><thead><tr><th>Source</th><th>Last attempt</th><th>Last change</th><th>Last status</th><th class="r">Rows</th><th>Note</th></tr></thead><tbody>
<?php foreach ($health as $h): ?><tr><td><?= e($h['source']) ?></td><td><?= fmt_dt($h['last_attempt_at']) ?></td><td><?= fmt_dt($h['last_change_at']) ?></td><td><span class="status s-<?= e((string) $h['last_status']) ?>"><?= e((string) $h['last_status']) ?></span></td><td class="r"><?= fmt_num($h['rows_total']) ?></td><td class="small"><?= e((string) $h['hold_reason']) ?></td></tr><?php endforeach; ?>
<?php if (!$health): ?><tr><td colspan="6" class="muted">No runs yet. Run <code>php bin/ingest clark-voters</code>.</td></tr><?php endif; ?>
</tbody></table></section>
<section class="card"><h2>Validation reference</h2>
<form method="post" action="/admin/settings" class="stack"><?= csrf_field() ?>
<label>Clark County published registration total <input name="clark_published_total" value="<?= e((string) $published) ?>" inputmode="numeric" placeholder="e.g. 1450000"></label>
<p class="small muted">From the SOS registration statistics. A load that differs by more than the row-delta threshold is held.</p>
<button class="btn">Save</button></form></section>
<section class="card"><h2>Recent runs</h2><ul class="plain"><?php foreach ($recent as $r): ?><li><a href="/admin/imports/<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?> <?= e($r['source']) ?></a> <span class="status s-<?= e($r['status']) ?>"><?= e($r['status']) ?></span> <span class="muted small"><?= fmt_dt($r['started_at']) ?></span></li><?php endforeach; ?></ul></section>
<section class="card"><h2>Work as a campaign</h2><p class="small muted">Staff can open any active organization's workspace for support. Switching is logged.</p>
<form method="post" action="/switch-org" class="inline-form"><?= csrf_field() ?><select name="org_id"><?php foreach ($orgs as $o): ?><?php if ($o['status'] === 'active'): ?><option value="<?= (int) $o['id'] ?>"><?= e($o['name']) ?></option><?php endif; ?><?php endforeach; ?></select><button class="btn">Open</button></form></section>
</div>
