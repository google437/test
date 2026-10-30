<div class="page-head"><h1>Election windows</h1><?php require __DIR__ . '/_nav.php'; ?></div>
<p class="small muted">The current window drives the feed schedule (mail ballot file every 4 hours after registration closes, early vote file every 2 hours during early voting) and the "already voted" flag for chase lists.</p>
<table class="tbl"><thead><tr><th>Name</th><th>Code</th><th>Registration closes</th><th>Early voting</th><th>Election day</th><th>Current</th></tr></thead><tbody>
<?php foreach ($windows as $w): ?><tr><td><?= e($w['name']) ?></td><td><?= e($w['election_code']) ?></td><td><?= fmt_date($w['registration_close']) ?></td><td><?= fmt_date($w['early_vote_start']) ?> – <?= fmt_date($w['early_vote_end']) ?></td><td><?= fmt_date($w['election_date']) ?></td><td><?= $w['is_current'] ? '✔' : '' ?></td></tr><?php endforeach; ?>
</tbody></table>
<form method="post" action="/admin/windows" class="card stack"><?= csrf_field() ?><h3>Add a window</h3>
  <div class="row"><label>Name <input name="name" placeholder="2026 General"></label><label>Election code <input name="election_code" placeholder="26G" required style="width:6em"></label><label>Election day <input type="date" name="election_date" required></label></div>
  <div class="row"><label>Registration closes <input type="date" name="registration_close"></label><label>Early voting starts <input type="date" name="early_vote_start"></label><label>Early voting ends <input type="date" name="early_vote_end"></label><label class="check"><input type="checkbox" name="is_current" value="1" checked> Make current</label></div>
  <button class="btn primary">Save window</button>
</form>
<h2>Known elections</h2><p class="small muted">Dates are derived from the county codes (Nevada primary: second Tuesday of June; general: first Tuesday after the first Monday of November). Saving a window with the same code overrides the date.</p>
<table class="tbl compact"><tbody><?php foreach ($elections as $e): ?><tr><td><?= e($e['code']) ?></td><td><?= e($e['name']) ?></td><td><?= fmt_date($e['election_date']) ?></td></tr><?php endforeach; ?></tbody></table>
