<?php $v->layout('layout/admin'); ?>
<p class="wyn-logo">Wyn Forms<span class="wyn-dot">.</span></p>
<h1 class="wyn-display">Set up<span class="wyn-dot">.</span></h1>
<p>Create the admin account. Next you’ll make your first form.</p>
<form method="post">
  <?= $v->csrf() ?>
  <label class="wyn-field">
    <span>Workspace name</span>
    <input name="site_name" value="<?= $v->e($old['site_name'] ?? '') ?>" placeholder="Wyn Forms" autocomplete="organization">
  </label>
  <label class="wyn-field<?= isset($errors['email']) ? ' has-error' : '' ?>">
    <span>Your email</span>
    <input type="email" name="email" value="<?= $v->e($old['email'] ?? '') ?>" required autocomplete="email">
    <?php if (isset($errors['email'])): ?><small class="wyn-error"><?= $v->e($errors['email']) ?></small><?php endif ?>
  </label>
  <label class="wyn-field<?= isset($errors['password']) ? ' has-error' : '' ?>">
    <span>Password</span>
    <input type="password" name="password" required minlength="10" autocomplete="new-password">
    <small<?= isset($errors['password']) ? ' class="wyn-error"' : '' ?>><?= $v->e($errors['password'] ?? 'At least 10 characters.') ?></small>
  </label>
  <button class="wyn-btn" type="submit">Create account</button>
</form>
