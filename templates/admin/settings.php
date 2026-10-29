<?php $v->layout('layout/admin', ['heading' => 'Settings']); ?>
<form method="post" action="<?= $v->url('admin/settings') ?>" class="wyn-form">
  <?= $v->csrf() ?>
  <section class="wyn-section">
    <h2>Workspace</h2>
    <div class="wyn-two">
      <label class="wyn-field"><span>Name</span><input name="site_name" value="<?= $v->e($s['site_name']) ?>" maxlength="60"></label>
      <label class="wyn-field"><span>Delete spam after</span>
        <select name="spam_retention_days"><?php foreach ([7, 14, 30, 90] as $d): ?><option value="<?= $d ?>"<?= (int) $s['spam_retention_days'] === $d ? ' selected' : '' ?>><?= $d ?> days</option><?php endforeach ?></select>
      </label>
    </div>
  </section>

  <section class="wyn-section" id="theme">
    <h2>Look</h2>
    <p>Applies to the dashboard and to the thank-you page your visitors see.</p>
    <div class="wyn-two">
      <label class="wyn-check">
        <input type="radio" name="theme" value="wayan"<?= $s['theme'] !== 'neutral' ? ' checked' : '' ?>>
        <span>Bold<small>Black and white, heavy uppercase headlines, square corners.</small></span>
      </label>
      <label class="wyn-check">
        <input type="radio" name="theme" value="neutral"<?= $s['theme'] === 'neutral' ? ' checked' : '' ?>>
        <span>Neutral<small>Quieter type and rounded corners. Fits alongside any brand.</small></span>
      </label>
    </div>
  </section>

  <section class="wyn-section" id="email">
    <h2>Email (SMTP)</h2>
    <p>Used for submission emails. Without SMTP, Wyn Forms falls back to PHP’s mail(), which many hosts block or send to spam.</p>
    <div class="wyn-three">
      <label class="wyn-field"><span>Host</span><input name="smtp_host" value="<?= $v->e($s['smtp_host']) ?>" placeholder="smtp.gmail.com"></label>
      <label class="wyn-field"><span>Port</span><input name="smtp_port" type="number" value="<?= $v->e($s['smtp_port']) ?>"></label>
      <label class="wyn-field"><span>Encryption</span>
        <select name="smtp_encryption"><?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'None (25)'] as $k => $label): ?><option value="<?= $k ?>"<?= $s['smtp_encryption'] === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select>
      </label>
      <label class="wyn-field"><span>Username</span><input name="smtp_user" value="<?= $v->e($s['smtp_user']) ?>" autocomplete="off"></label>
      <label class="wyn-field"><span>Password</span><input type="password" name="smtp_pass" placeholder="<?= $s['smtp_pass'] !== '' ? '•••••••• saved' : '' ?>" autocomplete="new-password"></label>
      <label class="wyn-field"><span>From</span><input name="smtp_from" value="<?= $v->e($s['smtp_from']) ?>" placeholder="Forms &lt;forms@example.com&gt;"></label>
    </div>
  </section>
  <div class="wyn-submit">
    <button class="wyn-btn" type="submit">Save settings</button>
    <button class="wyn-btn wyn-btn-line" type="submit" form="wyn-test-email">Send test email to me</button>
  </div>
</form>
<form id="wyn-test-email" method="post" action="<?= $v->url('admin/settings/test-email') ?>"><?= $v->csrf() ?></form>

<form method="post" action="<?= $v->url('admin/settings/password') ?>" class="wyn-form" id="account">
  <?= $v->csrf() ?>
  <section class="wyn-section">
    <h2>Your account</h2>
    <p>Signed in as <?= $v->e($user['email'] ?? '') ?></p>
    <div class="wyn-two">
      <label class="wyn-field"><span>Current password</span><input type="password" name="current" required autocomplete="current-password"></label>
      <label class="wyn-field"><span>New password</span><input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
    </div>
    <button class="wyn-btn wyn-btn-line" type="submit">Change password</button>
  </section>
</form>
