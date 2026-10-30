<div class="page-head">
  <h1><?= e(trim($v['first_name'] . ' ' . $v['middle_name'] . ' ' . $v['last_name'] . ' ' . $v['suffix'])) ?> <span class="party p-<?= e(strtolower((string) $v['party'])) ?>"><?= e(party_label($v['party'])) ?></span> <span class="pill"><?= e(status_label($v['status'])) ?></span><?= $v['voted_current'] ? '<span class="pill ok">Voted this election</span>' : '' ?></h1>
</div>
<div class="cols">
<section class="card">
  <h2>Voter file</h2>
  <dl class="kv">
    <dt>Age</dt><dd><?= e((string) age_from_year($v['birth_year'] ? (int) $v['birth_year'] : null)) ?> (born <?= e((string) $v['birth_year']) ?>)<?= $v['sex'] ? ', ' . e($v['sex']) : '' ?></dd>
    <dt>Address</dt><dd><?= e(voter_address($v)) ?><br><?= e(trim(($v['res_city'] ?? '') . ' ' . ($v['res_state'] ?? '') . ' ' . ($v['res_zip'] ?? ''))) ?><?= $v['confidential'] ? '<br><span class="muted small">Confidential flag set by the county: address and phone withheld.</span>' : '' ?></dd>
    <?php if ($v['mail_address']): ?><dt>Mailing</dt><dd><?= e($v['mail_address']) ?>, <?= e(trim($v['mail_city'] . ' ' . $v['mail_state'] . ' ' . $v['mail_zip'])) ?></dd><?php endif; ?>
    <dt>Phone</dt><dd><?= $v['phone'] ? '<a href="tel:' . e($v['phone']) . '">' . e($v['phone']) . '</a>' : '<span class="muted">none on file</span>' ?></dd>
    <dt>Precinct</dt><dd><?= e((string) $v['precinct_number']) ?><?php foreach ($districts as $d): ?> · <?= e(ucfirst($d['type']) . ' ' . $d['name']) ?><?php endforeach; ?></dd>
    <dt>Registered</dt><dd><?= fmt_date($v['registration_date']) ?><?= $v['language_pref'] ? ' · ' . e($v['language_pref']) : '' ?></dd>
    <dt>County ID</dt><dd><?= e($v['county_voter_id']) ?> (<?= e($v['source_county']) ?>)</dd>
    <dt>Household</dt><dd><?= (int) $v['hh_size'] ?> voter(s)<?php foreach ($household as $h): ?><br><a href="/voters/<?= (int) $h['id'] ?>"><?= e($h['first_name'] . ' ' . $h['last_name']) ?></a> <span class="muted small"><?= e((string) $h['party']) ?>, <?= e((string) age_from_year($h['birth_year'] ? (int) $h['birth_year'] : null)) ?></span><?php endforeach; ?></dd>
  </dl>
  <h3>Vote history <span class="muted small"><?= (int) $v['vote_count_general'] ?> of last 4 generals, <?= (int) $v['vote_count_primary'] ?> of last 4 primaries</span></h3>
  <table class="tbl compact"><tbody>
  <?php foreach ($history as $h): ?><tr><td><?= e($h['name'] ?? $h['election_code']) ?></td><td><?= e($h['election_code']) ?></td><td><?= e(vote_type_label($h['vote_type'])) ?></td></tr><?php endforeach; ?>
  <?php if (!$history): ?><tr><td class="muted">No vote history in the file.</td></tr><?php endif; ?>
  </tbody></table>
  <?php if ($ballot): ?><h3>Ballot activity</h3><table class="tbl compact"><tbody>
  <?php foreach ($ballot as $b): ?><tr><td><?= e($b['election_code']) ?></td><td><?= $b['mail_requested'] ? 'Mail ballot sent ' . fmt_date($b['mail_sent_date']) : '' ?><?= $b['mail_returned'] ? ' · returned ' . fmt_date($b['mail_returned_date']) : '' ?><?= $b['ev_voted_on'] ? ' · voted early ' . fmt_dt($b['ev_voted_on']) . ($b['ev_site'] ? ' at ' . e($b['ev_site']) : '') : '' ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
  <?php if ($changes): ?><details><summary class="small">File changes (<?= count($changes) ?>)</summary><table class="tbl compact"><tbody>
  <?php foreach ($changes as $c): ?><tr><td><?= fmt_date($c['changed_at']) ?></td><td><?= e($c['field']) ?></td><td><?= e((string) $c['old_value']) ?> → <?= e((string) $c['new_value']) ?></td></tr><?php endforeach; ?>
  </tbody></table></details><?php endif; ?>
