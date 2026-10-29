<?php $v->layout('layout/public', ['title' => 'Thank you']); ?>
<h1 class="wyn-display">Thank you<span class="wyn-dot">.</span></h1>
<p class="wyn-muted">Your message has been sent. We’ll get back to you as soon as we can.</p>
<?php if ($back): ?>
<p style="margin-top:32px"><a class="wyn-btn" href="<?= $v->e($back) ?>">Back to the website</a></p>
<?php endif ?>
