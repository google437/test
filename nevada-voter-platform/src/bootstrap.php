<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $path = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

require_once APP_ROOT . '/src/Core/helpers.php';

App\Core\Config::load(APP_ROOT . '/.env');
date_default_timezone_set(App\Core\Config::get('TIMEZONE', 'America/Los_Angeles'));
mb_internal_encoding('UTF-8');

// No voter PII ever reaches logs: we log exception class, message and location only, never request bodies.
set_exception_handler(function (Throwable $e): void {
    App\Core\Log::error(get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    if (App\Core\Config::get('APP_ENV') === 'development') {
        echo '<pre>' . htmlspecialchars((string) $e) . '</pre>';
    } else {
        echo '<!doctype html><title>Error</title><p>Something went wrong. The error has been logged.</p>';
    }
});
