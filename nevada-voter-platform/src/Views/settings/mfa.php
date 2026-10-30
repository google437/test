<h1>Security</h1>
<div class="card">
  <p>Two-factor authentication: <strong><?= $user['mfa_enabled'] ? 'on' : 'off' ?></strong>. Sessions expire after <?= (int) App\Core\Config::get('SESSION_HOURS', '12') ?> hours.</p>
  <h3>Reset authenticator</h3>
  <p class="small muted">Use this if you got a new phone. You will be signed out everywhere and enroll a new authenticator at next login.</p>
  <form method="post" action="/settings/mfa" class="inline-form"><?= csrf_field() ?>
    <label>Confirm password <input type="password" name="password" required></label>
    <button class="btn danger">Reset two-factor</button>
  </form>
</div>
