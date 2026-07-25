<?php
/** @var int $code */

use App\Http\View;

$titles = [
    404 => 'Такой страницы нет',
    500 => 'Что-то сломалось',
];
$title = $titles[$code] ?? 'Ошибка';
?>
<!doctype html>
<html lang="ru" data-theme="night">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($code) ?> — Random music</title>
<meta name="robots" content="noindex">
<link rel="icon" href="/assets/img/icon.png" type="image/png">
<link rel="stylesheet" href="/assets/css/fonts.css">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="container">
  <div class="error-page">
    <div class="error-page__code"><?= View::e($code) ?></div>
    <h1 class="site-title" style="font-size:2rem"><?= View::e($title) ?></h1>
    <p><a href="/">Вернуться к музыке</a></p>
  </div>
</div>
</body>
</html>
