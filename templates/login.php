<?php
/**
 * @var string $base
 * @var string $token
 * @var string $error
 * @var string $next
 */

use App\Http\View;

$e = static fn($v): string => View::e($v);

$messages = [
    'bad_credentials' => 'Неверное имя или пароль',
    'bad_token'       => 'Форма устарела, обновите страницу',
    'too_fast'        => 'Слишком быстро — попробуйте ещё раз',
    'rate_limited'    => 'Слишком много попыток, подождите немного',
    'bot'             => 'Не похоже на человека',
];
$errorText = $messages[$error] ?? null;
?>
<!doctype html>
<html lang="ru" data-theme="night">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Вход — Random music</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="<?= $e($base) ?>/assets/css/app.css">
</head>
<body>
<div class="container">
  <p class="auth-card__back"><a class="topbar__brand" href="<?= $e($base) ?>/">← Random music</a></p>
  <div class="auth-card">
    <h1>Вход</h1>

    <div class="auth-card__status"<?= $errorText ? ' data-kind="error"' : '' ?>><?= $e($errorText ?? '') ?></div>

    <form method="post" action="<?= $e($base) ?>/login">
      <label>Имя
        <input class="field" type="text" name="username" autocomplete="username" required autofocus>
      </label>
      <label>Пароль
        <input class="field" type="password" name="password" autocomplete="current-password" required>
      </label>

      <button class="button" type="submit">Войти</button>

      <input type="hidden" name="token" value="<?= $e($token) ?>">
      <?php if ($next !== ''): ?>
        <input type="hidden" name="next" value="<?= $e($next) ?>">
      <?php endif; ?>
      <!-- Ловушка для ботов: человек этого поля не видит -->
      <div class="hp" aria-hidden="true">
        <label>Не заполняйте это поле
          <input type="text" name="website" tabindex="-1" autocomplete="off">
        </label>
      </div>
    </form>

    <p class="auth-card__links">Нет аккаунта? <a href="<?= $e($base) ?>/register">Зарегистрироваться</a></p>
  </div>
</div>
</body>
</html>
