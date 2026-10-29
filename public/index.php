<?php

declare(strict_types=1);

// PHP's built-in dev server: serve real files (CSS, JS) directly.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $app = WynForms\App::boot(WYN_ROOT);
    $request = WynForms\Http\Request::fromGlobals($app->config->baseUrl(), (bool) $app->config->get('trust_proxy'));
    $response = $app->handle($request);
    $response->send();

    // Hand the visitor their response now, then send notifications.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } else {
        flush();
    }
    ignore_user_abort(true);
    $app->runDeferred();
} catch (Throwable $e) {
    error_log('[wyn-forms] ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "Wyn Forms hit an error and couldn't finish this request.\n\n";
    echo $e instanceof RuntimeException && str_contains($e->getMessage(), 'storage')
        ? $e->getMessage() . "\n"
        : "Details were written to the PHP error log.\n";
}
