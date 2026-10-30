<div class="page-head"><h1>Dashboard</h1><div class="actions"><a class="btn primary" href="/lists/new">New list</a><a class="btn" href="/voters">Find a voter</a></div></div>
<?php if ($window): ?><p class="banner">Current election: <strong><?= e($window['name']) ?></strong> on <?= fmt_date($window['election_date']) ?>. <?= fmt_num($stats['voted']) ?> of <?= fmt_num($stats['voters']) ?> voters in your districts have already voted (chase lists exclude them).</p><?php endif; ?>
<div class="tiles">
  <div class="tile"><div class="n"><?= fmt_num($stats['voters']) ?></div><div class="l">Voters in licensed districts</div></div>
  <div class="tile"><div class="n"><?= fmt_num($stats['doors']) ?></div><div class="l">Doors</div></div>
  <div class="tile"><div class="n"><?= fmt_num($stats['knocks']) ?></div><div class="l">Doors knocked</div></div>
  <div class="tile"><div class="n"><?= fmt_num($stats['calls']) ?></div><div class="l">Calls made</div></div>
  <div class="tile"><div class="n"><?= fmt_num($stats['contacts']) ?></div><div class="l">Conversations</div></div>
  <div class="tile"><div class="n"><?= fmt_num($stats['attempts_week']) ?></div><div class="l">Attempts, last 7 days</div></div>
</div>
<div class="cols">
  <section class="card">
    <h2>My turfs</h2>
    <?php if (!$myTurfs): ?><p class="muted">Nothing assigned to you. <a href="/canvass">Open the canvass app</a>.</p><?php endif; ?>
    <ul class="plain">
      <?php foreach ($myTurfs as $t): ?><li><a href="/canvass/turf/<?= (int) $t['id'] ?>"><?= e($t['name']) ?></a> <span class="muted small"><?= (int) $t['door_count'] ?> doors · <?= e($t['status']) ?></span></li><?php endforeach; ?>
    </ul>
  </section>
  <section class="card">
    <h2>Recent lists</h2>
    <ul class="plain">
      <?php foreach ($recentLists as $l): ?><li><a href="/lists/<?= (int) $l['id'] ?>"><?= e($l['name']) ?></a> <span class="muted small"><?= fmt_num($l['member_count']) ?> voters · <?= fmt_num($l['door_count']) ?> doors<?= $l['is_frozen'] ? ' · snapshot' : '' ?><?= $l['chase_mode'] ? ' · chase' : '' ?></span></li><?php endforeach; ?>
      <?php if (!$recentLists): ?><li class="muted">No lists yet. <a href="/lists/new">Build one</a>.</li><?php endif; ?>
    </ul>
  </section>
  <section class="card">
    <h2>Volunteer leaderboard (30 days)</h2>
    <table class="tbl"><thead><tr><th>Volunteer</th><th class="r">Attempts</th><th class="r">Conversations</th></tr></thead><tbody>
      <?php foreach ($leaders as $l): ?><tr><td><?= e($l['name']) ?></td><td class="r"><?= fmt_num($l['attempts']) ?></td><td class="r"><?= fmt_num($l['contacts']) ?></td></tr><?php endforeach; ?>
      <?php if (!$leaders): ?><tr><td colspan="3" class="muted">No contact attempts yet.</td></tr><?php endif; ?>
    </tbody></table>
  </section>
  <section class="card">
    <h2>Voter file</h2>
    <?php if ($lastRun): ?><p>Clark County file last loaded <?= fmt_dt($lastRun['finished_at']) ?> (<?= fmt_num($lastRun['rows_total']) ?> rows; <?= fmt_num($lastRun['rows_new']) ?> new, <?= fmt_num($lastRun['rows_changed']) ?> changed, <?= fmt_num($lastRun['rows_removed']) ?> removed).</p><?php else: ?><p class="muted">No voter file loaded yet.</p><?php endif; ?>
    <p class="small muted">Contact history is attached to each voter and survives weekly refreshes.</p>
  </section>
</div>
