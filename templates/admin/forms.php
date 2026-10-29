<?php
$v->layout('layout/admin', [
    'heading'     => 'Forms',
    'outline'     => $forms === [] ? 'Ready when you are' : $week . ' ' . ($week === 1 ? 'submission' : 'submissions') . ' this week',
    'heroActions' => '<a class="wyn-btn" href="' . $v->url('admin/forms/new') . '">New form</a>',
]);
?>
<?php if ($forms === []): ?>
<section class="wyn-empty">
  <h2>No forms yet.</h2>
  <p>Make a form, paste one line into your website’s HTML, and submissions arrive here with spam filtered out.</p>
  <a class="wyn-btn" href="<?= $v->url('admin/forms/new') ?>">Make your first form</a>
</section>
<?php else: ?>
<ul class="wyn-list">
  <?php foreach ($forms as $f): ?>
  <li>
    <a class="wyn-form-row" href="<?= $v->url('admin/forms/' . $f['id']) ?>">
      <div>
        <h2><?= $v->e($f['name']) ?></h2>
        <small>
          <?= $f['last_submission_at'] ? 'Last submission ' . $v->e($v->ago((int) $f['last_submission_at'])) : 'No submissions yet' ?>
          <?php if (!$f['enabled']): ?> · <span class="wyn-badge wyn-badge-line">Closed</span><?php endif ?>
        </small>
      </div>
      <div class="wyn-stat">
        <strong><?= (int) $f['unread'] ?></strong>
        <span>unread</span>
      </div>
      <div class="wyn-stat">
        <strong><?= (int) $f['submission_count'] ?></strong>
        <span>total</span>
      </div>
    </a>
  </li>
  <?php endforeach ?>
</ul>
<?php endif ?>
