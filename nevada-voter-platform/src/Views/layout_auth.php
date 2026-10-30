<?php $flash = App\Core\Flash::pull(); ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Sign in') ?> · <?= e(App\Core\Config::get('APP_NAME', 'Nevada Voter Platform')) ?></title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="auth-body">
<main class="auth-card">
  <a class="brand big" href="/login">NV<span>Voter</span></a>
  <?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
  <?= $content ?>
</main>
</body>
</html>
