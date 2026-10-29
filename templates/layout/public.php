<?php /** @var WynForms\View $v @var string $content @var string $title */ ?><!doctype html>
<html lang="en" data-wyn-theme="<?= $v->e($settings->get('theme')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $v->e($title) ?></title>
<link rel="icon" href="<?= $v->asset('wyn-favicon.svg') ?>" type="image/svg+xml">
<link rel="preload" href="<?= $v->url('assets/fonts/wyn-inter-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= $v->asset('wyn-tokens.css') ?>">
<link rel="stylesheet" href="<?= $v->asset('wyn-admin.css') ?>">
</head>
<body class="wyn-public">
<main><div class="wyn-center"><?= $content ?></div></main>
<footer>Form handled by Wyn Forms</footer>
</body>
</html>
