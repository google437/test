<div class="page-head"><h1><?= e($title) ?></h1><?php require __DIR__ . '/_nav.php'; ?></div>
<form method="post" action="<?= $org['id'] ? '/admin/orgs/' . (int) $org['id'] : '/admin/orgs' ?>" class="builder"><?= csrf_field() ?>
<div class="cols">
<section class="card"><h2>Organization</h2>
  <label>Name <input name="name" value="<?= e($org['name']) ?>" required></label>
  <label>Type <select name="type"><?php foreach (['candidate_committee' => 'Candidate committee', 'pac' => 'PAC', 'party' => 'Party', 'ballot_question_committee' => 'Ballot question committee', 'consultant' => 'Registered political consultant'] as $k => $l): ?><option value="<?= $k ?>" <?= $org['type'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
  <label>Committee / registration ID <input name="committee_id" value="<?= e((string) $org['committee_id']) ?>" placeholder="NV SOS committee ID"></label>
  <div class="row"><label>Contract start <input type="date" name="contract_start" value="<?= e((string) $org['contract_start']) ?>"></label><label>Contract end <input type="date" name="contract_end" value="<?= e((string) $org['contract_end']) ?>"></label></div>
  <label>Status <select name="status"><?php foreach (['active', 'ended', 'archived'] as $s): ?><option value="<?= $s ?>" <?= $org['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></label>
  <label>Delete campaign data after <input type="date" name="data_retention_until" value="<?= e((string) $org['data_retention_until']) ?>"> <span class="small muted">(defaults to 90 days after contract end when access ends)</span></label>
  <h3>Election-use attestation</h3>
  <?php if ($org['use_attestation_at']): ?><p>Attested <?= fmt_dt($org['use_attestation_at']) ?> by <?= e((string) $org['attested_by']) ?>. <label class="check"><input type="checkbox" name="clear_attest" value="1"> Clear attestation</label></p>
  <?php else: ?><p class="warn-text">Not yet attested. The organization must confirm it will use the voter list only for election purposes (NRS 293.558) before it is given access.</p><?php endif; ?>
  <label>Attested by (name and title) <input name="attested_by" value="<?= e((string) $org['attested_by']) ?>"></label>
  <label class="check"><input type="checkbox" name="attest" value="1"> Record attestation now (I have the signed form on file)</label>
</section>
<section class="card"><h2>Licensed districts</h2><p class="small muted">Users see only voters in precincts belonging to these districts. Enforced by database row-level security.</p>
  <div class="district-pick"><?php $lastType = null; foreach ($districts as $d): if ($d['type'] !== $lastType): $lastType = $d['type']; ?><h4><?= e(ucfirst($d['type'])) ?></h4><?php endif; ?>
    <label class="check"><input type="checkbox" name="district_ids[]" value="<?= (int) $d['id'] ?>" <?= in_array((int) $d['id'], $licensed, true) ? 'checked' : '' ?>> <?= e($d['name']) ?> <span class="muted small">(<?= fmt_num($d['voters']) ?>)</span></label><?php endforeach; ?></div>
</section>
</div>
<div class="form-actions"><button class="btn primary">Save organization</button><a class="btn" href="/admin/orgs">Back</a></div>
</form>
<?php if ($org['id']): ?><section class="card"><h2>Members</h2><table class="tbl compact"><tbody><?php foreach ($members as $m): ?><tr><td><?= e($m['name']) ?></td><td><?= e($m['email']) ?></td><td><?= e($m['role']) ?></td><td><?= $m['mfa_enabled'] ? 'MFA on' : 'MFA not enrolled' ?></td></tr><?php endforeach; ?><?php if (!$members): ?><tr><td class="muted">No members. Add them under <a href="/admin/users">Users</a>.</td></tr><?php endif; ?></tbody></table></section><?php endif; ?>
