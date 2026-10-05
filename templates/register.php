<?php
/**
 * @var string $base
 * @var string $token
 * @var string $error
 * @var int    $usernameMin
 * @var int    $usernameMax
 * @var int    $passwordMin
 */

use App\Http\View;

$e = static fn($v): string => View::e($v);

$messages = [
    'username_invalid'   => "Имя: $usernameMin–$usernameMax символов, буквы/цифры/подчёркивание/дефис",
    'username_taken'     => 'Это имя уже занято',
    'password_weak'      => "Пароль короче $passwordMin символов",
    'password_mismatch'  => 'Пароли не совпадают',
    'bad_token'          => 'Форма устарела, обновите страницу',
    'too_fast'           => 'Слишком быстро — попробуйте ещё раз',
    'rate_limited'       => 'Слишком много попыток, подождите немного',
    'bot'                => 'Не похоже на человека',
];
$errorText = $messages[$error] ?? null;
?>
<!doctype html>
<html lang="ru" data-theme="night">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Регистрация — Random music</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="<?= $e($base) ?>/assets/css/app.css">
</head>
<body>
<div class="container">
  <p class="auth-card__back"><a class="topbar__brand" href="<?= $e($base) ?>/">← Random music</a></p>
  <div class="auth-card">
    <h1>Регистрация</h1>
    <p class="auth-card__hint" style="text-align:center">
      Аккаунт нужен только для одного — загрузки своего трека. Без email;
      забыли пароль — заводите новый.
    </p>

    <div class="auth-card__status"<?= $errorText ? ' data-kind="error"' : '' ?>><?= $e($errorText ?? '') ?></div>

    <form method="post" action="<?= $e($base) ?>/register">
      <label>Имя
        <input class="field" type="text" name="username" autocomplete="username"
               minlength="<?= (int) $usernameMin ?>" maxlength="<?= (int) $usernameMax ?>" required autofocus>
      </label>
      <label>Пароль
        <input class="field" type="password" name="password" autocomplete="new-password"
               minlength="<?= (int) $passwordMin ?>" required>
      </label>
      <label>Пароль ещё раз
        <input class="field" type="password" name="password_confirm" autocomplete="new-password"
               minlength="<?= (int) $passwordMin ?>" required>
      </label>

      <button class="button" type="submit">Зарегистрироваться</button>

      <input type="hidden" name="token" value="<?= $e($token) ?>">
      <!-- Ловушка для ботов: человек этого поля не видит -->
      <div class="hp" aria-hidden="true">
        <label>Не заполняйте это поле
          <input type="text" name="website" tabindex="-1" autocomplete="off">
        </label>
      </div>
    </form>

    <p class="auth-card__links">Уже есть аккаунт? <a href="<?= $e($base) ?>/login">Войти</a></p>
  </div>
</div>
</body>
</html>
