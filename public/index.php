<?php
declare(strict_types=1);

/**
 * Единственный PHP-файл в веб-корне. Код приложения лежит вне docroot,
 * поэтому исходники и базы недостижимы по HTTP физически, а не по
 * договорённости с .htaccess.
 */

define('APP_ROOT', '/home/faust_z/apps/randommusic');

require APP_ROOT . '/src/bootstrap.php';

App\Kernel::handle(App\Http\Request::capture())->send();
