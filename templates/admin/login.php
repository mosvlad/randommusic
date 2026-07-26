<?php
/** @var string $base */

use App\Http\View;
?>
<!doctype html>
<html lang="ru" data-theme="night">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Вход — Random music</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="<?= View::e($base) ?>/assets/css/app.css">
</head>
<body>
<div class="container">
  <div class="error-page">
    <h1 class="site-title" style="font-size:2rem">Модераторская</h1>
    <form method="get" action="<?= View::e($base) ?>/admin" class="chat__row" style="max-width:26rem">
      <input class="field" type="password" name="token" placeholder="Токен" autocomplete="current-password" required>
      <button class="button" type="submit">Войти</button>
    </form>
  </div>
</div>
</body>
</html>
