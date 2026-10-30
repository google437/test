<div class="page-head"><h1>Phone bank: <?= e($list['name']) ?></h1><div class="actions"><span class="pill"><?= fmt_num($remaining) ?> left to call</span><span class="pill ok">You today: <?= $myCalls ?></span><a class="btn" href="/phone">Other lists</a></div></div>
<?php if (!$voter): ?><div class="card"><h2>All done</h2><p>Everyone in this list with a phone number has been called.</p></div><?php else: ?>
<div class="cols">
<section class="card">
  <h2><?= e($voter['first_name'] . ' ' . $voter['last_name']) ?> <span class="muted small"><?= e(party_label($voter['party'])) ?>, <?= e((string) age_from_year($voter['birth_year'] ? (int) $voter['birth_year'] : null)) ?></span></h2>
  <p class="phone-big"><a href="tel:<?= e(preg_replace('/\D/', '', (string) $voter['phone'])) ?>">☏ <?= e($voter['phone']) ?></a></p>
  <p class="small muted"><?= e(voter_address($voter)) ?>, <?= e((string) $voter['res_city']) ?> · Precinct <?= e((string) $voter['precinct_number']) ?> · <a href="/voters/<?= (int) $voter['id'] ?>" target="_blank">Full profile</a></p>
  <?php if ($script): ?><h3>Script</h3><div class="script"><?= nl2br(e(str_replace('[VOTER NAME]', $voter['first_name'], $script['body']))) ?></div><?php endif; ?>
</section>
<section class="card">
  <form method="post" action="/phone/<?= (int) $list['id'] ?>/record" class="stack"><?= csrf_field() ?>
    <input type="hidden" name="voter_id" value="<?= (int) $voter['id'] ?>">
    <h3>Result</h3>
    <div class="result-grid"><?php foreach ($results as $r): ?><label class="radio-btn"><input type="radio" name="result" value="<?= $r ?>" required><span><?= result_label($r) ?></span></label><?php endforeach; ?></div>
    <?php foreach ($questions as $q): $opts = json_decode((string) $q['options'], true) ?: []; ?>
      <label><?= e($q['question']) ?> <?php if ($q['qtype'] === 'text'): ?><input name="answers[<?= (int) $q['id'] ?>]"><?php else: ?><select name="answers[<?= (int) $q['id'] ?>]"><option value="">—</option><?php foreach ($opts as $o): ?><option><?= e((string) $o) ?></option><?php endforeach; ?></select><?php endif; ?></label>
    <?php endforeach; ?>
    <label>Notes <textarea name="notes" rows="2"></textarea></label>
    <button class="btn primary">Save and next voter</button>
    <a class="btn" href="/phone/<?= (int) $list['id'] ?>">Skip</a>
  </form>
</section>
</div>
<?php endif; ?>
