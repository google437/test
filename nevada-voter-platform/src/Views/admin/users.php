<div class="page-head"><h1>Users</h1><?php require __DIR__ . '/_nav.php'; ?></div>
<table class="tbl"><thead><tr><th>Name</th><th>Email</th><th>Staff</th><th>Organizations</th><th>MFA</th><th>Status</th><th>Last login</th><th></th></tr></thead><tbody>
<?php foreach ($users as $u): ?><tr><td><?= e($u['name']) ?></td><td><?= e($u['email']) ?></td><td><?= $u['is_staff'] ? 'yes' : '' ?></td><td class="small"><?= e((string) $u['orgs']) ?></td><td><?= $u['mfa_enabled'] ? 'on' : '<span class="warn-text">not enrolled</span>' ?></td><td><?= e($u['status']) ?></td><td><?= fmt_dt($u['last_login_at']) ?></td>
<td class="nowrap"><form method="post" action="/admin/users/<?= (int) $u['id'] ?>/toggle" class="inline"><?= csrf_field() ?><button class="btn small"><?= $u['status'] === 'active' ? 'Disable' : 'Enable' ?></button></form> <form method="post" action="/admin/users/<?= (int) $u['id'] ?>/reset-mfa" class="inline"><?= csrf_field() ?><button class="btn small">Reset MFA</button></form></td></tr><?php endforeach; ?>
</tbody></table>
<form method="post" action="/admin/users" class="card stack"><?= csrf_field() ?><h3>Add or update a user</h3>
  <div class="row"><label>Name <input name="name"></label><label>Email <input type="email" name="email" required></label></div>
  <div class="row"><label>Organization <select name="org_id"><option value="">— none —</option><?php foreach ($orgs as $o): ?><option value="<?= (int) $o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?></select></label><label>Role <select name="role"><?php foreach ($roles as $r): ?><option value="<?= $r ?>"><?= $r ?></option><?php endforeach; ?></select></label><label class="check"><input type="checkbox" name="is_staff" value="1"> Envision staff</label></div>
  <button class="btn primary">Save user</button>
</form>
