<?php

declare(strict_types=1);

/*
 * Dependency-free test suite: php tests/run.php
 * Uses a throwaway SQLite file and a local HTTP server for webhooks. No internet needed.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use WynForms\App;
use WynForms\Http\Request;
use WynForms\Receiver;

$wynPassed = $wynFailed = 0;
function wyn_check(string $name, bool $ok, string $detail = ''): void
{
    global $wynPassed, $wynFailed;
    $ok ? $wynPassed++ : $wynFailed++;
    echo ($ok ? "\033[32m  ✓\033[0m " : "\033[31m  ✗\033[0m ") . $name . ($ok || $detail === '' ? '' : "  ({$detail})") . PHP_EOL;
}

$tmp = sys_get_temp_dir() . '/wyn-forms-test-' . bin2hex(random_bytes(4));
mkdir($tmp);

// Local webhook receiver
file_put_contents($tmp . '/hook.php', '<?php file_put_contents(__DIR__ . "/hook.log", json_encode(["sig" => $_SERVER["HTTP_X_WYN_SIGNATURE"] ?? null, "body" => file_get_contents("php://input")]) . "\n", FILE_APPEND); echo "ok";');
$port = random_int(20000, 40000);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $tmp . '/hook.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && @file_get_contents("http://127.0.0.1:{$port}/") === false; $i++) {
    usleep(50_000);
}
@unlink($tmp . '/hook.log');
register_shutdown_function(function () use ($server, $tmp) {
    proc_terminate($server);
    exec('rm -rf ' . escapeshellarg($tmp));
});

$captchaOk = true;
$app = App::boot(WYN_ROOT, ['database' => $tmp . '/db.sqlite', 'app_url' => 'https://forms.test'], function () use (&$captchaOk) {
    return ['success' => $captchaOk];
});

[$formId, $errors] = $app->forms->save([
    'name' => 'Contact', 'enabled' => 1, 'allowed_origins' => "https://villa.test\nshop.example.com", 'rate_limit' => 3,
    'min_seconds' => 3, 'accept_files' => 1, 'max_file_mb' => 1,
    'notify' => ['webhook_url' => "http://127.0.0.1:{$port}/", 'webhook_secret' => 'k'],
]);
$form = $app->forms->find((int) $formId);
$ip = 0;
$post = function (array $fields, array $headers = [], array $files = [], ?string $body = null) use ($app, &$form, &$ip) {
    $ip++;
    $headers += ['origin' => 'https://villa.test', 'accept' => 'application/json'];

    return $app->handle(new Request('POST', '/f/' . $form['key'], post: $fields, ip: "10.0.0.{$ip}", headers: $headers, files: $files, body: $body ?? ''));
};
$json = fn ($res) => json_decode($res->body, true) ?: [];
$last = fn () => $app->db->one('SELECT * FROM wyn_submissions ORDER BY id DESC LIMIT 1');

// ---------------------------------------------------------------------------
echo "Form settings\n";
wyn_check('form gets a 12-character key', (bool) preg_match('/^[a-z2-9]{12}$/', $form['key']));
wyn_check('origins normalised to hosts', $form['allowed_origins'] === "villa.test\nshop.example.com");
[, $e] = $app->forms->save(['name' => '', 'redirect_url' => 'ftp://x', 'captcha' => 'turnstile', 'notify' => ['emails' => 'nope', 'telegram_token' => 'x']]);
wyn_check('validation catches bad input', isset($e['name'], $e['redirect_url'], $e['captcha_secret'], $e['notify_emails'], $e['notify_telegram']));

// ---------------------------------------------------------------------------
echo "Field handling\n";
$f = Receiver::fields(['name' => ' Ayu ', 'extras' => ['Pickup', 'Breakfast'], 'agree' => true, 'wyn-hp' => '', 'wyn-next' => 'x', 'cf-turnstile-response' => 't', "bad\0key" => "v\0al\r\nue"]);
wyn_check('trims, flattens checkbox groups, drops control fields', $f === ['name' => 'Ayu', 'extras' => 'Pickup, Breakfast', 'agree' => 'yes', 'badkey' => "val\nue"]);
wyn_check('caps field count', count(Receiver::fields(array_fill_keys(range(1, 300), 'x'))) === 100);

// ---------------------------------------------------------------------------
echo "Accepting submissions\n";
$res = $post(['name' => 'Ketut', 'email' => 'ketut@villa.test', 'message' => "Hello\nWorld", 'wyn-elapsed' => '12.4', 'wyn-subject' => 'Villa enquiry']);
$row = $last();
wyn_check('valid submission is accepted', $res->status === 200 && $json($res)['ok'] === true && $row['status'] === 'inbox');
wyn_check('JSON reply includes the id', $json($res)['id'] === (int) $row['id']);
wyn_check('email field is captured for reply-to', $row['email'] === 'ketut@villa.test');
wyn_check('field order and line breaks kept', json_decode($row['data'], true) === ['name' => 'Ketut', 'email' => 'ketut@villa.test', 'message' => "Hello\nWorld"]);
wyn_check('IP is stored only as a salted hash', strlen((string) $row['ip_hash']) === 32 && !str_contains((string) $row['ip_hash'], '10.0.0'));
wyn_check('CORS allows the listed site', ($res->headers['Access-Control-Allow-Origin'] ?? '') === 'https://villa.test');
wyn_check('subdomain of an allowed site works', $post(['name' => 'Sub', 'message' => 'x'], ['origin' => 'https://www.shop.example.com'])->status === 200);

$app->runDeferred();
$hook = array_map(fn ($l) => json_decode($l, true), file($tmp . '/hook.log', FILE_IGNORE_NEW_LINES) ?: []);
$payload = json_decode($hook[0]['body'] ?? '{}', true);
wyn_check('webhook sent after the response', count($hook) === 2 && $payload['submission']['fields']['name'] === 'Ketut');
wyn_check('webhook is signed', $hook[0]['sig'] === 'sha256=' . hash_hmac('sha256', $hook[0]['body'], 'k'));
wyn_check('delivery logged', (int) $app->db->value('SELECT COUNT(*) FROM wyn_deliveries WHERE ok = 1') === 2);

$res = $post([], ['content-type' => 'application/json'], [], '{"name":"Json","newsletter":true}');
wyn_check('JSON body accepted', $res->status === 200 && json_decode($last()['data'], true) === ['name' => 'Json', 'newsletter' => 'yes']);

// ---------------------------------------------------------------------------
echo "Rejections\n";
wyn_check('other sites are refused', $post(['name' => 'X', 'message' => 'x'], ['origin' => 'https://evil.test'])->status === 403);
wyn_check('lookalike domain is refused', $post(['name' => 'X', 'message' => 'x'], ['origin' => 'https://notvilla.test'])->status === 403);
wyn_check('empty submission is refused', $post(['name' => '', 'message' => ' '])->status === 422);
wyn_check('unknown form is 404', $app->handle(new Request('POST', '/f/doesnotexist', post: ['a' => 'b'], headers: ['accept' => 'application/json']))->status === 404);
$ipBefore = $ip;
$ip = 99;
$statuses = [];
for ($i = 0; $i < 4; $i++) {
    $ip = 99 - 1; // same visitor every time
    $statuses[] = $post(['name' => "R{$i}", 'message' => 'x'])->status;
}
wyn_check('rate limit per visitor', $statuses === [200, 200, 200, 429]);
$ip = $ipBefore;
$app->db->update('wyn_forms', ['enabled' => 0], 'id = :id', ['id' => $form['id']]);
$form = $app->forms->find((int) $formId);
wyn_check('closed form is refused', $post(['name' => 'Late', 'message' => 'x'])->status === 410);
$app->db->update('wyn_forms', ['enabled' => 1], 'id = :id', ['id' => $form['id']]);
$form = $app->forms->find((int) $formId);

// ---------------------------------------------------------------------------
echo "Spam\n";
$app->runDeferred();
$countBefore = (int) $app->db->value('SELECT COUNT(*) FROM wyn_deliveries');
$cases = [
    'honeypot'   => ['name' => 'Bot', 'message' => 'hi', 'wyn-hp' => 'filled'],
    'too fast'   => ['name' => 'Fast', 'message' => 'hi', 'wyn-elapsed' => '0.4'],
    'many links' => ['name' => 'Seo', 'message' => 'https://a.co https://b.co https://c.co https://d.co'],
    'bbcode'     => ['name' => 'Seo', 'message' => '[url=https://x.co]cheap[/url]'],
];
foreach ($cases as $label => $fields) {
    $res = $post($fields);
    $row = $last();
    wyn_check("{$label}: stored as spam, bot still sees success", $res->status === 200 && $json($res)['ok'] === true && !isset($json($res)['id']) && $row['status'] === 'spam');
}
$app->runDeferred();
wyn_check('spam sends no notifications', (int) $app->db->value('SELECT COUNT(*) FROM wyn_deliveries') === $countBefore);

$app->db->update('wyn_forms', ['captcha' => 'turnstile', 'captcha_secret' => 's'], 'id = :id', ['id' => $form['id']]);
$form = $app->forms->find((int) $formId);
wyn_check('CAPTCHA required when enabled', $post(['name' => 'C', 'message' => 'x'])->status === 403);
wyn_check('valid CAPTCHA passes', $post(['name' => 'C', 'message' => 'x', 'cf-turnstile-response' => 'tok'])->status === 200);
$captchaOk = false;
wyn_check('failed CAPTCHA is refused', $post(['name' => 'C', 'message' => 'x', 'cf-turnstile-response' => 'tok'])->status === 403);
wyn_check('CAPTCHA token is not stored', !str_contains((string) $app->db->value('SELECT data FROM wyn_submissions WHERE data LIKE ? LIMIT 1', ['%"C"%']), 'tok'));
$app->db->update('wyn_forms', ['captcha' => 'none'], 'id = :id', ['id' => $form['id']]);
$form = $app->forms->find((int) $formId);

// ---------------------------------------------------------------------------
echo "Files\n";
file_put_contents($tmp . '/a.txt', 'hello file');
$file = ['field' => 'cv', 'name' => '../../etc/<cv>.txt', 'tmp' => $tmp . '/a.txt', 'size' => 10, 'error' => UPLOAD_ERR_OK];
$res = $post(['name' => 'Filer', 'message' => 'see attached'], [], [$file]);
$stored = $app->db->one('SELECT * FROM wyn_files ORDER BY id DESC LIMIT 1');
wyn_check('upload stored with a random name outside public/', $res->status === 200 && preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}$#', $stored['stored_name']) === 1);
wyn_check('original name is sanitised', $stored['original_name'] === '_cv_.txt');
wyn_check('file content intact', file_get_contents((string) $app->uploads->path($stored['stored_name'])) === 'hello file');
$big = $file + [];
$big['size'] = 2 * 1024 * 1024;
wyn_check('oversized file refused', $post(['name' => 'Big', 'message' => 'x'], [], [$big])->status === 413);
wyn_check('too many files refused', $post(['name' => 'Many', 'message' => 'x'], [], array_fill(0, 6, $file))->status === 413);

// ---------------------------------------------------------------------------
echo "Browser flow\n";
$res = $post(['name' => 'Html', 'message' => 'x'], ['accept' => 'text/html', 'referer' => 'https://villa.test/contact']);
wyn_check('HTML post redirects to the thank-you page', $res->status === 303 && str_starts_with($res->headers['Location'], '/f/' . $form['key'] . '/thanks?back='));
$res = $post(['name' => 'Next', 'message' => 'x', 'wyn-next' => 'https://villa.test/thanks'], ['accept' => 'text/html']);
wyn_check('wyn-next to an allowed site is followed', $res->headers['Location'] === 'https://villa.test/thanks');
$res = $post(['name' => 'Evil', 'message' => 'x', 'wyn-next' => 'https://evil.test/phish'], ['accept' => 'text/html']);
wyn_check('wyn-next to another site is ignored (no open redirect)', !str_contains($res->headers['Location'], 'evil.test'));
$res = $post(['name' => '', 'message' => ''], ['accept' => 'text/html']);
wyn_check('HTML error page explains the problem', $res->status === 422 && str_contains($res->body, 'The form was empty'));
$pre = $app->handle(new Request('OPTIONS', '/f/' . $form['key'], headers: ['origin' => 'https://villa.test']));
wyn_check('CORS preflight', $pre->status === 204 && $pre->headers['Access-Control-Allow-Origin'] === 'https://villa.test');

// ---------------------------------------------------------------------------
echo "Dashboard\n";
$get = fn (string $path, array $query = []) => $app->handle(new Request('GET', $path, query: $query));
wyn_check('admin redirects to setup before an account exists', $get('/admin')->headers['Location'] === '/admin/setup');
$_SESSION = [];
$token = $app->session->csrfToken();
$app->handle(new Request('POST', '/admin/setup', post: ['_token' => $token, 'email' => 'me@example.com', 'password' => 'long-enough-pw']));
wyn_check('setup signs in', $app->auth->user()['email'] === 'me@example.com');
wyn_check('POST without CSRF token refused', $app->handle(new Request('POST', '/admin/forms', post: ['name' => 'X']))->status === 419);
wyn_check('forms list', str_contains($get('/admin')->body, 'Contact'));
wyn_check('inbox lists submissions', str_contains($get('/admin/forms/' . $form['id'])->body, 'Ketut'));
wyn_check('search', str_contains($get('/admin/forms/' . $form['id'], ['q' => 'Ketut'])->body, 'Ketut') && !str_contains($get('/admin/forms/' . $form['id'], ['q' => 'Ketut'])->body, 'Filer'));
wyn_check('spam folder', str_contains($get('/admin/forms/' . $form['id'], ['status' => 'spam'])->body, 'Filled the hidden honeypot field'));
$first = (int) $app->db->value("SELECT id FROM wyn_submissions WHERE status = 'inbox' ORDER BY id LIMIT 1");
wyn_check('opening a submission marks it read', $get('/admin/submissions/' . $first)->status === 200 && (int) $app->db->value('SELECT is_read FROM wyn_submissions WHERE id = ?', [$first]) === 1);
$dl = $get('/admin/files/' . $stored['id']);
wyn_check('file download is forced, never inline', $dl->body === 'hello file' && str_starts_with($dl->headers['Content-Disposition'], 'attachment') && $dl->headers['Content-Type'] === 'application/octet-stream');
$app->db->insert('wyn_submissions', ['form_id' => $form['id'], 'data' => json_encode(['name' => '=HYPERLINK("http://x")']), 'created_at' => time()]);
$csv = $get('/admin/forms/' . $form['id'] . '/export.csv')->body;
wyn_check('CSV export with formula injection neutralised', str_contains($csv, 'Ketut') && str_contains($csv, "'=HYPERLINK"));
$res = $app->handle(new Request('POST', '/admin/forms/' . $form['id'] . '/bulk', post: ['_token' => $token, 'do' => 'archived', 'ids' => [$first], 'status' => 'inbox']));
wyn_check('bulk archive', $res->status === 303 && $app->db->value('SELECT status FROM wyn_submissions WHERE id = ?', [$first]) === 'archived');
$app->handle(new Request('POST', '/admin/submissions/' . $stored['submission_id'] . '/delete', post: ['_token' => $token]));
wyn_check('deleting a submission deletes its files', $app->uploads->path($stored['stored_name']) === null);
$app->db->query("UPDATE wyn_submissions SET created_at = 0 WHERE status = 'spam'");
wyn_check('old spam is cleaned up', $app->cleanup(force: true) === 4 && (int) $app->db->value("SELECT COUNT(*) FROM wyn_submissions WHERE status = 'spam'") === 0);
wyn_check('bold theme by default, no external fonts', str_contains($get('/admin')->body, 'data-wyn-theme="wayan"') && !str_contains($get('/admin')->body, 'googleapis'));
$app->handle(new Request('POST', '/admin/settings', post: ['_token' => $token, 'site_name' => 'Wyn', 'theme' => 'neutral', 'spam_retention_days' => '30']));
wyn_check('neutral theme applies to dashboard and thank-you page', str_contains($get('/admin')->body, 'data-wyn-theme="neutral"') && str_contains($get('/f/' . $form['key'] . '/thanks')->body, 'data-wyn-theme="neutral"'));
$app->auth->logout();
wyn_check('signed out users go to login', $get('/admin')->headers['Location'] === '/admin/login');

echo PHP_EOL . ($wynFailed === 0 ? "\033[32m{$wynPassed} passed\033[0m" : "\033[31m{$wynFailed} failed\033[0m, {$wynPassed} passed") . PHP_EOL;
exit($wynFailed === 0 ? 0 : 1);
