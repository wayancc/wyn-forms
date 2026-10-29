<?php

declare(strict_types=1);

namespace WynForms;

/**
 * Key/value settings edited from the dashboard.
 */
final class Settings
{
    public const DEFAULTS = [
        'site_name'           => 'Wyn Forms',
        'theme'               => 'wayan',   // wayan (bold, based on wayan.cc) | neutral
        'smtp_host'           => '',
        'smtp_port'           => '587',
        'smtp_encryption'     => 'tls',   // tls | ssl | none
        'smtp_user'           => '',
        'smtp_pass'           => '',
        'smtp_from'           => '',
        'spam_retention_days' => '30',    // spam is deleted after this many days
        'ip_salt'             => '',      // makes stored IP hashes useless outside this install
        'last_cleanup_at'     => '0',
    ];

    private ?array $cache = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $key): string
    {
        $this->cache ??= array_column($this->db->all('SELECT key, value FROM wyn_settings'), 'value', 'key');

        return (string) ($this->cache[$key] ?? self::DEFAULTS[$key] ?? '');
    }

    public function set(string $key, string $value): void
    {
        $this->db->query('INSERT INTO wyn_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$key, $value]);
        if ($this->cache !== null) {
            $this->cache[$key] = $value;
        }
    }

    /** Random salt for hashing visitor IPs. Created on first use. */
    public function ipSalt(): string
    {
        $salt = $this->get('ip_salt');
        if ($salt === '') {
            $salt = bin2hex(random_bytes(16));
            $this->set('ip_salt', $salt);
        }

        return $salt;
    }
}
