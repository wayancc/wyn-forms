<?php

declare(strict_types=1);

namespace WynForms;

use WynForms\Notify\HttpPost;

/**
 * Decides what happens to a submission before it's stored.
 *
 *   reject → refused with an error (bad origin, rate limit, failed CAPTCHA, empty)
 *   spam   → stored in the Spam folder, no notifications, and the sender still sees "thanks"
 *            (telling a bot it was caught only teaches it to adapt)
 *   accept → stored in the Inbox and notifications go out
 */
final class SpamGuard
{
    public const HONEYPOT = 'wyn-hp';
    public const ELAPSED = 'wyn-elapsed';
    private const WINDOW = 600; // rate-limit window, seconds

    /** @var callable(string $url, array $fields): array */
    private $verifier;

    public function __construct(private readonly Database $db, ?callable $captchaVerifier = null)
    {
        $this->verifier = $captchaVerifier ?? static function (string $url, array $fields): array {
            $body = HttpPost::send($url, http_build_query($fields), ['Content-Type: application/x-www-form-urlencoded'], 8);

            return json_decode($body, true) ?: [];
        };
    }

    /**
     * @param array  $form   decoded form row
     * @param array  $raw    every submitted field, including wyn-* control fields
     * @param array  $fields the fields that will be stored
     * @return array{action: string, reason: ?string, status: int}
     */
    public function evaluate(array $form, array $raw, array $fields, string $ipHash, ?string $originHost, string $ip, int $now): array
    {
        // 1. Where the request came from
        $allowed = Forms::origins($form);
        if ($allowed !== [] && ($originHost === null || !self::hostAllowed($originHost, $allowed))) {
            return self::reject('This form doesn’t accept submissions from ' . ($originHost ?? 'an unknown site') . '.', 403);
        }

        // 2. How often this visitor has submitted
        $recent = (int) $this->db->value(
            'SELECT COUNT(*) FROM wyn_submissions WHERE form_id = ? AND ip_hash = ? AND created_at > ?',
            [$form['id'], $ipHash, $now - self::WINDOW],
        );
        if ($recent >= (int) $form['rate_limit']) {
            return self::reject('Too many submissions. Wait a few minutes and try again.', 429);
        }

        // 3. Nothing to store
        if (trim(implode('', array_map(fn ($v) => is_string($v) ? $v : '', $fields))) === '') {
            return self::reject('The form was empty.', 422);
        }

        // 4. CAPTCHA, when the form uses one
        if ($form['captcha'] !== 'none') {
            [$url, $field] = $form['captcha'] === 'turnstile'
                ? ['https://challenges.cloudflare.com/turnstile/v0/siteverify', 'cf-turnstile-response']
                : ['https://api.hcaptcha.com/siteverify', 'h-captcha-response'];
            $token = (string) ($raw[$field] ?? '');
            if ($token === '') {
                return self::reject('Complete the CAPTCHA and try again.', 403);
            }
            try {
                $result = ($this->verifier)($url, ['secret' => $form['captcha_secret'], 'response' => $token, 'remoteip' => $ip]);
            } catch (\Throwable) {
                $result = [];
            }
            if (empty($result['success'])) {
                return self::reject('The CAPTCHA check failed. Try again.', 403);
            }
        }

        // 5. Bot traps: stored as spam so you can still review them
        if (trim((string) ($raw[self::HONEYPOT] ?? '')) !== '') {
            return self::spam('Filled the hidden honeypot field');
        }
        if ((int) $form['min_seconds'] > 0 && isset($raw[self::ELAPSED]) && is_numeric($raw[self::ELAPSED])
            && (float) $raw[self::ELAPSED] < (int) $form['min_seconds']) {
            return self::spam('Submitted ' . round((float) $raw[self::ELAPSED], 1) . 's after the page loaded');
        }

        // 6. Content that real people rarely send to a contact form
        $text = implode("\n", array_map(fn ($v) => is_string($v) ? $v : '', $fields));
        $links = preg_match_all('#https?://|www\.#i', $text);
        if ($links > 3) {
            return self::spam("Contains {$links} links");
        }
        if (preg_match('/\[url=|<a\s+href=|\[link=/i', $text)) {
            return self::spam('Contains link markup');
        }

        return ['action' => 'accept', 'reason' => null, 'status' => 200];
    }

    private static function hostAllowed(string $host, array $allowed): bool
    {
        $host = strtolower($host);
        foreach ($allowed as $a) {
            // "example.com" also allows "www.example.com" and other subdomains
            if ($host === $a || str_ends_with($host, '.' . $a)) {
                return true;
            }
        }

        return false;
    }

    private static function reject(string $reason, int $status): array
    {
        return ['action' => 'reject', 'reason' => $reason, 'status' => $status];
    }

    private static function spam(string $reason): array
    {
        return ['action' => 'spam', 'reason' => $reason, 'status' => 200];
    }
}
