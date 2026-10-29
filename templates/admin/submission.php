<?php
use WynForms\Notify\Notifier;

$v->layout('layout/admin', [
    'heading' => $form['name'],
    'crumb'   => '<a href="' . $v->url('admin') . '">Forms</a>',
    'tabsFor' => $form,
]);
$statusLabel = ['inbox' => 'Inbox', 'spam' => 'Spam', 'archived' => 'Archived'][$s['status']];
$act = fn (string $action, string $label, string $class = 'wyn-btn-line') => '<form method="post" action="' . $v->url('admin/submissions/' . $s['id'] . '/' . $action) . '"'
    . ($action === 'delete' ? ' data-wyn-confirm="Delete this submission and its files? This can’t be undone."' : '') . '>'
    . $v->csrf() . '<button class="wyn-btn wyn-btn-small ' . $class . '" type="submit">' . $label . '</button></form>';
?>
<div class="wyn-toolbar">
  <a class="wyn-label" href="<?= $v->url('admin/forms/' . $form['id']) ?>?status=<?= $s['status'] ?>">← <?= $statusLabel ?></a>
  <div class="wyn-actions">
    <?php if ($newer): ?><a class="wyn-btn wyn-btn-line wyn-btn-small" href="<?= $v->url('admin/submissions/' . $newer) ?>">Newer</a><?php endif ?>
    <?php if ($older): ?><a class="wyn-btn wyn-btn-line wyn-btn-small" href="<?= $v->url('admin/submissions/' . $older) ?>">Older</a><?php endif ?>
  </div>
</div>

<?php if ($s['status'] === 'spam'): ?>
<div class="wyn-flash wyn-flash-warn">Marked as spam: <?= $v->e($s['spam_reason'] ?: 'by you') ?>. No notifications were sent.</div>
<?php endif ?>

<div class="wyn-grid">
  <div>
    <dl class="wyn-fields">
      <?php foreach ($data as $name => $value): ?>
      <div>
        <dt class="wyn-label"><?= $v->e($name) ?></dt>
        <dd><?= (string) $value === '' ? '<span class="wyn-muted">—</span>' : (filter_var($value, FILTER_VALIDATE_EMAIL) ? '<a href="mailto:' . $v->e($value) . '">' . $v->e($value) . '</a>' : $v->e($value)) ?></dd>
      </div>
      <?php endforeach ?>
    </dl>
    <?php if ($files !== []): ?>
    <ul class="wyn-files">
      <?php foreach ($files as $f): ?>
      <li>
        <span><strong><?= $v->e($f['original_name']) ?></strong> <span class="wyn-muted wyn-small"><?= $v->e($f['field']) ?> · <?= Notifier::size((int) $f['size']) ?></span></span>
        <a class="wyn-btn wyn-btn-line wyn-btn-small" href="<?= $v->url('admin/files/' . $f['id']) ?>">Download</a>
      </li>
      <?php endforeach ?>
    </ul>
    <?php endif ?>
  </div>

  <aside class="wyn-side">
    <h2 class="wyn-label">Details</h2>
    <dl>
      <dt>Received</dt><dd><?= $v->time((int) $s['created_at'], 'D, M j, Y · H:i') ?></dd>
      <dt>Folder</dt><dd><?= $statusLabel ?></dd>
      <?php if ($s['referrer']): ?><dt>Sent from</dt><dd><?= $v->e($s['referrer']) ?></dd><?php endif ?>
      <?php if ($s['user_agent']): ?><dt>Browser</dt><dd class="wyn-small"><?= $v->e($s['user_agent']) ?></dd><?php endif ?>
      <?php if ($deliveries !== []): ?>
      <dt>Notifications</dt>
      <dd>
        <ul class="wyn-delivery">
          <?php foreach ($deliveries as $d): ?>
          <li class="<?= $d['ok'] ? 'wyn-ok' : 'wyn-bad' ?>" title="<?= $v->e($d['error']) ?>"><?= $d['ok'] ? '✓' : '✗' ?> <?= $v->e(ucfirst($d['channel'])) ?><?= $d['ok'] ? '' : ': ' . $v->e(mb_strimwidth((string) $d['error'], 0, 80, '…')) ?></li>
          <?php endforeach ?>
        </ul>
      </dd>
      <?php endif ?>
    </dl>
    <div class="wyn-actions">
      <?php if ($s['email']): ?><a class="wyn-btn wyn-btn-small" href="mailto:<?= $v->e($s['email']) ?>?subject=<?= rawurlencode('Re: ' . $form['name']) ?>">Reply by email</a><?php endif ?>
      <?= $s['status'] !== 'archived' ? $act('archived', 'Archive') : '' ?>
      <?= $s['status'] === 'spam' ? $act('inbox', 'Not spam') : $act('spam', 'Mark as spam') ?>
      <?= $s['status'] === 'archived' ? $act('inbox', 'Move to Inbox') : '' ?>
      <?= $act('unread', 'Mark unread') ?>
      <?= $act('delete', 'Delete', 'wyn-btn-danger') ?>
    </div>
  </aside>
</div>
