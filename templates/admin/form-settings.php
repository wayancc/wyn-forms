<?php
$isNew = empty($form['id']);
$n = $form['notify'];
$err = fn (string $k) => isset($errors[$k]) ? '<small class="wyn-error">' . $v->e($errors[$k]) . '</small>' : '';
$cls = fn (string $k) => isset($errors[$k]) ? ' has-error' : '';
$v->layout('layout/admin', $isNew ? [
    'heading' => 'New form',
    'crumb'   => '<a href="' . $v->url('admin') . '">Forms</a>',
    'intro'   => 'Name it, choose who gets notified, and you’ll get the code for your website next.',
] : [
    'heading' => $form['name'],
    'crumb'   => '<a href="' . $v->url('admin') . '">Forms</a>',
    'tabsFor' => $form,
]);
?>
<form method="post" action="<?= $v->url($isNew ? 'admin/forms' : 'admin/forms/' . $form['id'] . '/settings') ?>" class="wyn-form">
  <?= $v->csrf() ?>

  <section class="wyn-section">
    <h2>Basics</h2>
    <label class="wyn-field<?= $cls('name') ?>">
      <span>Form name</span>
      <input name="name" value="<?= $v->e($form['name']) ?>" required maxlength="100" placeholder="Contact form">
      <?= $err('name') ?>
    </label>
    <label class="wyn-field<?= $cls('redirect_url') ?>">
      <span>After submitting, send visitors to <small>optional</small></span>
      <input type="url" name="redirect_url" value="<?= $v->e($form['redirect_url']) ?>" placeholder="https://example.com/thank-you">
      <?= $err('redirect_url') ?: '<small>Leave empty to show a built-in thank-you page.</small>' ?>
    </label>
    <label class="wyn-check">
      <input type="checkbox" name="enabled" value="1"<?= $form['enabled'] ? ' checked' : '' ?>>
      <span>Accept submissions<small>Turn off to close the form. Visitors see a message that it’s closed.</small></span>
    </label>
  </section>

  <section class="wyn-section" id="notifications">
    <h2>Notifications</h2>
    <p>Who hears about new submissions. Spam never triggers a notification.</p>
    <label class="wyn-field<?= $cls('notify_emails') ?>">
      <span>Email to</span>
      <input name="notify[emails]" value="<?= $v->e($n['emails'] ?? '') ?>" placeholder="you@example.com, team@example.com">
      <?= $err('notify_emails') ?: '<small>Replies go straight to the visitor when the form has an email field.</small>' ?>
    </label>
    <div class="wyn-two">
      <label class="wyn-field<?= $cls('notify_slack_url') ?>">
        <span>Slack webhook URL</span>
        <input name="notify[slack_url]" value="<?= $v->e($n['slack_url'] ?? '') ?>" placeholder="https://hooks.slack.com/services/…">
        <?= $err('notify_slack_url') ?>
      </label>
      <label class="wyn-field<?= $cls('notify_discord_url') ?>">
        <span>Discord webhook URL</span>
        <input name="notify[discord_url]" value="<?= $v->e($n['discord_url'] ?? '') ?>" placeholder="https://discord.com/api/webhooks/…">
        <?= $err('notify_discord_url') ?>
      </label>
      <label class="wyn-field<?= $cls('notify_telegram') ?>">
        <span>Telegram bot token</span>
        <input name="notify[telegram_token]" value="<?= $v->e($n['telegram_token'] ?? '') ?>" placeholder="123456:ABC…" autocomplete="off">
        <?= $err('notify_telegram') ?>
      </label>
      <label class="wyn-field">
        <span>Telegram chat ID</span>
        <input name="notify[telegram_chat]" value="<?= $v->e($n['telegram_chat'] ?? '') ?>" placeholder="-1001234567890">
      </label>
      <label class="wyn-field<?= $cls('notify_webhook_url') ?>">
        <span>Webhook URL <small>JSON POST</small></span>
        <input name="notify[webhook_url]" value="<?= $v->e($n['webhook_url'] ?? '') ?>" placeholder="https://example.com/hooks/forms">
        <?= $err('notify_webhook_url') ?>
      </label>
      <label class="wyn-field">
        <span>Webhook signing secret <small>optional</small></span>
        <input name="notify[webhook_secret]" value="<?= $v->e($n['webhook_secret'] ?? '') ?>" autocomplete="off">
        <small>Signs the body as <code>X-Wyn-Signature: sha256=…</code></small>
      </label>
    </div>
  </section>

  <section class="wyn-section">
    <h2>Spam protection</h2>
    <p>A hidden honeypot field is always on. The rest you can tune.</p>
    <div class="wyn-three">
      <label class="wyn-field">
        <span>Minimum time on page</span>
        <select name="min_seconds"><?php foreach ([0 => 'Off', 2 => '2 seconds', 3 => '3 seconds', 5 => '5 seconds', 10 => '10 seconds'] as $s => $label): ?><option value="<?= $s ?>"<?= (int) $form['min_seconds'] === $s ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select>
        <small>Needs wyn-forms.js on the page.</small>
      </label>
      <label class="wyn-field">
        <span>Per visitor, every 10 min</span>
        <select name="rate_limit"><?php foreach ([3, 5, 10, 20, 50, 100] as $l): ?><option value="<?= $l ?>"<?= (int) $form['rate_limit'] === $l ? ' selected' : '' ?>><?= $l ?> submissions</option><?php endforeach ?></select>
      </label>
      <label class="wyn-field">
        <span>CAPTCHA</span>
        <select name="captcha"><?php foreach (['none' => 'None', 'turnstile' => 'Cloudflare Turnstile', 'hcaptcha' => 'hCaptcha'] as $k => $label): ?><option value="<?= $k ?>"<?= $form['captcha'] === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select>
      </label>
    </div>
    <label class="wyn-field<?= $cls('captcha_secret') ?>">
      <span>CAPTCHA secret key <small>only when a CAPTCHA is selected</small></span>
      <input name="captcha_secret" value="<?= $v->e($form['captcha_secret']) ?>" autocomplete="off">
      <?= $err('captcha_secret') ?>
    </label>
    <label class="wyn-field">
      <span>Only accept submissions from these sites <small>optional, one per line</small></span>
      <textarea name="allowed_origins" rows="3" placeholder="example.com&#10;shop.example.com"><?= $v->e($form['allowed_origins']) ?></textarea>
      <small>Subdomains are included: example.com also allows www.example.com. Leave empty to accept any site.</small>
    </label>
  </section>

  <section class="wyn-section">
    <h2>File uploads</h2>
    <label class="wyn-check">
      <input type="checkbox" name="accept_files" value="1"<?= $form['accept_files'] ? ' checked' : '' ?>>
      <span>Accept file attachments<small>Up to 5 files. Remember <code>enctype="multipart/form-data"</code> on your form.</small></span>
    </label>
    <label class="wyn-field" style="max-width:260px">
      <span>Largest file allowed</span>
      <select name="max_file_mb"><?php foreach ([1, 2, 5, 10, 25] as $mb): ?><option value="<?= $mb ?>"<?= (int) $form['max_file_mb'] === $mb ? ' selected' : '' ?>><?= $mb ?> MB</option><?php endforeach ?></select>
    </label>
  </section>

  <div class="wyn-submit">
    <button class="wyn-btn" type="submit"><?= $isNew ? 'Create form' : 'Save settings' ?></button>
    <?php if (!$isNew): ?>
    <button class="wyn-btn wyn-btn-line" type="submit" form="wyn-test-form">Send test notification</button>
    <?php endif ?>
  </div>
