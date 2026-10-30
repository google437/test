<?php $f = $list['filters']; $sel = fn(string $k, string $v) => in_array($v, (array) ($f[$k] ?? []), true) ? 'selected' : ''; $val = fn(string $k) => e((string) ($f[$k] ?? '')); ?>
<div class="page-head"><h1><?= e($title) ?></h1>
  <div class="count-box" id="live-count"><span class="n" id="cnt-voters">…</span> voters · <span class="n" id="cnt-doors">…</span> doors</div></div>
<form method="post" action="<?= $list['id'] ? '/lists/' . (int) $list['id'] : '/lists' ?>" id="list-form" class="builder">
<?= csrf_field() ?>
<div class="builder-grid">
  <section class="card">
    <h3>Name</h3>
    <label>List name <input name="name" value="<?= e($list['name']) ?>" required placeholder="e.g. AD1 Dem + NP 2 of 4 generals"></label>
    <label class="check"><input type="checkbox" name="chase_mode" value="1" <?= !empty($list['chase_mode']) ? 'checked' : '' ?>> Chase mode: drop voters who have already voted this election</label>
  </section>
  <section class="card">
    <h3>Geography</h3>
    <label>Districts <select name="district_ids[]" multiple size="6" id="f-districts">
      <?php $lastType = null; foreach ($districts as $d): ?><?php if ($d['type'] !== $lastType): $lastType = $d['type']; ?><?php endif; ?><option value="<?= (int) $d['id'] ?>" <?= $sel('district_ids', (string) $d['id']) ?>><?= e(ucfirst($d['type']) . ' ' . $d['name']) ?></option><?php endforeach; ?>
    </select></label>
    <label>Precincts <select name="precinct_ids[]" multiple size="6" id="f-precincts">
      <?php foreach ($precincts as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $sel('precinct_ids', (string) $p['id']) ?>><?= e($p['number']) ?></option><?php endforeach; ?>
    </select></label>
    <div class="row"><label>Household size min <input type="number" name="hh_min" min="1" value="<?= $val('hh_min') ?>"></label><label>max <input type="number" name="hh_max" min="1" value="<?= $val('hh_max') ?>"></label></div>
  </section>
  <section class="card">
    <h3>Registration</h3>
    <label>Party <select name="parties[]" multiple size="6">
      <?php foreach (['DEM', 'REP', 'NP', 'LIB', 'INA', 'OTH'] as $p): ?><option value="<?= $p ?>" <?= $sel('parties', $p) ?>><?= e(party_label($p)) ?></option><?php endforeach; ?>
    </select></label>
    <label>Status <select name="statuses[]" multiple size="3">
      <?php foreach (['A' => 'Active', 'I' => 'Inactive', 'removed' => 'Removed from file'] as $k => $l): ?><option value="<?= $k ?>" <?= $sel('statuses', $k) ?>><?= $l ?></option><?php endforeach; ?>
    </select></label>
    <div class="row"><label>Age min <input type="number" name="age_min" min="17" max="120" value="<?= $val('age_min') ?>"></label><label>max <input type="number" name="age_max" min="17" max="120" value="<?= $val('age_max') ?>"></label></div>
    <label>Sex <select name="sex"><option value="">Any</option><?php foreach (['F' => 'Female', 'M' => 'Male', 'U' => 'Unknown'] as $k => $l): ?><option value="<?= $k ?>" <?= ($f['sex'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
    <div class="row"><label>Registered from <input type="date" name="reg_from" value="<?= $val('reg_from') ?>"></label><label>to <input type="date" name="reg_to" value="<?= $val('reg_to') ?>"></label></div>
    <label>Phone <select name="has_phone"><option value="">Any</option><option value="1" <?= isset($f['has_phone']) && $f['has_phone'] ? 'selected' : '' ?>>Has a phone number</option><option value="0" <?= isset($f['has_phone']) && !$f['has_phone'] ? 'selected' : '' ?>>No phone number</option></select></label>
  </section>
  <section class="card">
    <h3>Vote history</h3>
    <div class="row"><label>Voted in at least <input type="number" name="gen_min" min="0" max="4" value="<?= $val('gen_min') ?>"> of last 4 generals</label></div>
    <div class="row"><label>Voted in at least <input type="number" name="pri_min" min="0" max="4" value="<?= $val('pri_min') ?>"> of last 4 primaries</label></div>
    <label>Voted in a specific election <select name="voted_election"><option value="">—</option><?php foreach ($elections as $el): ?><option value="<?= e($el['code']) ?>" <?= ($f['voted_election'] ?? '') === $el['code'] ? 'selected' : '' ?>><?= e($el['name']) ?> (<?= e($el['code']) ?>)</option><?php endforeach; ?></select></label>
    <label>…by method <select name="vote_method"><option value="">Any</option><?php foreach (['P', 'M', 'E', 'F', 'Z'] as $m): ?><option value="<?= $m ?>" <?= ($f['vote_method'] ?? '') === $m ? 'selected' : '' ?>><?= vote_type_label($m) ?></option><?php endforeach; ?></select></label>
    <h3>This election</h3>
    <?php foreach (['mail_requested' => 'Mail ballot requested', 'mail_returned' => 'Mail ballot returned', 'early_voted' => 'Voted early'] as $k => $l): ?>
    <label><?= $l ?> <select name="<?= $k ?>"><option value="">Any</option><option value="1" <?= isset($f[$k]) && $f[$k] ? 'selected' : '' ?>>Yes</option><option value="0" <?= isset($f[$k]) && !$f[$k] ? 'selected' : '' ?>>No</option></select></label>
    <?php endforeach; ?>
  </section>
  <section class="card">
    <h3>Campaign data</h3>
    <label>Tags <select name="tags[]" multiple size="4"><?php foreach ($tags as $t): ?><option value="<?= e($t['tag']) ?>" <?= $sel('tags', $t['tag']) ?>><?= e($t['tag']) ?> (<?= (int) $t['n'] ?>)</option><?php endforeach; ?></select></label>
    <label>Survey question <select name="survey_q" id="f-survey-q"><option value="">—</option><?php foreach ($questions as $q): ?><option value="<?= (int) $q['id'] ?>" data-options='<?= e((string) $q['options']) ?>' <?= (int) ($f['survey_q'] ?? 0) === (int) $q['id'] ? 'selected' : '' ?>><?= e($q['question']) ?></option><?php endforeach; ?></select></label>
    <label>Answered <select name="survey_answers[]" multiple size="4" id="f-survey-a"><?php foreach ((array) ($f['survey_answers'] ?? []) as $a): ?><option value="<?= e($a) ?>" selected><?= e($a) ?></option><?php endforeach; ?></select></label>
    <label>Exclude voters with a result of <select name="exclude_results[]" multiple size="4"><?php foreach (['contacted', 'refused', 'moved', 'deceased', 'wrong_number'] as $r): ?><option value="<?= $r ?>" <?= $sel('exclude_results', $r) ?>><?= result_label($r) ?></option><?php endforeach; ?></select></label>
    <label>Not contacted in the last <input type="number" name="not_contacted_days" min="1" value="<?= $val('not_contacted_days') ?>"> days</label>
  </section>
</div>
<div class="form-actions">
  <button class="btn primary">Save list</button>
  <?php if (!$list['id']): ?><label class="check"><input type="checkbox" name="freeze" value="1"> Also freeze as a snapshot now</label><?php endif; ?>
  <a class="btn" href="<?= $list['id'] ? '/lists/' . (int) $list['id'] : '/lists' ?>">Cancel</a>
</div>
</form>
