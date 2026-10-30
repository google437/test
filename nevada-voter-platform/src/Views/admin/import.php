<div class="page-head"><h1>Import run #<?= (int) $run['id'] ?> <span class="status s-<?= e($run['status']) ?>"><?= e($run['status']) ?></span></h1><?php require __DIR__ . '/_nav.php'; ?></div>
<?php if ($run['status'] === 'held'): ?>
<div class="card flash-error"><h3>Held for review</h3><p><?= e((string) $run['hold_reason']) ?></p>
  <form method="post" action="/admin/imports/<?= (int) $run['id'] ?>/approve" class="inline"><?= csrf_field() ?><button class="btn primary">Approve and load</button></form>
  <form method="post" action="/admin/imports/<?= (int) $run['id'] ?>/reject" class="inline"><?= csrf_field() ?><button class="btn danger">Reject</button></form>
</div>
<?php elseif ($run['status'] === 'approved'): ?><div class="card"><p>Approved<?= $approver ? ' by ' . e($approver) : '' ?>; waiting for the ingestion worker to apply it.</p></div><?php endif; ?>
<div class="cols">
<section class="card"><h2>Run</h2><dl class="kv">
  <dt>Source</dt><dd><?= e($run['source']) ?></dd><dt>URL / file</dt><dd class="small"><?= e((string) $run['source_url']) ?></dd>
  <dt>Started</dt><dd><?= fmt_dt($run['started_at']) ?></dd><dt>Finished</dt><dd><?= fmt_dt($run['finished_at']) ?><?= $run['duration_ms'] !== null ? ' (' . round($run['duration_ms'] / 1000, 1) . 's)' : '' ?></dd>
  <dt>ETag / Last-Modified</dt><dd class="small"><?= e((string) $run['etag']) ?> <?= e((string) $run['last_modified']) ?></dd>
  <dt>SHA-256</dt><dd class="small mono"><?= e((string) $run['sha256']) ?></dd><dt>Archive</dt><dd class="small mono"><?= e((string) $run['raw_path']) ?></dd>
  <dt>Rows</dt><dd><?= fmt_num($run['rows_total']) ?> total · <?= fmt_num($run['rows_new']) ?> new · <?= fmt_num($run['rows_changed']) ?> changed · <?= fmt_num($run['rows_unchanged']) ?> unchanged · <?= fmt_num($run['rows_removed']) ?> removed · <?= fmt_num($run['rows_error']) ?> errors</dd>
  <dt>Unknown codes</dt><dd><?= e((string) $run['unknown_code_pct']) ?>%</dd>
  <?php if ($approver): ?><dt>Approved by</dt><dd><?= e($approver) ?></dd><?php endif; ?>
</dl>
<?php if ($summary): ?><details><summary class="small">Summary JSON</summary><pre class="small"><?= e(json_encode($summary, JSON_PRETTY_PRINT)) ?></pre></details><?php endif; ?>
<p class="small muted">Re-run from the archive: <code>php bin/ingest rerun --run=<?= (int) $run['id'] ?></code></p>
</section>
<section class="card"><h2>Field changes</h2><table class="tbl compact"><tbody><?php foreach ($changes as $c): ?><tr><td><?= e($c['field']) ?></td><td class="r"><?= fmt_num($c['n']) ?></td></tr><?php endforeach; ?><?php if (!$changes): ?><tr><td class="muted">None recorded.</td></tr><?php endif; ?></tbody></table></section>
<section class="card wide"><h2>Error rows (<?= fmt_num($errorCount) ?>)</h2><table class="tbl compact"><thead><tr><th>Line</th><th>Reason</th><th>Context</th></tr></thead><tbody>
<?php foreach ($errors as $er): ?><tr><td><?= (int) $er['line_no'] ?></td><td><?= e($er['reason']) ?></td><td class="small mono"><?= e((string) $er['raw']) ?></td></tr><?php endforeach; ?>
<?php if (!$errors): ?><tr><td colspan="3" class="muted">No error rows.</td></tr><?php endif; ?></tbody></table></section>
</div>