</form>

<?php if (!$isNew): ?>
<form id="wyn-test-form" method="post" action="<?= $v->url('admin/forms/' . $form['id'] . '/test') ?>"><?= $v->csrf() ?></form>

<section class="wyn-section wyn-form">
  <h2>Danger zone</h2>
  <div class="wyn-actions" style="margin-bottom:22px">
    <form method="post" action="<?= $v->url('admin/forms/' . $form['id'] . '/rotate') ?>" data-wyn-confirm="Give this form a new address? The old one stops working immediately, so update your website right after.">
      <?= $v->csrf() ?><button class="wyn-btn wyn-btn-line wyn-btn-small" type="submit">New form address</button>
    </form>
    <span class="wyn-muted wyn-small">Use this if spammers found the current address.</span>
  </div>
  <form method="post" action="<?= $v->url('admin/forms/' . $form['id'] . '/delete') ?>">
    <?= $v->csrf() ?>
    <label class="wyn-field" style="max-width:420px">
      <span>Type “<?= $v->e($form['name']) ?>” to delete this form and all of its submissions</span>
      <input name="confirm_name" autocomplete="off">
    </label>
    <button class="wyn-btn wyn-btn-danger wyn-btn-small" type="submit">Delete form</button>
  </form>
</section>
<?php endif ?>
