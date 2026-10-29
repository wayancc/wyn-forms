<?php

declare(strict_types=1);

/*
 * Wyn Forms has no Composer dependencies. This file is the whole autoloader.
 */

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('Wyn Forms needs PHP 8.2 or newer. This server runs PHP ' . PHP_VERSION . '.');
}

foreach (['pdo_sqlite', 'curl', 'openssl'] as $ext) {
    if (!extension_loaded($ext)) {
        http_response_code(500);
        exit("Wyn Forms needs the PHP extension \"{$ext}\". Enable it in your host's PHP settings.");
    }
}

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'WynForms\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

define('WYN_ROOT', dirname(__DIR__));
define('WYN_VERSION', '0.1.0');
