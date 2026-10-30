<div class="page-head"><h1>Scripts</h1><nav class="subnav"><a href="/settings/surveys">Surveys</a><a href="/settings/scripts" class="on">Scripts</a><?php if (App\Core\Auth::hasRole('admin')): ?><a href="/settings/team">Team</a><?php endif; ?></nav></div>
<?php foreach ($scripts as $s): ?><div class="card"><h3><?= e($s['name']) ?> <span class="pill"><?= e($s['channel']) ?></span><?= $s['is_default'] ? '<span class="pill ok">default</span>' : '' ?></h3><div class="script"><?= nl2br(e($s['body'])) ?></div>
<form method="post" action="/settings/scripts/<?= (int) $s['id'] ?>/delete" class="inline" data-confirm='Delete this script?'><?= csrf_field() ?><button class="btn small danger">Delete</button></form></div><?php endforeach; ?>
<form method="post" action="/settings/scripts" class="card stack"><?= csrf_field() ?><h3>Add a script</h3>
  <div class="row"><label>Name <input name="name" required maxlength="100"></label><label>Channel <select name="channel"><option value="any">Any</option><option value="door">Door</option><option value="phone">Phone</option></select></label><label class="check"><input type="checkbox" name="is_default" value="1"> Default</label></div>
  <label>Script text <textarea name="body" rows="6" required placeholder="Use [NAME] for the volunteer and [VOTER NAME] for the voter."></textarea></label>
  <button class="btn primary">Save script</button>
</form>
