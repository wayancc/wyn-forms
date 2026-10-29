<?php

declare(strict_types=1);

namespace WynForms\Notify;

use WynForms\Database;
use WynForms\Forms;
use WynForms\Settings;

/**
 * Tells people about a new submission: email, webhook, Slack, Discord, Telegram.
 * Every delivery is logged, and one failing channel never stops the others.
 */
final class Notifier
{
    /** @var list<array{channel: string, ok: bool, error: ?string}> deliveries in this request (used by tests) */
    public array $log = [];

    public function __construct(
        private readonly Database $db,
        private readonly Settings $settings,
        private readonly string $adminUrl = '',
    ) {
    }

    /**
     * @param array $fields submitted fields, name => value
     * @param list<array{original_name: string, size: int, mime: string}> $files
     * @return list<array{channel: string, ok: bool, error: ?string}>
     */
    public function submission(array $form, int $submissionId, array $fields, array $files = [], ?string $subject = null, ?string $replyTo = null): array
    {
        $n = $form['notify'];
        $link = $this->adminUrl !== '' ? rtrim($this->adminUrl, '/') . '/admin/submissions/' . $submissionId : null;
        $title = 'New submission: ' . $form['name'];
        $lines = self::lines($fields, $files);
        $report = [];

        $send = function (string $channel, callable $fn) use (&$report, $form, $submissionId): void {
            $error = null;
            try {
                $fn();
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            $this->db->insert('wyn_deliveries', [
                'submission_id' => $submissionId ?: null,
                'form_id'       => $form['id'],
                'channel'       => $channel,
                'ok'            => (int) ($error === null),
                'error'         => $error,
                'sent_at'       => time(),
            ]);
            $report[] = $this->log[] = ['channel' => $channel, 'ok' => $error === null, 'error' => $error];
        };

        $emails = Forms::emails((string) ($n['emails'] ?? ''));
        if ($emails !== []) {
            $send('email', fn () => $this->email($emails, $subject ?: $title, $lines . ($link ? "\n\nOpen in Wyn Forms: {$link}" : ''), $replyTo));
        }

        if (!empty($n['webhook_url'])) {
            $send('webhook', function () use ($n, $form, $submissionId, $fields, $files) {
                $body = json_encode([
                    'event'      => $submissionId ? 'submission' : 'test',
                    'form'       => ['id' => (int) $form['id'], 'key' => $form['key'], 'name' => $form['name']],
                    'submission' => [
                        'id'         => $submissionId,
                        'created_at' => gmdate('c'),
                        'fields'     => (object) $fields,
                        'files'      => array_map(fn ($f) => ['name' => $f['original_name'], 'size' => (int) $f['size'], 'type' => $f['mime']], $files),
                    ],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $headers = ['Content-Type: application/json'];
                if (!empty($n['webhook_secret'])) {
                    $headers[] = 'X-Wyn-Signature: sha256=' . hash_hmac('sha256', $body, (string) $n['webhook_secret']);
                }
                HttpPost::send($n['webhook_url'], $body, $headers);
            });
        }

        $chat = '📬 ' . $title . "\n" . mb_substr($lines, 0, 1500) . ($link ? "\n" . $link : '');
        if (!empty($n['slack_url'])) {
            $send('slack', fn () => HttpPost::json($n['slack_url'], ['text' => $chat]));
        }
        if (!empty($n['discord_url'])) {
            $send('discord', fn () => HttpPost::json($n['discord_url'], ['content' => mb_substr($chat, 0, 1900)]));
        }
        if (!empty($n['telegram_token']) && !empty($n['telegram_chat'])) {
            $send('telegram', fn () => HttpPost::json(
                'https://api.telegram.org/bot' . rawurlencode($n['telegram_token']) . '/sendMessage',
                ['chat_id' => $n['telegram_chat'], 'text' => mb_substr($chat, 0, 4000), 'disable_web_page_preview' => true],
            ));
        }

        return $report;
    }

    /** Send a sample submission to every channel configured on a form. */
    public function test(array $form): array
    {
        return $this->submission($form, 0, ['name' => 'Test Person', 'email' => 'test@example.com', 'message' => 'This is a test from Wyn Forms. If you can read it, notifications work.'], [], 'Test notification: ' . $form['name']);
    }

    private function email(array $to, string $subject, string $body, ?string $replyTo): void
    {
        $from = $this->settings->get('smtp_from') ?: $this->settings->get('smtp_user') ?: 'wyn-forms@' . (gethostname() ?: 'localhost');
        $subject = trim((string) preg_replace('/[\r\n]+/', ' ', $subject));
        $body .= "\n\n— Sent by Wyn Forms";

        if ($this->settings->get('smtp_host') !== '') {
            (new Smtp(
                host: $this->settings->get('smtp_host'),
                port: (int) $this->settings->get('smtp_port'),
                encryption: $this->settings->get('smtp_encryption'),
                username: $this->settings->get('smtp_user'),
                password: $this->settings->get('smtp_pass'),
            ))->send($from, $to, $subject, $body, $replyTo);

            return;
        }

        $headers = "From: {$from}\r\n" . ($replyTo ? "Reply-To: {$replyTo}\r\n" : '') . "Content-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0";
        if (!@mail(implode(', ', $to), '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers)) {
            throw new \RuntimeException('PHP mail() failed. Add SMTP details under Settings.');
        }
    }

    private static function lines(array $fields, array $files): string
    {
        $out = [];
        foreach ($fields as $name => $value) {
            $value = (string) $value;
            $out[] = str_contains($value, "\n") ? "{$name}:\n{$value}" : "{$name}: {$value}";
        }
        foreach ($files as $f) {
            $out[] = 'Attachment: ' . $f['original_name'] . ' (' . self::size((int) $f['size']) . ')';
        }

        return implode("\n", $out);
    }

    public static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
