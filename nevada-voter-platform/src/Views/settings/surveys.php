<div class="page-head"><h1>Survey questions</h1><nav class="subnav"><a href="/settings/surveys" class="on">Surveys</a><a href="/settings/scripts">Scripts</a><?php if (App\Core\Auth::hasRole('admin')): ?><a href="/settings/team">Team</a><?php endif; ?></nav></div>
<table class="tbl"><thead><tr><th>#</th><th>Question</th><th>Type</th><th>Options</th><th class="r">Responses</th><th>Active</th></tr></thead><tbody>
<?php foreach ($questions as $q): ?><tr><td><?= (int) $q['sort_order'] ?></td><td><?= e($q['question']) ?></td><td><?= e($q['qtype']) ?></td><td class="small"><?= e(implode(' / ', json_decode((string) $q['options'], true) ?: [])) ?></td><td class="r"><?= fmt_num($q['responses']) ?></td>
<td><form method="post" action="/settings/surveys/<?= (int) $q['id'] ?>/toggle" class="inline"><?= csrf_field() ?><button class="btn small"><?= $q['active'] ? 'Active · turn off' : 'Off · turn on' ?></button></form></td></tr><?php endforeach; ?>
</tbody></table>
<form method="post" action="/settings/surveys" class="card stack"><?= csrf_field() ?><h3>Add a question</h3>
  <label>Question <input name="question" required maxlength="300"></label>
  <div class="row"><label>Type <select name="qtype"><option value="scale">Scale 1-5</option><option value="choice">Multiple choice</option><option value="yesno">Yes / No</option><option value="text">Free text</option></select></label><label>Order <input type="number" name="sort_order" value="<?= count($questions) + 1 ?>" style="width:5em"></label></div>
  <label>Options (multiple choice, one per line) <textarea name="options" rows="3"></textarea></label>
  <button class="btn primary">Add question</button>
</form>
