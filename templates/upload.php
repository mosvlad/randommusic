<?php
/**
 * @var string $base
 * @var array  $user
 * @var string $token
 * @var string $error
 * @var bool   $ok
 * @var int    $pending
 * @var int    $maxPending
 * @var int    $maxBytes
 * @var array  $mine
 */

use App\Http\View;

$e = static fn($v): string => View::e($v);
$dt = static fn(int $ts): string => gmdate('d.m.Y H:i', $ts);

$messages = [
    'too_large'        => 'Файл больше ' . round($maxBytes / 1048576) . ' МБ',
    'bad_format'       => 'Не похоже на аудио (или формат не из списка ниже)',
    'too_long'         => 'Слишком длинный трек',
    'upload_failed'    => 'Загрузка не удалась, попробуйте ещё раз',
    'no_file'          => 'Выберите файл',
    'too_many_pending' => 'У вас уже есть трек на модерации — дождитесь решения',
    'bad_token'        => 'Форма устарела, обновите страницу',
    'too_fast'         => 'Слишком быстро — попробуйте ещё раз',
    'rate_limited'     => 'Загрузок многовато на сегодня, попробуйте завтра',
    'bot'              => 'Не похоже на человека',
];
$errorText = $messages[$error] ?? null;

$statusLabel = ['pending' => 'на модерации', 'approved' => 'в библиотеке', 'rejected' => 'отклонён'];
$maxMb = (int) round($maxBytes / 1048576);
$canUpload = $pending < $maxPending;
?>
<!doctype html>
<html lang="ru" data-theme="night">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Загрузить трек — Random music</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="<?= $e($base) ?>/assets/css/app.css">
</head>
<body>
<div class="container">
  <p class="auth-card__back"><a class="topbar__brand" href="<?= $e($base) ?>/">← Random music</a></p>
  <div class="auth-card">
    <h1>Загрузить трек</h1>
    <p class="auth-card__hint" style="text-align:center">
      Вы вошли как <strong><?= $e($user['username']) ?></strong>. Файл попадёт на
      модерацию; если подойдёт — окажется в общей библиотеке с подписью
      «Загрузил: <?= $e($user['username']) ?>».
    </p>

    <?php if ($ok): ?>
      <div class="auth-card__status" data-kind="ok">Загружено, ждите решения модератора</div>
    <?php endif; ?>
    <?php if ($errorText): ?>
      <div class="auth-card__status" data-kind="error"><?= $e($errorText) ?></div>
    <?php endif; ?>

    <?php if ($canUpload): ?>
      <form method="post" action="<?= $e($base) ?>/upload" enctype="multipart/form-data">
        <label>Файл (mp3, ogg, opus, m4a, flac, wav — до <?= $maxMb ?> МБ)
          <input class="field" type="file" name="track" accept="audio/*" required>
        </label>
        <label>Исполнитель <span class="auth-card__hint">(необязательно, если в файле уже есть теги)</span>
          <input class="field" type="text" name="artist" maxlength="190">
        </label>
        <label>Название
          <input class="field" type="text" name="title" maxlength="190">
        </label>

        <button class="button" type="submit">Отправить на модерацию</button>

        <input type="hidden" name="token" value="<?= $e($token) ?>">
        <!-- Ловушка для ботов: человек этого поля не видит -->
        <div class="hp" aria-hidden="true">
          <label>Не заполняйте это поле
            <input type="text" name="website" tabindex="-1" autocomplete="off">
          </label>
        </div>
      </form>
    <?php else: ?>
      <p class="auth-card__status">У вас трек уже на модерации — новый можно будет отправить после решения.</p>
    <?php endif; ?>

    <?php if ($mine): ?>
      <h2 style="margin:.5rem 0 0;font-size:1rem;font-weight:400;color:var(--panel-dim)">Мои загрузки</h2>
      <ul class="upload-list">
        <?php foreach ($mine as $m): ?>
          <li>
            <span class="upload-list__name">
              <?= $e(trim(($m['artist'] ? $m['artist'] . ' — ' : '') . ($m['title'] ?: $m['original_name']))) ?>
              <span class="auth-card__hint">· <?= $e($dt((int) $m['created_at'])) ?></span>
            </span>
            <span class="status-pill status-pill--<?= $e($m['status']) ?>"><?= $e($statusLabel[$m['status']] ?? $m['status']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
