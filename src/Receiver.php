<?php

declare(strict_types=1);

namespace WynForms;

use WynForms\Http\Request;
use WynForms\Notify\Notifier;

/**
 * Turns a POST to /f/{key} into a stored submission.
 *
 * Control fields (never stored):
 *   wyn-hp       honeypot, leave it empty and hidden
 *   wyn-next     where to send the visitor afterwards (must be on an allowed site)
 *   wyn-subject  subject line for the notification email
 *   wyn-elapsed  seconds since page load, set by wyn-forms.js (time trap)
 */
final class Receiver
{
    private const MAX_FIELDS = 100;
    private const MAX_VALUE = 20_000;
    private const CAPTCHA_FIELDS = ['cf-turnstile-response', 'h-captcha-response', 'g-recaptcha-response'];

    public function __construct(
        private readonly Database $db,
        private readonly Settings $settings,
        private readonly SpamGuard $guard,
        private readonly Uploads $uploads,
        private readonly Notifier $notifier,
    ) {
    }

    /**
     * @return array{ok: bool, status: int, message: string, id: ?int, redirect: ?string, spam: bool, notify: ?\Closure}
     */
    public function handle(array $form, Request $r, ?int $now = null): array
    {
        $now ??= time();

        if (!$form['enabled']) {
            return self::fail('This form is no longer accepting submissions.', 410);
        }

        $raw = $r->body !== '' ? json_decode($r->body, true) : $r->post;
        if (!is_array($raw)) {
            return self::fail('Send the form as regular form data or a JSON object.', 400);
        }

        $fields = self::fields($raw);
        $files = $r->files;
        if ($files !== [] && !$form['accept_files']) {
            return self::fail('This form doesn’t accept file uploads. Turn on file uploads in the form settings.', 422);
        }
        if ($files !== [] && ($error = Uploads::validate($files, (int) $form['max_file_mb']))) {
            return self::fail($error, 413);
        }

        $originHost = self::host($r->header('origin')) ?? self::host($r->header('referer'));
        $ipHash = substr(hash('sha256', $this->settings->ipSalt() . '|' . $r->ip), 0, 32);
        // Count file names as content too, so a files-only submission isn't "empty".
        $content = $fields + array_combine(array_map(fn ($f) => 'file:' . $f['field'] . count($fields), $files), array_column($files, 'name'));

        $verdict = $this->guard->evaluate($form, $raw, $content, $ipHash, $originHost, $r->ip, $now);
        if ($verdict['action'] === 'reject') {
            return self::fail((string) $verdict['reason'], $verdict['status']);
        }
        $spam = $verdict['action'] === 'spam';

        $email = self::findEmail($fields);
        $stored = [];
        $id = $this->db->transaction(function (Database $db) use ($form, $fields, $files, $spam, $verdict, $email, $ipHash, $r, $now, &$stored): int {
            $id = $db->insert('wyn_submissions', [
                'form_id'     => $form['id'],
                'data'        => json_encode((object) $fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'email'       => $email,
                'status'      => $spam ? 'spam' : 'inbox',
                'spam_reason' => $verdict['reason'],
                'ip_hash'     => $ipHash,
                'user_agent'  => mb_substr((string) $r->header('user-agent'), 0, 300),
                'referrer'    => mb_substr((string) $r->header('referer'), 0, 500),
                'created_at'  => $now,
            ]);

            // Spam keeps its text for review, but attachments from bots aren't worth the disk space.
            if (!$spam) {
                foreach ($files as $file) {
                    $info = $this->uploads->store($file, is_uploaded_file($file['tmp']));
                    $db->insert('wyn_files', ['submission_id' => $id, 'field' => mb_substr($file['field'], 0, 100)] + $info);
                    $stored[] = $info;
                }
                $db->query('UPDATE wyn_forms SET submission_count = submission_count + 1, last_submission_at = ? WHERE id = ?', [$now, $form['id']]);
            }

            return $id;
        });

        $subject = isset($raw['wyn-subject']) && is_string($raw['wyn-subject']) ? mb_substr($raw['wyn-subject'], 0, 200) : null;

        return [
            'ok'       => true,
            'status'   => 200,
            'message'  => 'Thanks! Your message has been sent.',
            'id'       => $spam ? null : $id,
            'redirect' => $this->redirectTarget($form, $raw, $originHost),
            'spam'     => $spam,
            // Run after the response is sent, so the visitor never waits on email or webhooks.
            'notify'   => $spam ? null : fn () => $this->notifier->submission($form, $id, $fields, $stored, $subject, $email),
        ];
    }

    /** Clean, flatten and cap the visitor's fields. Control fields and CAPTCHA tokens are dropped. */
    public static function fields(array $raw): array
    {
        $out = [];
        foreach ($raw as $name => $value) {
            $name = trim(str_replace("\0", '', (string) $name));
            if ($name === '' || str_starts_with($name, 'wyn-') || in_array($name, self::CAPTCHA_FIELDS, true)) {
                continue;
            }
            if (is_array($value)) {
                // Checkbox groups (interests[]=a&interests[]=b) become "a, b"
                $value = implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $value));
            } elseif (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            } elseif (!is_scalar($value) && $value !== null) {
                continue;
            }
            $value = str_replace(["\0", "\r\n", "\r"], ['', "\n", "\n"], trim((string) $value));
            $out[mb_substr($name, 0, 100)] = mb_substr($value, 0, self::MAX_VALUE);
            if (count($out) >= self::MAX_FIELDS) {
                break;
            }
        }

        return $out;
    }

    private function redirectTarget(array $form, array $raw, ?string $originHost): ?string
    {
        $next = isset($raw['wyn-next']) && is_string($raw['wyn-next']) ? trim($raw['wyn-next']) : '';
        if ($next !== '' && preg_match('#^https?://#i', $next)) {
            // Only follow wyn-next to sites this form trusts, so the endpoint can't be used as an open redirect.
            $host = self::host($next);
            $trusted = array_filter([...Forms::origins($form), self::host($form['redirect_url']), $originHost]);
            foreach ($trusted as $t) {
                if ($host !== null && ($host === $t || str_ends_with($host, '.' . $t))) {
                    return $next;
                }
            }
        }

        return $form['redirect_url'] !== '' ? $form['redirect_url'] : null;
    }

    private static function findEmail(array $fields): ?string
    {
        foreach ($fields as $name => $value) {
            if (preg_match('/e-?mail/i', (string) $name) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return mb_substr((string) $value, 0, 254);
            }
        }

        return null;
    }

    private static function host(?string $url): ?string
    {
        if ($url === null || $url === '' || $url === 'null') {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : null;
    }

    private static function fail(string $message, int $status): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message, 'id' => null, 'redirect' => null, 'spam' => false, 'notify' => null];
    }
}
