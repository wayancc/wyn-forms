<?php

declare(strict_types=1);

namespace WynForms;

/**
 * Form definitions: endpoint key, where to redirect, who to notify, and spam settings.
 */
final class Forms
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array> forms with unread inbox counts */
    public function all(): array
    {
        return $this->db->all(
            "SELECT f.*, (SELECT COUNT(*) FROM wyn_submissions s WHERE s.form_id = f.id AND s.status = 'inbox' AND s.is_read = 0) AS unread
             FROM wyn_forms f ORDER BY f.name COLLATE NOCASE",
        );
    }

    public function find(int $id): ?array
    {
        return self::decode($this->db->one('SELECT * FROM wyn_forms WHERE id = ?', [$id]));
    }

    public function findByKey(string $key): ?array
    {
        return self::decode($this->db->one('SELECT * FROM wyn_forms WHERE key = ?', [$key]));
    }

    public function defaults(): array
    {
        return [
            'id' => null, 'name' => '', 'enabled' => 1, 'redirect_url' => '', 'allowed_origins' => '',
            'min_seconds' => 3, 'rate_limit' => 10, 'captcha' => 'none', 'captcha_secret' => '',
            'accept_files' => 0, 'max_file_mb' => 5,
            'notify' => ['emails' => '', 'webhook_url' => '', 'webhook_secret' => '', 'slack_url' => '', 'discord_url' => '', 'telegram_token' => '', 'telegram_chat' => ''],
        ];
    }

    /**
     * @return array{0: ?int, 1: array<string, string>, 2: array} [id, errors, normalized input]
     */
    public function save(array $in, ?int $id = null): array
    {
        $notifyIn = is_array($in['notify'] ?? null) ? $in['notify'] : [];
        $notify = [];
        foreach (array_keys($this->defaults()['notify']) as $key) {
            $notify[$key] = trim((string) ($notifyIn[$key] ?? ''));
        }

        $data = [
            'name'            => mb_substr(trim((string) ($in['name'] ?? '')), 0, 100),
            'enabled'         => (int) !empty($in['enabled']),
            'redirect_url'    => trim((string) ($in['redirect_url'] ?? '')),
            'allowed_origins' => self::normalizeOrigins((string) ($in['allowed_origins'] ?? '')),
            'min_seconds'     => max(0, min(60, (int) ($in['min_seconds'] ?? 3))),
            'rate_limit'      => max(1, min(1000, (int) ($in['rate_limit'] ?? 10))),
            'captcha'         => in_array($captcha = (string) ($in['captcha'] ?? 'none'), ['none', 'turnstile', 'hcaptcha'], true) ? $captcha : 'none',
            'captcha_secret'  => trim((string) ($in['captcha_secret'] ?? '')),
            'accept_files'    => (int) !empty($in['accept_files']),
            'max_file_mb'     => max(1, min(50, (int) ($in['max_file_mb'] ?? 5))),
        ];

        $errors = [];
        if ($data['name'] === '') {
            $errors['name'] = 'Enter a name, for example “Contact form”.';
        }
        if ($data['redirect_url'] !== '' && !self::isHttpUrl($data['redirect_url'])) {
            $errors['redirect_url'] = 'Enter a full URL starting with https://';
        }
        if ($data['captcha'] !== 'none' && $data['captcha_secret'] === '') {
            $errors['captcha_secret'] = 'Enter the secret key from your CAPTCHA provider.';
        }
        foreach (self::emails($notify['emails']) as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['notify_emails'] = "“{$email}” isn’t a valid email address.";
            }
        }
        foreach (['webhook_url', 'slack_url', 'discord_url'] as $key) {
            if ($notify[$key] !== '' && !self::isHttpUrl($notify[$key])) {
                $errors['notify_' . $key] = 'Enter a full URL starting with https://';
            }
        }
        if (($notify['telegram_token'] === '') !== ($notify['telegram_chat'] === '')) {
            $errors['notify_telegram'] = 'Telegram needs both the bot token and the chat ID.';
        }

        $data['notify'] = json_encode($notify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($errors !== []) {
            return [null, $errors, ['notify' => $notify, 'id' => $id] + $data];
        }

        if ($id === null) {
            $data['key'] = self::newKey();
            $data['created_at'] = time();

            return [$this->db->insert('wyn_forms', $data), [], $data];
        }

        $this->db->update('wyn_forms', $data, 'id = :id', ['id' => $id]);

        return [$id, [], $data];
    }

    public function delete(int $id, Uploads $uploads): void
    {
        foreach ($this->db->all('SELECT f.stored_name FROM wyn_files f JOIN wyn_submissions s ON s.id = f.submission_id WHERE s.form_id = ?', [$id]) as $file) {
            $uploads->remove($file['stored_name']);
        }
        $this->db->query('DELETE FROM wyn_forms WHERE id = ?', [$id]);
    }

    /** Give the form a new endpoint URL, e.g. after it leaked to spammers. */
    public function rotateKey(int $id): string
    {
        $key = self::newKey();
        $this->db->update('wyn_forms', ['key' => $key], 'id = :id', ['id' => $id]);

        return $key;
    }

    public static function decode(?array $form): ?array
    {
        if ($form !== null) {
            $form['notify'] = json_decode((string) $form['notify'], true) ?: [];
        }

        return $form;
    }

    /** @return list<string> */
    public static function emails(string $list): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $list) ?: [])));
    }

    /** @return list<string> lower-case hosts */
    public static function origins(array $form): array
    {
        return array_values(array_filter(explode("\n", (string) $form['allowed_origins'])));
    }

    private static function normalizeOrigins(string $text): string
    {
        $hosts = [];
        foreach (preg_split('/[\s,]+/', strtolower($text)) ?: [] as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $host = str_contains($item, '://') ? (string) parse_url($item, PHP_URL_HOST) : explode('/', $item)[0];
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return implode("\n", array_unique($hosts));
    }

    private static function isHttpUrl(string $url): bool
    {
        return (bool) filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private static function newKey(): string
    {
        // 12 chars from an unambiguous alphabet: short enough to paste, too long to guess.
        $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
        $key = '';
        for ($i = 0; $i < 12; $i++) {
            $key .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $key;
    }
}
