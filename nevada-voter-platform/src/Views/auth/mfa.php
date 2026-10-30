<h1>Two-factor code</h1>
<p>Enter the 6-digit code from your authenticator app.</p>
<form method="post" action="/mfa" class="stack">
  <?= csrf_field() ?>
  <label>Code <input type="text" name="code" inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code" required autofocus></label>
  <button class="btn primary">Verify</button>
</form>
<form method="post" action="/logout"><?= csrf_field() ?><button class="linklike">Sign out</button></form>
