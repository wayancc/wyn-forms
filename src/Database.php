<?php

declare(strict_types=1);

namespace WynForms;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper over a single SQLite file, with versioned migrations.
 */
final class Database
{
    public readonly PDO $pdo;

    public function __construct(string $path)
    {
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create the storage folder: {$dir}");
            }
            if (!is_writable($dir)) {
                throw new \RuntimeException("The storage folder isn't writable by PHP: {$dir}");
            }
        }

        $this->pdo = new PDO('sqlite:' . $path, options: [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');

        $this->migrate();
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        // Bind with real types: SQLite compares an integer with a string-bound value as text
        // inside expressions such as COALESCE(), which silently breaks time-range queries.
        foreach ($params as $key => $value) {
            $type = match (true) {
                is_int($value), is_bool($value) => PDO::PARAM_INT,
                $value === null                 => PDO::PARAM_NULL,
                default                         => PDO::PARAM_STR,
            };
            $stmt->bindValue(is_int($key) ? $key + 1 : ':' . ltrim($key, ':'), $value, $type);
        }
        $stmt->execute();

        return $stmt;
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array> */
    public function all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $this->query(
            sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $cols), implode(', ', array_map(fn ($c) => ':' . $c, $cols))),
            $data,
        );

        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(', ', array_map(fn ($c) => "{$c} = :set_{$c}", array_keys($data)));
        $bound = [];
        foreach ($data as $k => $v) {
            $bound['set_' . $k] = $v;
        }

        return $this->query("UPDATE {$table} SET {$set} WHERE {$where}", $bound + $params)->rowCount();
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function migrate(): void
    {
        $migrations = [
            1 => <<<'SQL'
                CREATE TABLE wyn_settings (
                    key   TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                );
                CREATE TABLE wyn_users (
                    id            INTEGER PRIMARY KEY,
                    email         TEXT NOT NULL UNIQUE,
                    password_hash TEXT NOT NULL,
                    created_at    INTEGER NOT NULL
                );
                CREATE TABLE wyn_login_attempts (
                    ip           TEXT NOT NULL,
                    attempted_at INTEGER NOT NULL
                );
                CREATE TABLE wyn_forms (
                    id                 INTEGER PRIMARY KEY,
                    key                TEXT NOT NULL UNIQUE,          -- public endpoint id: /f/{key}
                    name               TEXT NOT NULL,
                    enabled            INTEGER NOT NULL DEFAULT 1,
                    redirect_url       TEXT NOT NULL DEFAULT '',      -- where browsers go after submitting
                    allowed_origins    TEXT NOT NULL DEFAULT '',      -- one domain per line; empty = any
                    min_seconds        INTEGER NOT NULL DEFAULT 3,    -- time trap; 0 = off
                    rate_limit         INTEGER NOT NULL DEFAULT 10,   -- submissions per IP per 10 minutes
                    captcha            TEXT NOT NULL DEFAULT 'none',  -- none | turnstile | hcaptcha
                    captcha_secret     TEXT NOT NULL DEFAULT '',
                    accept_files       INTEGER NOT NULL DEFAULT 0,
                    max_file_mb        INTEGER NOT NULL DEFAULT 5,
                    notify             TEXT NOT NULL DEFAULT '{}',    -- JSON: emails, webhook, slack, discord, telegram
                    submission_count   INTEGER NOT NULL DEFAULT 0,
                    last_submission_at INTEGER,
                    created_at         INTEGER NOT NULL
                );
                CREATE TABLE wyn_submissions (
                    id          INTEGER PRIMARY KEY,
                    form_id     INTEGER NOT NULL REFERENCES wyn_forms(id) ON DELETE CASCADE,
                    data        TEXT NOT NULL,                        -- JSON object, field order preserved
                    email       TEXT,                                 -- copied from an "email" field, for search and reply
                    status      TEXT NOT NULL DEFAULT 'inbox',        -- inbox | spam | archived
                    spam_reason TEXT,
                    is_read     INTEGER NOT NULL DEFAULT 0,
                    ip_hash     TEXT,
                    user_agent  TEXT,
                    referrer    TEXT,
                    created_at  INTEGER NOT NULL
                );
                CREATE INDEX wyn_submissions_form ON wyn_submissions (form_id, status, created_at);
                CREATE INDEX wyn_submissions_ip ON wyn_submissions (form_id, ip_hash, created_at);
                CREATE TABLE wyn_files (
                    id            INTEGER PRIMARY KEY,
                    submission_id INTEGER NOT NULL REFERENCES wyn_submissions(id) ON DELETE CASCADE,
                    field         TEXT NOT NULL,
                    original_name TEXT NOT NULL,
                    stored_name   TEXT NOT NULL,
                    size          INTEGER NOT NULL,
                    mime          TEXT NOT NULL
                );
                CREATE TABLE wyn_deliveries (
                    id            INTEGER PRIMARY KEY,
                    submission_id INTEGER REFERENCES wyn_submissions(id) ON DELETE CASCADE,
                    form_id       INTEGER,
                    channel       TEXT NOT NULL,
                    ok            INTEGER NOT NULL,
                    error         TEXT,
                    sent_at       INTEGER NOT NULL
                );
                SQL,
        ];

        $current = (int) $this->pdo->query('PRAGMA user_version')->fetchColumn();
        foreach ($migrations as $version => $sql) {
            if ($version <= $current) {
                continue;
            }
            $this->pdo->beginTransaction();
            $this->pdo->exec($sql);
            $this->pdo->exec('PRAGMA user_version = ' . $version);
            $this->pdo->commit();
        }
    }
}
