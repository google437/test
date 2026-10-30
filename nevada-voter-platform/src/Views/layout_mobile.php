<?php $flash = App\Core\Flash::pull(); ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Canvass') ?></title>
<link rel="stylesheet" href="/assets/app.css">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#1b3a5c"><meta name="apple-mobile-web-app-capable" content="yes">
<meta name="csrf-token" content="<?= e(App\Core\Csrf::token()) ?>">
</head>
<body class="mobile">
<header class="mbar">
  <a href="/canvass" class="brand">NV<span>Voter</span></a>
  <span class="mtitle"><?= e($title ?? '') ?></span>
  <span id="net" class="net" title="Connection">●</span>
</header>
<main class="mmain">
  <?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
  <?= $content ?>
</main>
<nav class="mnav">
  <a href="/canvass">My turfs</a><a href="/phone">Phone</a><a href="/">Desktop</a>
</nav>
<script src="/assets/canvass.js" defer></script>
</body>
</html>
