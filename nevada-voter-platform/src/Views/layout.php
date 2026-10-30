<?php /** @var string $content */ $flash = App\Core\Flash::pull(); $isStaff = App\Core\Auth::isStaff(); $role = App\Core\Auth::role(); ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Nevada Voter Platform') ?> · <?= e(App\Core\Config::get('APP_NAME', 'Nevada Voter Platform')) ?></title>
<link rel="stylesheet" href="/assets/app.css">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#1b3a5c">
<meta name="csrf-token" content="<?= e(App\Core\Csrf::token()) ?>">
</head>
<body>
<header class="topbar">
  <a class="brand" href="/">NV<span>Voter</span></a>
  <nav class="nav">
    <?php if ($org): ?>
      <a href="/lists" class="<?= str_starts_with($_SERVER['REQUEST_URI'], '/lists') ? 'on' : '' ?>">Lists</a>
      <a href="/voters" class="<?= str_starts_with($_SERVER['REQUEST_URI'], '/voters') ? 'on' : '' ?>">Voters</a>
      <a href="/canvass" class="<?= str_starts_with($_SERVER['REQUEST_URI'], '/canvass') ? 'on' : '' ?>">Canvass</a>
      <a href="/phone" class="<?= str_starts_with($_SERVER['REQUEST_URI'], '/phone') ? 'on' : '' ?>">Phone</a>
      <a href="/reports" class="<?= str_starts_with($_SERVER['REQUEST_URI'], '/reports') ? 'on' : '' ?>">Reports</a>
      <?php if (App\Core\Auth::hasRole('manager')): ?><a href="/settings/surveys" class="<?= str_starts_with($_SERVER['REQUEST_URI'], '/settings/s') ? 'on' : '' ?>">Setup</a><?php endif; ?>
    <?php endif; ?>
    <?php if ($isStaff): ?><a href="/admin" class="<?= str_starts_with($_SERVER['REQUEST_URI'], '/admin') ? 'on' : '' ?>">Admin</a><?php endif; ?>
  </nav>
  <div class="who">
    <?php if ($org): ?><span class="org" title="Current campaign"><?= e($org['name']) ?></span><?php endif; ?>
    <details class="menu">
      <summary><?= e($user['name'] ?? '') ?><?= $role ? ' · ' . e($role) : '' ?></summary>
      <div class="menu-body">
        <?php $orgsForUser = $isStaff ? App\Core\DB::app()->all("SELECT id, name FROM orgs WHERE status = 'active' ORDER BY name") : App\Core\DB::app()->all("SELECT o.id, o.name FROM memberships m JOIN orgs o ON o.id = m.org_id WHERE m.user_id = :u AND o.status = 'active' ORDER BY o.name", [':u' => $user['id']]);
        if (count($orgsForUser) > 1 || $isStaff): ?>
          <form method="post" action="/switch-org" class="inline"><?= csrf_field() ?>
            <label>Campaign <select name="org_id" data-autosubmit>
              <option value="">— choose —</option>
              <?php foreach ($orgsForUser as $o): ?><option value="<?= (int) $o['id'] ?>" <?= $org && (int) $org['id'] === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?>
            </select></label>
          </form>
        <?php endif; ?>
        <a href="/settings/mfa">Security</a>
        <form method="post" action="/logout"><?= csrf_field() ?><button class="linklike">Sign out</button></form>
      </div>
    </details>
  </div>
</header>
<main class="main">
  <?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
  <?= $content ?>
</main>
<footer class="foot">Voter file data is licensed for election purposes only (NRS 293.440, 293.558). Every export is logged.</footer>
<script src="/assets/app.js" defer></script>
</body>
</html>
