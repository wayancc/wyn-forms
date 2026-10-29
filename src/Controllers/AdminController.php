<?php

declare(strict_types=1);

namespace WynForms\Controllers;

use WynForms\App;
use WynForms\Http\Request;
use WynForms\Http\Response;
use WynForms\NotFound;
use WynForms\Notify\Smtp;
use WynForms\Settings;
use WynForms\Urls;

final class AdminController
{
    private const PER_PAGE = 50;
    private const STATUSES = ['inbox' => 'Inbox', 'spam' => 'Spam', 'archived' => 'Archived'];

    public function __construct(private readonly App $app)
    {
    }

    // ---------------------------------------------------------------- Auth

    public function setup(Request $r): Response
    {
        $errors = [];
        if ($r->method === 'POST' && !$this->app->session->validCsrf($r->post['_token'] ?? null)) {
            $errors['email'] = 'Your session expired. Try again.';
        } elseif ($r->method === 'POST') {
            $email = $r->input('email');
            $password = (string) ($r->post['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Enter a valid email address.';
            }
            if (strlen($password) < 10) {
                $errors['password'] = 'Use at least 10 characters.';
            }
            if ($errors === []) {
                $id = $this->app->auth->createUser($email, $password);
                $this->app->settings->set('site_name', $r->input('site_name') ?: 'Wyn Forms');
                $this->app->auth->login($id);
                $this->app->session->flash('Account created. Make your first form.');

                return Response::redirect($this->app->url('admin/forms/new'));
            }
        }

        return $this->page('admin/setup', ['errors' => $errors, 'old' => $r->post], 'Set up', bare: true);
    }

    public function login(Request $r): Response
    {
        $error = null;
        if ($r->method === 'POST') {
            if ($this->app->auth->isThrottled($r->ip)) {
                $error = 'Too many attempts. Wait 15 minutes and try again.';
            } elseif ($this->app->auth->attempt($r->input('email'), (string) ($r->post['password'] ?? ''), $r->ip)) {
                return Response::redirect($this->app->url('admin'));
            } else {
                $error = 'That email and password don’t match.';
            }
        }

        return $this->page('admin/login', ['error' => $error, 'email' => $r->input('email')], 'Sign in', bare: true);
    }

    public function logout(Request $r): Response
    {
        $this->app->auth->logout();

        return Response::redirect($this->app->url('admin/login'));
    }

    // ---------------------------------------------------------------- Forms

    public function forms(Request $r): Response
    {
        $forms = $this->app->forms->all();
        $week = (int) $this->app->db->value("SELECT COUNT(*) FROM wyn_submissions WHERE status != 'spam' AND created_at > ?", [time() - 7 * 86400]);

        return $this->page('admin/forms', ['forms' => $forms, 'week' => $week], 'Forms', 'forms');
    }

    public function formSettings(Request $r, ?string $id = null): Response
    {
        $form = $id !== null ? $this->form($id) : $this->app->forms->defaults();

        return $this->page('admin/form-settings', ['form' => $form, 'errors' => []], $id ? 'Settings' : 'New form', $id ? 'settings-tab' : 'forms');
    }

    public function formSave(Request $r, ?string $id = null): Response
    {
        $existing = $id !== null ? $this->form($id) : null;
        [$savedId, $errors, $data] = $this->app->forms->save($r->post, $existing ? (int) $existing['id'] : null);

        if ($errors !== []) {
            return $this->page('admin/form-settings', [
                'form'   => $data + ['id' => $existing['id'] ?? null, 'key' => $existing['key'] ?? null],
                'errors' => $errors,
            ], $existing ? 'Settings' : 'New form', $existing ? 'settings-tab' : 'forms', 422);
        }

        if ($existing) {
            $this->app->session->flash('Settings saved.');

            return Response::redirect($this->app->url('admin/forms/' . $savedId . '/settings'));
        }

        $this->app->session->flash('Form created. Copy the code below into your website.');

        return Response::redirect($this->app->url('admin/forms/' . $savedId . '/setup'));
    }

    public function formSetup(Request $r, string $id): Response
    {
        $form = $this->form($id);

        return $this->page('admin/form-setup', [
            'form'     => $form,
            'endpoint' => Urls::absolute($this->app->config, 'f/' . $form['key']),
            'script'   => Urls::absolute($this->app->config, 'assets/wyn-forms.js'),
        ], 'Add to your site', 'setup-tab');
    }

    public function formAction(Request $r, string $id, string $action): Response
    {
        $form = $this->form($id);
        $fid = (int) $form['id'];

        switch ($action) {
            case 'delete':
                if ($r->input('confirm_name') !== $form['name']) {
                    $this->app->session->flash('Type the form name exactly to delete it.', 'error');

                    return Response::redirect($this->app->url("admin/forms/{$fid}/settings"));
                }
                $this->app->forms->delete($fid, $this->app->uploads);
                $this->app->session->flash("{$form['name']} and all its submissions were deleted.");

                return Response::redirect($this->app->url('admin'));

            case 'rotate':
                $this->app->forms->rotateKey($fid);
                $this->app->session->flash('The form has a new address. Update the action URL on your website, or submissions will fail.', 'warn');

                return Response::redirect($this->app->url("admin/forms/{$fid}/setup"));

            case 'test':
                $report = $this->app->notifier->test($form);
                if ($report === []) {
                    $this->app->session->flash('No notifications are set up for this form yet.', 'warn');
                } else {
                    foreach ($report as $line) {
                        $this->app->session->flash(ucfirst($line['channel']) . ($line['ok'] ? ': sent.' : ': failed. ' . $line['error']), $line['ok'] ? 'ok' : 'error');
                    }
                }

                return Response::redirect($this->app->url("admin/forms/{$fid}/settings#notifications"));

            case 'bulk':
                $ids = array_map('intval', (array) ($r->post['ids'] ?? []));
                $do = $r->input('do');
                $count = 0;
                foreach ($ids as $sid) {
                    $sub = $this->app->db->one('SELECT id FROM wyn_submissions WHERE id = ? AND form_id = ?', [$sid, $fid]);
                    if ($sub === null) {
                        continue;
                    }
                    $count++;
                    match ($do) {
                        'delete'               => $this->app->deleteSubmission($sid),
                        'read', 'unread'       => $this->app->db->update('wyn_submissions', ['is_read' => (int) ($do === 'read')], 'id = :id', ['id' => $sid]),
                        'inbox', 'spam', 'archived' => $this->app->db->update('wyn_submissions', ['status' => $do], 'id = :id', ['id' => $sid]),
                        default                => $count--,
                    };
                }
                $label = ['delete' => 'deleted', 'read' => 'marked read', 'unread' => 'marked unread', 'inbox' => 'moved to Inbox', 'spam' => 'moved to Spam', 'archived' => 'archived'][$do] ?? 'updated';
                $this->app->session->flash($count === 0 ? 'Select at least one submission.' : "{$count} " . ($count === 1 ? 'submission' : 'submissions') . " {$label}.", $count ? 'ok' : 'warn');

                return Response::redirect($this->app->url("admin/forms/{$fid}") . '?status=' . rawurlencode($r->input('status', 'inbox')));
        }

        throw new NotFound();
    }

    // ---------------------------------------------------------------- Submissions

    public function inbox(Request $r, string $id): Response
    {
        $form = $this->form($id);
        $status = array_key_exists($r->input('status'), self::STATUSES) ? $r->input('status') : 'inbox';
        $q = mb_substr($r->input('q'), 0, 100);
        $page = max(1, (int) $r->input('page', '1'));

        [$where, $params] = $this->filter((int) $form['id'], $status, $q);
        $total = (int) $this->app->db->value("SELECT COUNT(*) FROM wyn_submissions s WHERE {$where}", $params);
        $rows = $this->app->db->all(
            "SELECT s.*, (SELECT COUNT(*) FROM wyn_files f WHERE f.submission_id = s.id) AS file_count FROM wyn_submissions s WHERE {$where} ORDER BY s.created_at DESC, s.id DESC LIMIT ? OFFSET ?",
            [...$params, self::PER_PAGE, ($page - 1) * self::PER_PAGE],
        );
        $counts = array_column($this->app->db->all('SELECT status, COUNT(*) AS n FROM wyn_submissions WHERE form_id = ? GROUP BY status', [$form['id']]), 'n', 'status');

        return $this->page('admin/inbox', [
            'form'     => $form,
            'rows'     => $rows,
            'status'   => $status,
            'statuses' => self::STATUSES,
            'counts'   => $counts,
            'q'        => $q,
            'page'     => $page,
            'pages'    => max(1, (int) ceil($total / self::PER_PAGE)),
            'total'    => $total,
        ], $form['name'], 'inbox-tab');
    }

    public function submission(Request $r, string $id): Response
    {
        $s = $this->app->db->one('SELECT * FROM wyn_submissions WHERE id = ?', [(int) $id]) ?? throw new NotFound();
        $form = $this->app->forms->find((int) $s['form_id']) ?? throw new NotFound();
        if (!$s['is_read']) {
            $this->app->db->update('wyn_submissions', ['is_read' => 1], 'id = :id', ['id' => $s['id']]);
        }

        $neighbour = fn (string $op, string $dir) => $this->app->db->value(
            "SELECT id FROM wyn_submissions WHERE form_id = ? AND status = ? AND id {$op} ? ORDER BY id {$dir} LIMIT 1",
            [$s['form_id'], $s['status'], $s['id']],
        );

        return $this->page('admin/submission', [
            'form'       => $form,
            's'          => $s,
            'data'       => json_decode($s['data'], true) ?: [],
            'files'      => $this->app->db->all('SELECT * FROM wyn_files WHERE submission_id = ?', [$s['id']]),
            'deliveries' => $this->app->db->all('SELECT * FROM wyn_deliveries WHERE submission_id = ? ORDER BY id', [$s['id']]),
            'newer'      => $neighbour('>', 'ASC'),
            'older'      => $neighbour('<', 'DESC'),
        ], 'Submission #' . $s['id'], 'inbox-tab');
    }

    public function submissionAction(Request $r, string $id, string $action): Response
    {
        $s = $this->app->db->one('SELECT * FROM wyn_submissions WHERE id = ?', [(int) $id]) ?? throw new NotFound();
        $back = $this->app->url('admin/forms/' . $s['form_id']) . '?status=' . $s['status'];

        switch ($action) {
            case 'delete':
                $this->app->deleteSubmission((int) $s['id']);
                $this->app->session->flash('Submission deleted.');

                return Response::redirect($back);
            case 'unread':
                $this->app->db->update('wyn_submissions', ['is_read' => 0], 'id = :id', ['id' => $s['id']]);

                return Response::redirect($back);
            case 'inbox':
            case 'spam':
            case 'archived':
                $this->app->db->update('wyn_submissions', ['status' => $action], 'id = :id', ['id' => $s['id']]);
                $this->app->session->flash(['inbox' => 'Moved to Inbox.', 'spam' => 'Marked as spam.', 'archived' => 'Archived.'][$action]);

                return Response::redirect($back);
        }

        throw new NotFound();
    }

    public function download(Request $r, string $id): Response
    {
        $file = $this->app->db->one('SELECT * FROM wyn_files WHERE id = ?', [(int) $id]) ?? throw new NotFound();
        $path = $this->app->uploads->path($file['stored_name']) ?? throw new NotFound();

        // Always a download, never rendered inline: an uploaded HTML or SVG file can't run in the dashboard.
        return new Response(200, (string) file_get_contents($path), [
            'Content-Type'            => 'application/octet-stream',
            'Content-Disposition'     => "attachment; filename*=UTF-8''" . rawurlencode($file['original_name']),
            'Content-Length'          => (string) filesize($path),
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control'           => 'private, no-store',
        ]);
    }

    public function export(Request $r, string $id): Response
    {
        $form = $this->form($id);
        $status = array_key_exists($r->input('status'), self::STATUSES) ? $r->input('status') : 'inbox';
        [$where, $params] = $this->filter((int) $form['id'], $status, mb_substr($r->input('q'), 0, 100));
        $rows = $this->app->db->all("SELECT s.* FROM wyn_submissions s WHERE {$where} ORDER BY s.created_at", $params);

        // Columns: every field name in the order it first appears.
        $columns = [];
        $decoded = [];
        foreach ($rows as $row) {
            $data = json_decode($row['data'], true) ?: [];
            $decoded[] = [$row, $data];
            foreach (array_keys($data) as $key) {
                $columns[$key] = true;
            }
        }
        $columns = array_keys($columns);

        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8 correctly
        fputcsv($out, ['Submitted', 'ID', ...$columns], escape: '');
        foreach ($decoded as [$row, $data]) {
            $line = [date('Y-m-d H:i:s', (int) $row['created_at']), $row['id']];
            foreach ($columns as $c) {
                $line[] = self::csvSafe((string) ($data[$c] ?? ''));
            }
            fputcsv($out, $line, escape: '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        $name = preg_replace('/[^a-z0-9]+/', '-', strtolower($form['name'])) . '-' . $status . '-' . date('Y-m-d') . '.csv';

        return new Response(200, $csv, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    // ---------------------------------------------------------------- Settings

    public function settings(Request $r): Response
    {
        $values = [];
        foreach (array_keys(Settings::DEFAULTS) as $key) {
            $values[$key] = $this->app->settings->get($key);
        }

        return $this->page('admin/settings', ['s' => $values], 'Settings', 'settings');
    }

    public function settingsSave(Request $r): Response
    {
        foreach (['site_name', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'smtp_from'] as $key) {
            $this->app->settings->set($key, mb_substr($r->input($key), 0, 300));
        }
        if (($pass = (string) ($r->post['smtp_pass'] ?? '')) !== '') {
            $this->app->settings->set('smtp_pass', $pass);
        }
        $this->app->settings->set('theme', $r->input('theme') === 'neutral' ? 'neutral' : 'wayan');
        $this->app->settings->set('spam_retention_days', (string) max(1, min(365, (int) $r->input('spam_retention_days'))));
        $this->app->session->flash('Settings saved.');

        return Response::redirect($this->app->url('admin/settings'));
    }

    public function testEmail(Request $r): Response
    {
        $to = $this->app->auth->user()['email'];
        $s = $this->app->settings;
        try {
            if ($s->get('smtp_host') === '') {
                throw new \RuntimeException('Add an SMTP host first.');
            }
            (new Smtp($s->get('smtp_host'), (int) $s->get('smtp_port'), $s->get('smtp_encryption'), $s->get('smtp_user'), $s->get('smtp_pass')))
                ->send($s->get('smtp_from') ?: $s->get('smtp_user'), [$to], 'Wyn Forms test email', "SMTP works. Form notifications will arrive like this.\n\n— Wyn Forms");
            $this->app->session->flash("Test email sent to {$to}.");
        } catch (\Throwable $e) {
            $this->app->session->flash('Couldn’t send: ' . $e->getMessage(), 'error');
        }

        return Response::redirect($this->app->url('admin/settings#email'));
    }

    public function passwordSave(Request $r): Response
    {
        $user = $this->app->auth->user();
        $new = (string) ($r->post['new'] ?? '');
        if (!$this->app->auth->verifyPassword((int) $user['id'], (string) ($r->post['current'] ?? ''))) {
            $this->app->session->flash('Your current password is incorrect.', 'error');
        } elseif (strlen($new) < 10) {
            $this->app->session->flash('Use at least 10 characters for the new password.', 'error');
        } else {
            $this->app->auth->changePassword((int) $user['id'], $new);
            $this->app->session->flash('Password changed.');
        }

        return Response::redirect($this->app->url('admin/settings#account'));
    }

    // ---------------------------------------------------------------- Helpers

    private function page(string $template, array $vars, string $title, string $nav = '', int $status = 200, bool $bare = false): Response
    {
        $html = $this->app->view()->render($template, $vars + ['title' => $title, 'nav' => $nav, 'bare' => $bare]);

        return new Response($status, $html, [
            'Content-Type'    => 'text/html; charset=utf-8',
            'Cache-Control'   => 'no-store',
            'X-Frame-Options' => 'DENY',
        ]);
    }

    private function form(string $id): array
    {
        return $this->app->forms->find((int) $id) ?? throw new NotFound();
    }

    /** @return array{0: string, 1: array} */
    private function filter(int $formId, string $status, string $q): array
    {
        $where = 's.form_id = ? AND s.status = ?';
        $params = [$formId, $status];
        if ($q !== '') {
            $where .= " AND (s.data LIKE ? ESCAPE '\\' OR s.email LIKE ? ESCAPE '\\')";
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $params[] = $like;
            $params[] = $like;
        }

        return [$where, $params];
    }

    /** Stop spreadsheet apps from treating a submitted value as a formula. */
    private static function csvSafe(string $value): string
    {
        return $value !== '' && str_contains('=+-@', $value[0]) ? "'" . $value : $value;
    }
}
