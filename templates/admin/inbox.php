<?php
$v->layout('layout/admin', [
    'heading'     => $form['name'],
    'crumb'       => '<a href="' . $v->url('admin') . '">Forms</a>',
    'tabsFor'     => $form,
    'heroActions' => '<a class="wyn-btn wyn-btn-line" href="' . $v->e($v->url('admin/forms/' . $form['id'] . '/export.csv') . '?status=' . $status . ($q !== '' ? '&q=' . rawurlencode($q) : '')) . '">Export CSV</a>',
]);
$preview = static function (array $data): array {
    $who = '';
    foreach (['name', 'full_name', 'fullname', 'first_name', 'email'] as $key) {
        foreach ($data as $k => $val) {
            if (strtolower((string) $k) === $key && trim((string) $val) !== '') {
                $who = (string) $val;
                break 2;
            }
        }
    }
    $rest = array_filter($data, fn ($val) => (string) $val !== $who && trim((string) $val) !== '');

    return [$who ?: (string) (reset($data) ?: 'Untitled'), mb_substr(preg_replace('/\s+/', ' ', implode(' · ', $rest)) ?? '', 0, 220)];
};
$qs = fn (array $extra) => '?' . http_build_query(array_filter(['status' => $status, 'q' => $q] + $extra, fn ($x) => $x !== '' && $x !== null));
?>
<nav class="wyn-tabs wyn-label" aria-label="Folders" style="--wyn-gutter:0;margin-top:22px">
  <?php foreach ($statuses as $key => $label): ?>
  <a href="?status=<?= $key ?>"<?= $status === $key ? ' aria-current="true"' : '' ?>><?= $label ?><span class="wyn-count"><?= (int) ($counts[$key] ?? 0) ?></span></a>
  <?php endforeach ?>
</nav>

<form method="post" action="<?= $v->url('admin/forms/' . $form['id'] . '/bulk') ?>" id="wyn-bulk-form">
  <?= $v->csrf() ?>
  <input type="hidden" name="status" value="<?= $v->e($status) ?>">
</form>

<div class="wyn-toolbar">
  <form class="wyn-search" method="get" role="search">
    <input type="hidden" name="status" value="<?= $v->e($status) ?>">
    <input type="search" name="q" value="<?= $v->e($q) ?>" placeholder="Search submissions" aria-label="Search submissions">
    <button class="wyn-btn wyn-btn-small" type="submit" style="min-height:52px">Search</button>
  </form>
  <?php if ($rows !== []): ?>
  <div class="wyn-bulk">
    <label class="wyn-check" style="margin:0"><input type="checkbox" data-wyn-select-all> <span class="wyn-small">All</span></label>
    <select name="do" form="wyn-bulk-form" aria-label="Action for selected submissions">
      <option value="read">Mark read</option>
      <option value="unread">Mark unread</option>
      <?php if ($status !== 'inbox'): ?><option value="inbox">Move to Inbox</option><?php endif ?>
      <?php if ($status !== 'archived'): ?><option value="archived">Archive</option><?php endif ?>
      <?php if ($status !== 'spam'): ?><option value="spam">Mark as spam</option><?php endif ?>
      <option value="delete">Delete</option>
    </select>
    <button class="wyn-btn wyn-btn-small" type="submit" form="wyn-bulk-form">Apply</button>
  </div>
  <?php endif ?>
</div>

<?php if ($rows === []): ?>
<section class="wyn-empty">
  <?php if ($q !== ''): ?>
  <h2>No matches.</h2>
  <p>Nothing in <?= $v->e(strtolower($statuses[$status])) ?> matches “<?= $v->e($q) ?>”.</p>
  <a class="wyn-btn wyn-btn-line" href="?status=<?= $status ?>">Clear search</a>
  <?php elseif ($status === 'inbox' && (int) $form['submission_count'] === 0): ?>
  <h2>Waiting for the first one.</h2>
  <p>Add the form to your website and submit it once to see it here.</p>
  <a class="wyn-btn" href="<?= $v->url('admin/forms/' . $form['id'] . '/setup') ?>">Get the form code</a>
  <?php else: ?>
  <h2><?= $status === 'spam' ? 'No spam.' : 'All clear.' ?></h2>
  <p><?= $status === 'spam' ? 'Suspicious submissions land here and are deleted automatically after a while.' : 'Nothing in this folder.' ?></p>
  <?php endif ?>
</section>
<?php else: ?>
<ul class="wyn-inbox">
  <?php foreach ($rows as $s): [$who, $rest] = $preview(json_decode($s['data'], true) ?: []); ?>
  <li class="<?= $s['is_read'] ? '' : 'is-unread' ?>">
    <input type="checkbox" name="ids[]" value="<?= (int) $s['id'] ?>" form="wyn-bulk-form" aria-label="Select submission <?= (int) $s['id'] ?>">
    <a href="<?= $v->url('admin/submissions/' . $s['id']) ?>">
      <div class="wyn-who"><?= $v->e($who) ?><?= (int) $s['file_count'] ? ' <span class="wyn-badge wyn-badge-line">' . (int) $s['file_count'] . ' file' . ((int) $s['file_count'] > 1 ? 's' : '') . '</span>' : '' ?></div>
      <div class="wyn-preview"><?= $v->e($rest) ?></div>
      <?php if ($s['status'] === 'spam' && $s['spam_reason']): ?><span class="wyn-reason"><?= $v->e($s['spam_reason']) ?></span><?php endif ?>
    </a>
    <time datetime="<?= date('c', (int) $s['created_at']) ?>" title="<?= $v->time((int) $s['created_at'], 'M j, Y H:i') ?>"><?= $v->e(date('Y-m-d') === date('Y-m-d', (int) $s['created_at']) ? date('H:i', (int) $s['created_at']) : date('M j', (int) $s['created_at'])) ?></time>
  </li>
  <?php endforeach ?>
</ul>
<?php if ($pages > 1): ?>
<nav class="wyn-pager" aria-label="Pages">
  <?= $page > 1 ? '<a class="wyn-btn wyn-btn-line wyn-btn-small" href="' . $v->e($qs(['page' => $page - 1])) . '">Newer</a>' : '<span></span>' ?>
  <span>Page <?= $page ?> of <?= $pages ?> · <?= $total ?> total</span>
  <?= $page < $pages ? '<a class="wyn-btn wyn-btn-line wyn-btn-small" href="' . $v->e($qs(['page' => $page + 1])) . '">Older</a>' : '<span></span>' ?>
</nav>
<?php endif ?>
<?php endif ?>
