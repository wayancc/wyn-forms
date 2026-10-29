<?php
$v->layout('layout/admin', [
    'heading' => $form['name'],
    'crumb'   => '<a href="' . $v->url('admin') . '">Forms</a>',
    'tabsFor' => $form,
]);
$e = fn (string $s) => $v->e($s);
$html = <<<HTML
<form action="{$endpoint}" method="POST" data-wyn-form>
  <label>Name <input name="name" required></label>
  <label>Email <input type="email" name="email" required></label>
  <label>Message <textarea name="message" required></textarea></label>

  <!-- Spam trap: keep it hidden and empty -->
  <input type="text" name="wyn-hp" tabindex="-1" autocomplete="off" style="position:absolute;left:-10000px" aria-hidden="true">

  <button type="submit">Send</button>
</form>
<script src="{$script}" defer></script>
HTML;
$ajax = <<<HTML
<form action="{$endpoint}" method="POST" data-wyn-form data-wyn-ajax
      data-wyn-success="Thanks! We'll get back to you soon.">
  …your fields…
  <button type="submit">Send</button>
  <p data-wyn-status></p>
</form>
<script src="{$script}" defer></script>
HTML;
$fetch = <<<JS
const wynRes = await fetch('{$endpoint}', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ name: 'Ada', email: 'ada@example.com', message: 'Hello' }),
});
const wynData = await wynRes.json(); // { ok: true, message: "…", id: 42 }
JS;
?>
<ol class="wyn-steps">
  <li>
    <h2>Your form’s address</h2>
    <p>Every submission sent here lands in this form’s inbox.</p>
    <div class="wyn-endpoint">
      <input id="wyn-endpoint" value="<?= $e($endpoint) ?>" readonly aria-label="Form address">
      <button class="wyn-btn" type="button" data-wyn-copy="#wyn-endpoint">Copy</button>
    </div>
  </li>
  <li>
    <h2>Paste the form into your site</h2>
    <p>Use your own fields and design. Any field name works. A field called <code>email</code> becomes the reply-to address on notifications.</p>
    <div class="wyn-code"><button class="wyn-copy" type="button" data-wyn-copy-next>Copy</button><pre><code><?= $e($html) ?></code></pre></div>
    <details class="wyn-more">
      <summary>Send without leaving the page</summary>
      <p class="wyn-muted">Add <code>data-wyn-ajax</code>. The message appears in <code>[data-wyn-status]</code>, and the form fires <code>wyn:success</code> and <code>wyn:error</code> events.</p>
      <div class="wyn-code"><button class="wyn-copy" type="button" data-wyn-copy-next>Copy</button><pre><code><?= $e($ajax) ?></code></pre></div>
    </details>
    <details class="wyn-more">
      <summary>From JavaScript or another server</summary>
      <div class="wyn-code"><button class="wyn-copy" type="button" data-wyn-copy-next>Copy</button><pre><code><?= $e($fetch) ?></code></pre></div>
    </details>
    <details class="wyn-more">
      <summary>Optional control fields</summary>
      <p class="wyn-muted"><code>wyn-subject</code> sets the notification email subject. <code>wyn-next</code> sends the visitor to a different page after submitting (it has to be on one of your allowed sites).</p>
    </details>
  </li>
  <li>
    <h2>Send a test</h2>
    <p>Submit the form on your site. It should appear under Submissions within a second, and notifications go out right after.</p>
    <a class="wyn-btn wyn-btn-line" href="<?= $v->url('admin/forms/' . $form['id']) ?>">Open submissions</a>
  </li>
</ol>
