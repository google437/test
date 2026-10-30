<div class="page-head"><h1>Team</h1><nav class="subnav"><a href="/settings/surveys">Surveys</a><a href="/settings/scripts">Scripts</a><a href="/settings/team" class="on">Team</a></nav></div>
<table class="tbl"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>MFA</th><th>Last login</th><th></th></tr></thead><tbody>
<?php foreach ($members as $m): ?><tr><td><?= e($m['name']) ?></td><td><?= e($m['email']) ?></td><td><?= e($m['role']) ?></td><td><?= $m['mfa_enabled'] ? 'on' : '<span class="warn-text">not enrolled</span>' ?></td><td><?= fmt_dt($m['last_login_at']) ?></td>
<td><?php if ((int) $m['id'] !== App\Core\Auth::userId()): ?><form method="post" action="/settings/team/<?= (int) $m['id'] ?>/remove" class="inline" data-confirm='Remove from this campaign?'><?= csrf_field() ?><button class="btn small danger">Remove</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table>
<form method="post" action="/settings/team" class="card stack"><?= csrf_field() ?><h3>Add a team member</h3>
  <p class="small muted">Roles: volunteer (canvass and call), organizer (build lists and cut turf), manager (exports and setup), admin (team).</p>
  <div class="row"><label>Name <input name="name"></label><label>Email <input type="email" name="email" required></label><label>Role <select name="role"><?php foreach ($roles as $r): ?><option value="<?= $r ?>"><?= $r ?></option><?php endforeach; ?></select></label></div>
  <button class="btn primary">Add</button>
</form>
