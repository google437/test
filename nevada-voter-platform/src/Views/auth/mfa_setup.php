<h1>Set up two-factor authentication</h1>
<p>Every account needs an authenticator app (Google Authenticator, 1Password, Authy, etc.). Scan this code, then enter the 6-digit code it shows.</p>
<div id="qr" class="qr" data-uri="<?= e($uri) ?>"></div>
<p class="small">Can't scan? Enter this key manually: <code><?= e(chunk_split($secret, 4, ' ')) ?></code></p>
<form method="post" action="/mfa/setup" class="stack">
  <?= csrf_field() ?>
  <label>Code from app <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus></label>
  <button class="btn primary">Turn on two-factor</button>
</form>
<form method="post" action="/logout"><?= csrf_field() ?><button class="linklike">Sign out</button></form>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="/assets/mfa.js"></script>
