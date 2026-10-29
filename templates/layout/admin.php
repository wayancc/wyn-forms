<?php
/**
 * Dashboard shell.
 *
 * Page templates pass: heading (big title), outline (outlined subtitle), intro, crumb (html),
 * heroActions (html), tabsFor (form row, shows Inbox / Settings / Add to site tabs).
 *
 * @var WynForms\View $v @var string $content @var string $title @var string $nav @var bool $bare
 */
$heading ??= $title;
$links = ['forms' => ['Forms', 'admin'], 'settings' => ['Settings', 'admin/settings']];
$tabsFor ??= null;
?><!doctype html>
<html lang="en" data-wyn-theme="<?= $v->e($settings->get('theme')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $v->e($title) ?> · <?= $v->e($settings->get('site_name')) ?></title>
<link rel="icon" href="<?= $v->asset('wyn-favicon.svg') ?>" type="image/svg+xml">
<link rel="preload" href="<?= $v->url('assets/fonts/wyn-inter-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= $v->asset('wyn-tokens.css') ?>">
<link rel="stylesheet" href="<?= $v->asset('wyn-admin.css') ?>">
<script src="<?= $v->asset('wyn-admin.js') ?>" defer></script>
</head>
<body>
<?php if ($bare): ?>
<main class="wyn-center">
  <?php foreach ($v->flashes() as $flash): ?><div class="wyn-flash wyn-flash-<?= $v->e($flash['type']) ?>" role="status"><?= $v->e($flash['message']) ?></div><?php endforeach ?>
  <?= $content ?>
</main>
<?php else: ?>
<header class="wyn-bar">
  <div class="wyn-bar-in">
    <a class="wyn-logo" href="<?= $v->url('admin') ?>"><?= $v->e($settings->get('site_name')) ?><span class="wyn-dot">.</span></a>
    <nav class="wyn-nav wyn-label" aria-label="Main">
      <?php foreach ($links as $key => [$label, $href]): ?>
      <a href="<?= $v->url($href) ?>"<?= $nav === $key || ($key === 'forms' && $tabsFor) ? ' aria-current="page"' : '' ?>><?= $label ?></a>
      <?php endforeach ?>
    </nav>
    <div class="wyn-bar-end">
      <form method="post" action="<?= $v->url('admin/logout') ?>"><?= $v->csrf() ?><button class="wyn-linklike" type="submit">Sign out</button></form>
    </div>
  </div>
</header>

<section class="wyn-hero">
  <div class="wyn-hero-in">
    <div>
      <?php if (!empty($crumb)): ?><p class="wyn-crumb wyn-label"><?= $crumb ?></p><?php endif ?>
      <h1 class="wyn-display"><?= $v->e($heading) ?><span class="wyn-dot">.</span></h1>
      <?php if (!empty($outline)): ?><p class="wyn-outline"><?= $v->e($outline) ?></p><?php endif ?>
      <?php if (!empty($intro)): ?><p><?= $v->e($intro) ?></p><?php endif ?>
    </div>
    <?= $heroActions ?? '' ?>
  </div>
</section>

<?php if ($tabsFor): ?>
<nav class="wyn-tabs wyn-label" aria-label="Form">
  <a href="<?= $v->url('admin/forms/' . $tabsFor['id']) ?>"<?= $nav === 'inbox-tab' ? ' aria-current="page"' : '' ?>>Submissions</a>
  <a href="<?= $v->url('admin/forms/' . $tabsFor['id'] . '/setup') ?>"<?= $nav === 'setup-tab' ? ' aria-current="page"' : '' ?>>Add to site</a>
  <a href="<?= $v->url('admin/forms/' . $tabsFor['id'] . '/settings') ?>"<?= $nav === 'settings-tab' ? ' aria-current="page"' : '' ?>>Settings</a>
</nav>
<?php endif ?>

<main class="wyn-wrap">
  <?php foreach ($v->flashes() as $flash): ?>
  <div class="wyn-flash wyn-flash-<?= $v->e($flash['type']) ?>" role="status"><?= $v->e($flash['message']) ?></div>
  <?php endforeach ?>
  <?= $content ?>
</main>
<?php endif ?>
</body>
</html>