</section>
<section class="card">
  <h2>Record a contact</h2>
  <form method="post" action="/voters/<?= (int) $v['id'] ?>/contact" class="stack"><?= csrf_field() ?>
    <div class="row">
      <label>Channel <select name="channel"><option value="door">Door</option><option value="phone">Phone</option><option value="text">Text</option></select></label>
      <label>Result <select name="result" required><?php foreach ($results as $r): ?><option value="<?= $r ?>"><?= result_label($r) ?></option><?php endforeach; ?></select></label>
    </div>
    <?php foreach ($questions as $q): $opts = json_decode((string) $q['options'], true) ?: []; ?>
      <label><?= e($q['question']) ?>
        <?php if ($q['qtype'] === 'text'): ?><input name="answers[<?= (int) $q['id'] ?>]"><?php else: ?><select name="answers[<?= (int) $q['id'] ?>]"><option value="">—</option><?php foreach ($opts as $o): ?><option><?= e((string) $o) ?></option><?php endforeach; ?></select><?php endif; ?>
      </label>
    <?php endforeach; ?>
    <label>Notes <textarea name="notes" rows="2"></textarea></label>
    <button class="btn primary">Save contact</button>
  </form>
  <h3>Tags</h3>
  <p><?php foreach ($tags as $t): ?><form method="post" action="/voters/<?= (int) $v['id'] ?>/tag" class="inline"><?= csrf_field() ?><input type="hidden" name="tag" value="<?= e($t['tag']) ?>"><input type="hidden" name="remove" value="1"><button class="tag" title="Remove tag"><?= e($t['tag']) ?> ×</button></form> <?php endforeach; ?></p>
  <form method="post" action="/voters/<?= (int) $v['id'] ?>/tag" class="inline-form"><?= csrf_field() ?><input name="tag" placeholder="add a tag" maxlength="40"><button class="btn small">Add</button></form>
  <h3>Notes</h3>
  <form method="post" action="/voters/<?= (int) $v['id'] ?>/note" class="stack"><?= csrf_field() ?><textarea name="body" rows="2" placeholder="Campaign-only note"></textarea><button class="btn small">Add note</button></form>
  <ul class="plain">
    <?php foreach ($notes as $n): ?><li><?= e($n['body']) ?> <span class="muted small">· <?= e((string) $n['user_name']) ?>, <?= fmt_dt($n['created_at']) ?></span></li><?php endforeach; ?>
  </ul>
</section>
<section class="card wide">
  <h2>Contact history</h2>
  <table class="tbl"><thead><tr><th>When</th><th>Channel</th><th>Result</th><th>By</th><th>Notes</th><th>Survey</th></tr></thead><tbody>
  <?php foreach ($attempts as $a): ?><tr><td><?= fmt_dt($a['contacted_at']) ?></td><td><?= e($a['channel']) ?></td><td><?= e(result_label($a['result'])) ?></td><td><?= e((string) $a['user_name']) ?></td><td><?= e((string) $a['notes']) ?></td>
    <td><?php foreach ($responses as $r): if ((int) $r['contact_attempt_id'] === (int) $a['id']): ?><div class="small"><?= e($r['question']) ?>: <strong><?= e((string) $r['answer']) ?></strong></div><?php endif; endforeach; ?></td></tr><?php endforeach; ?>
  <?php if (!$attempts): ?><tr><td colspan="6" class="muted">No contact attempts yet.</td></tr><?php endif; ?>
  </tbody></table>
</section>
</div>
