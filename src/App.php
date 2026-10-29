<?php

declare(strict_types=1);

namespace WynForms;

use WynForms\Controllers\AdminController;
use WynForms\Controllers\PublicController;
use WynForms\Http\Request;
use WynForms\Http\Response;
use WynForms\Http\Router;
use WynForms\Http\Session;
use WynForms\Notify\Notifier;

/**
 * Wires everything together. One instance per request (web) or per command (CLI).
 */
final class App
{
    public readonly Database $db;
    public readonly Settings $settings;
    public readonly Session $session;
    public readonly Auth $auth;
    public readonly Forms $forms;
    public readonly Uploads $uploads;
    public readonly Notifier $notifier;
    public readonly SpamGuard $guard;
    public readonly Receiver $receiver;

    /** @var list<\Closure> work to finish after the response has been sent */
    private array $deferred = [];

    public function __construct(public readonly Config $config, ?callable $captchaVerifier = null)
    {
        date_default_timezone_set((string) $config->get('timezone') ?: 'UTC');

        $this->db = new Database($config->databasePath());
        $this->settings = new Settings($this->db);
        $this->session = new Session();
        $this->auth = new Auth($this->db, $this->session);
        $this->forms = new Forms($this->db);
        $this->uploads = new Uploads(dirname($config->databasePath() === ':memory:' ? sys_get_temp_dir() . '/x' : $config->databasePath()) . '/uploads');
        $this->notifier = new Notifier($this->db, $this->settings, rtrim((string) $config->get('app_url'), '/'));
        $this->guard = new SpamGuard($this->db, $captchaVerifier);
        $this->receiver = new Receiver($this->db, $this->settings, $this->guard, $this->uploads, $this->notifier);
    }

    public static function boot(string $root, array $overrides = [], ?callable $captchaVerifier = null): self
    {
        return new self(Config::load($root, $overrides), $captchaVerifier);
    }

    public function handle(Request $request): Response
    {
        $isAdmin = str_starts_with($request->path, '/admin');
        if ($isAdmin) {
            $this->session->start($request->secure, $this->config->baseUrl());
        }

        $router = new Router();
        $public = new PublicController($this);
        $admin = new AdminController($this);

        $router->add('GET', '/', fn () => Response::redirect($this->url('admin')));
        $router->add('POST|OPTIONS', '/f/{key}', $public->submit(...));
        $router->add('GET', '/f/{key}', $public->endpointInfo(...));
        $router->add('GET', '/f/{key}/thanks', $public->thanks(...));

        $router->add('GET|POST', '/admin/setup', $admin->setup(...));
        $router->add('GET|POST', '/admin/login', $admin->login(...));
        $router->add('POST', '/admin/logout', $admin->logout(...));
        $router->add('GET', '/admin', $admin->forms(...));
        $router->add('GET', '/admin/forms/new', $admin->formSettings(...));
        $router->add('POST', '/admin/forms', $admin->formSave(...));
        $router->add('GET', '/admin/forms/{id}', $admin->inbox(...));
        $router->add('GET', '/admin/forms/{id}/settings', $admin->formSettings(...));
        $router->add('POST', '/admin/forms/{id}/settings', $admin->formSave(...));
        $router->add('GET', '/admin/forms/{id}/setup', $admin->formSetup(...));
        $router->add('GET', '/admin/forms/{id}/export.csv', $admin->export(...));
        $router->add('POST', '/admin/forms/{id}/{action}', $admin->formAction(...));
        $router->add('GET', '/admin/submissions/{id}', $admin->submission(...));
        $router->add('POST', '/admin/submissions/{id}/{action}', $admin->submissionAction(...));
        $router->add('GET', '/admin/files/{id}', $admin->download(...));
        $router->add('GET', '/admin/settings', $admin->settings(...));
        $router->add('POST', '/admin/settings', $admin->settingsSave(...));
        $router->add('POST', '/admin/settings/password', $admin->passwordSave(...));
        $router->add('POST', '/admin/settings/test-email', $admin->testEmail(...));

        if ($isAdmin && ($guard = $this->guard($request)) !== null) {
            return $guard;
        }

        try {
            return $router->dispatch($request) ?? $public->notFound();
        } catch (NotFound) {
            return $public->notFound();
        }
    }

    /** Queue work (notifications, cleanup) to run once the visitor has their response. */
    public function defer(\Closure $task): void
    {
        $this->deferred[] = $task;
    }

    public function runDeferred(): void
    {
        foreach ($this->deferred as $task) {
            try {
                $task();
            } catch (\Throwable $e) {
                error_log('[wyn-forms] ' . $e->getMessage());
            }
        }
        $this->deferred = [];
    }

    /** Daily housekeeping: drop old spam and expired login attempts. */
    public function cleanup(bool $force = false): int
    {
        $now = time();
        if (!$force && $now - (int) $this->settings->get('last_cleanup_at') < 86400) {
            return 0;
        }
        $cutoff = $now - max(1, (int) $this->settings->get('spam_retention_days')) * 86400;
        $old = $this->db->all("SELECT id FROM wyn_submissions WHERE status = 'spam' AND created_at < ?", [$cutoff]);
        foreach ($old as $row) {
            $this->deleteSubmission((int) $row['id']);
        }
        $this->db->query('DELETE FROM wyn_login_attempts WHERE attempted_at < ?', [$now - 86400]);
        $this->db->query('DELETE FROM wyn_deliveries WHERE sent_at < ?', [$now - 90 * 86400]);
        $this->settings->set('last_cleanup_at', (string) $now);

        return count($old);
    }

    public function deleteSubmission(int $id): void
    {
        foreach ($this->db->all('SELECT stored_name FROM wyn_files WHERE submission_id = ?', [$id]) as $f) {
            $this->uploads->remove($f['stored_name']);
        }
        $this->db->query('DELETE FROM wyn_submissions WHERE id = ?', [$id]);
    }

    /** Admin pages need a signed-in user (or first-run setup) and a CSRF token on every POST. */
    private function guard(Request $request): ?Response
    {
        if (!$this->auth->hasUsers()) {
            return $request->path === '/admin/setup' ? null : Response::redirect($this->url('admin/setup'));
        }
        if ($request->path === '/admin/setup') {
            return Response::redirect($this->url('admin'));
        }
        if ($request->path !== '/admin/login' && $this->auth->user() === null) {
            return Response::redirect($this->url('admin/login'));
        }
        if ($request->method === 'POST' && !$this->session->validCsrf($request->post['_token'] ?? null)) {
            return Response::html('<p>This form expired. Go back, reload the page and try again.</p>', 419);
        }

        return null;
    }

    public function view(): View
    {
        return new View(WYN_ROOT . '/templates', $this->config, $this->settings, $this->session, $this->auth->user());
    }

    public function url(string $path = ''): string
    {
        return $this->config->baseUrl() . ltrim($path, '/');
    }
}
