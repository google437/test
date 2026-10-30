<div class="page-head"><h1>Phone bank</h1></div>
<p>Pick a list to call. Voters without a phone number are skipped, and each voter is called once per list.</p>
<table class="tbl"><thead><tr><th>List</th><th class="r">Voters</th><th class="r">Calls so far</th><th></th></tr></thead><tbody>
<?php foreach ($lists as $l): ?><tr><td><?= e($l['name']) ?></td><td class="r"><?= fmt_num($l['member_count']) ?></td><td class="r"><?= fmt_num($l['calls']) ?></td><td><a class="btn primary small" href="/phone/<?= (int) $l['id'] ?>">Start calling</a></td></tr><?php endforeach; ?>
<?php if (!$lists): ?><tr><td colspan="4" class="muted">No lists yet.</td></tr><?php endif; ?>
</tbody></table>
