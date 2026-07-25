<?php
declare(strict_types=1);

/**
 * Точка инициализации: автозагрузчик и конфигурация.
 * Composer в рантайме сознательно не используется — приложение должно
 * работать из чистого git clone без единой внешней зависимости.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $rel  = str_replace('\\', '/', substr($class, 4));
    $file = APP_ROOT . '/src/' . $rel . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

\App\Support\Config::load();

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

// Веб работает под faust_z-www, CLI — под faust_z, оба в группе faust_z.
// Без этого файлы, созданные одним, второй записать не сможет: базы SQLite
// вместе с -wal/-shm пишут обе стороны.
umask(0002);

if (\App\Support\Config::isDebug()) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    $logDir = \App\Support\Config::varDir() . '/log';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    ini_set('error_log', $logDir . '/php-error.log');
}
