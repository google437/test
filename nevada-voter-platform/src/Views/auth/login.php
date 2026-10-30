<h1>Sign in</h1>
<form method="post" action="/login" class="stack">
  <?= csrf_field() ?>
  <input type="hidden" name="next" value="<?= e($next) ?>">
  <label>Email <input type="email" name="email" required autocomplete="username" autofocus></label>
  <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
  <button class="btn primary">Continue</button>
</form>
<p class="muted small">Access is limited to verified election organizations. All activity is logged.</p>
