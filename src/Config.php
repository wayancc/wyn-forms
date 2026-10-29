<?php

declare(strict_types=1);

namespace WynForms;

/**
 * Server-level settings from config.php. Everything users change day to day
 * (site name, alerts, SMTP) lives in the database and is edited in the dashboard.
 */
final class Config
{
    private const DEFAULTS = [
        'database'    => 'storage/wyn-forms.sqlite',
        'base_url'    => '/',          // path prefix when installed in a subfolder
        'app_url'     => '',           // full URL used in alert links, e.g. https://status.example.com
        'timezone'    => 'UTC',
        'trust_proxy' => false,        // read client IP from X-Forwarded-For (behind Cloudflare / a load balancer)
        'user_agent'  => 'WynForms/0.1',
    ];

    private function __construct(private array $data, public readonly string $root)
    {
    }

    public static function load(string $root, array $overrides = []): self
    {
        $file = $root . '/config.php';
        $user = is_file($file) ? require $file : [];
        $data = array_replace(self::DEFAULTS, is_array($user) ? $user : [], $overrides);
        if ($env = getenv('WYN_DATABASE')) {
            $data['database'] = $env;
        }
        $data['base_url'] = rtrim('/' . trim((string) $data['base_url'], '/'), '/') . '/';

        return new self($data, $root);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function databasePath(): string
    {
        $path = (string) $this->data['database'];

        return str_starts_with($path, '/') || $path === ':memory:' ? $path : $this->root . '/' . $path;
    }

    public function baseUrl(): string
    {
        return $this->data['base_url'];
    }
}
