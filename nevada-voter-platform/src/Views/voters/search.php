<div class="page-head"><h1>Find a voter</h1></div>
<form method="get" action="/voters" class="inline-form"><label>Name <input name="q" value="<?= e($q) ?>" placeholder="last name, first name or both" autofocus></label><button class="btn primary">Search</button></form>
<p class="small muted">Results are limited to your campaign's licensed districts and to 50 rows. Searches are logged.</p>
<?php if ($q !== ''): ?>
<table class="tbl"><thead><tr><th>Name</th><th>Age</th><th>Party</th><th>Status</th><th>Address</th><th>Precinct</th></tr></thead><tbody>
<?php foreach ($rows as $v): ?><tr><td><a href="/voters/<?= (int) $v['id'] ?>"><?= e(trim($v['last_name'] . ', ' . $v['first_name'])) ?></a></td><td><?= e((string) age_from_year($v['birth_year'] ? (int) $v['birth_year'] : null)) ?></td><td><?= e((string) $v['party']) ?></td><td><?= e(status_label($v['status'])) ?></td><td><?= e(voter_address($v)) ?><?= $v['res_city'] ? ', ' . e($v['res_city']) : '' ?></td><td><?= e((string) $v['precinct_number']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="muted">No matches.</td></tr><?php endif; ?>
</tbody></table>
<?php endif; ?>
