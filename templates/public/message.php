<?php $v->layout('layout/public', ['title' => rtrim($heading, '.')]); ?>
<h1 class="wyn-display"><?= $v->e(rtrim($heading, '.')) ?><span class="wyn-dot">.</span></h1>
<p class="wyn-muted"><?= $v->e($text) ?></p>
<?php if ($back): ?>
<p style="margin-top:32px"><a class="wyn-btn" href="<?= $v->e($back) ?>">Go back and try again</a></p>
<?php endif ?>
