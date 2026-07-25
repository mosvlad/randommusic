<?php
/**
 * @var string     $base
 * @var string     $origin
 * @var array|null $initial
 * @var bool       $isPermalink
 * @var array      $messages
 * @var int        $lastId
 * @var string     $token
 * @var array      $stats
 * @var int        $online
 * @var int        $maxLen
 * @var int        $maxName
 * @var string     $assetVer
 */

use App\Http\View;

$e = static fn($v): string => View::e($v);
$asset = static fn(string $p): string => $base . $p . '?v=' . $assetVer;

$trackTitle = $initial
    ? trim(($initial['artist'] ? $initial['artist'] . ' — ' : '') . $initial['title'])
    : null;

$pageTitle = $isPermalink && $trackTitle
    ? $trackTitle . ' · Random music'
    : 'Random music — случайная музыка онлайн';

$description = $isPermalink && $trackTitle
    ? $trackTitle . ' — слушать на Random music'
    : 'Случайная музыка онлайн и ламповый чат. Заходи и слушай: '
      . number_format($stats['tracks'], 0, '.', ' ') . ' треков, ' . $stats['hours'] . ' часов.';

$canonical = $origin . $base . ($isPermalink && $initial ? '/t/' . $initial['id'] : '/');
?>
<!doctype html>
<html lang="ru" data-theme="night">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($pageTitle) ?></title>

<meta name="description" content="<?= $e($description) ?>">
<meta name="keywords" content="музыка, онлайн, случайная, радио, чат, плейлист, random music, chat">
<link rel="canonical" href="<?= $e($canonical) ?>">

<meta property="og:type" content="website">
<meta property="og:site_name" content="Random music">
<meta property="og:title" content="<?= $e($pageTitle) ?>">
<meta property="og:description" content="<?= $e($description) ?>">
<meta property="og:url" content="<?= $e($canonical) ?>">
<meta property="og:image" content="<?= $e($origin . $base) ?>/assets/img/og.jpg">
<meta name="twitter:card" content="summary_large_image">

<link rel="icon" href="<?= $e($base) ?>/assets/img/icon.png" type="image/png">
<link rel="apple-touch-icon" href="<?= $e($base) ?>/assets/img/cover.jpg">
<link rel="manifest" href="<?= $e($base) ?>/manifest.webmanifest">
<meta name="theme-color" content="#191919">

<link rel="preload" href="<?= $e($base) ?>/assets/fonts/yanone-kaffeesatz-cyrillic.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= $e($asset('/assets/css/app.css')) ?>">

<meta name="yandex-verification" content="f4ff80d20a325806">
</head>
<body>

<div class="container">

  <header class="site-head">
    <h1 class="site-title">Random music</h1>
    <div class="site-intro">
      <p>Привет, друг. На этом сайте ты можешь слушать случайную музыку и общаться.</p>
      <p>Вопросы и предложения — в чатик в телеге, ссылка внизу.</p>
      <p class="accent">Чтобы оставаться на связи, заходите в ТГ:
        <a href="https://t.me/randommusic_reborn" target="_blank" rel="noopener">ссылка</a></p>
      <p>Всем добра!</p>
    </div>
  </header>

  <!-- ------------------------------------------------------------------ -->
  <section class="player" id="player" aria-label="Плеер">

    <div class="now-playing">
      <div class="now-playing__title" id="np-title"><?= $e($initial['title'] ?? 'Загружаю…') ?></div>
<?php /* Строка артиста остаётся в потоке даже пустой: иначе панель
             прыгала бы по высоте на треках без тега. */ ?>
      <div class="now-playing__artist" id="np-artist"><?= $e($initial['artist'] ?? '') ?></div>
      <div class="now-playing__meta" id="np-meta"><?php
        if ($initial) {
            echo $e(implode(' · ', array_filter([
                $initial['album'] ?: null,
                $initial['year'] ?: null,
                $initial['bitrate'] ? $initial['bitrate'] . ' kbps' : null,
            ])));
        }
      ?></div>
    </div>

    <div class="progress" id="progress" role="slider" tabindex="0"
         aria-label="Позиция в треке" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
      <div class="progress__track">
        <div class="progress__buffer" id="progress-buffer"></div>
        <div class="progress__fill" id="progress-fill"></div>
      </div>
      <div class="progress__thumb"></div>
    </div>

    <div class="times">
      <span id="time-current">0:00</span>
      <span id="time-total"><?php
        $d = (int) round((float) ($initial['duration'] ?? 0));
        printf('%d:%02d', intdiv($d, 60), $d % 60);
      ?></span>
    </div>

    <!-- Одна строка: воспроизведение слева, RANDOM по центру, громкость
         справа. Кнопки «следующий» нет намеренно — её работу и делает
         RANDOM, две кнопки с одним действием только путали бы. -->
    <div class="controls">
      <div class="controls__side controls__side--left">
        <button type="button" class="icon-btn" id="btn-prev" aria-label="Предыдущий трек" disabled>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6h2v12H6zm3.5 6 8.5 6V6z"/></svg>
        </button>

        <button type="button" class="icon-btn" id="btn-play" data-state="paused" aria-label="Слушать">
          <svg class="i-play" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
          <svg class="i-pause" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5h4v14H6zm8 0h4v14h-4z"/></svg>
        </button>
      </div>

      <button type="button" class="button button--random" id="btn-random">RANDOM</button>

      <div class="controls__side controls__side--right">
        <label class="volume">
          <span class="visually-hidden">Громкость</span>
          <svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor">
            <path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3a4.5 4.5 0 0 0-2.5-4v8a4.5 4.5 0 0 0 2.5-4z"/>
          </svg>
          <input type="range" id="volume" min="0" max="100" value="50">
        </label>
      </div>
    </div>

    <audio id="audio" preload="metadata"<?= $initial ? ' src="' . $e($initial['url']) . '"' : '' ?>></audio>
    <audio id="preloader" preload="auto" hidden></audio>

    <div class="player-extra">
      <span id="player-status" role="status" aria-live="polite"></span>
      <span>
        <?php if ($initial): ?>
          <a id="share-link" href="<?= $e($base) ?>/t/<?= (int) $initial['id'] ?>">ссылка на трек</a>
        <?php endif; ?>
      </span>
    </div>

    <button type="button" class="spoiler-toggle" id="history-toggle" aria-expanded="false" aria-controls="history">
      Показать историю
    </button>
    <div class="history" id="history" data-open="0">
      <ul class="history__list" id="history-list"></ul>
    </div>

    <noscript>
      <p style="margin-top:.75rem;color:#555">
        Без JavaScript доступен один случайный трек и последние сообщения чата.
      </p>
      <?php if ($initial): ?>
        <audio class="noscript-player" controls src="<?= $e($initial['url']) ?>"></audio>
      <?php endif; ?>
      <p style="margin-top:.5rem"><a href="<?= $e($base) ?>/">Обновить страницу — другой трек</a></p>
    </noscript>
  </section>

  <!-- ------------------------------------------------------------------ -->
  <h2 class="section-title">Lamp chat</h2>

  <section class="chat" id="chat" aria-label="Чат">
    <button type="button" class="chat__more" id="chat-more">Показать более ранние</button>

    <ul class="chat__log" id="chat-log" role="log" aria-live="polite" aria-relevant="additions">
      <?php foreach ($messages as $m): ?>
        <li class="msg" data-id="<?= (int) $m['id'] ?>">
          <div class="msg__head">
            <span class="msg__name"><?= $e($m['name']) ?></span>
            <time class="msg__time" datetime="<?= $e(gmdate('c', (int) $m['time'])) ?>"><?= $e(gmdate('H:i', (int) $m['time'])) ?></time>
          </div>
          <div class="msg__body"><?= $e($m['content']) ?></div>
        </li>
      <?php endforeach; ?>
    </ul>

    <div class="chat__meta">
      <span id="chat-status" role="status" aria-live="polite"></span>
      <span hidden><span class="online-dot"></span>онлайн: <span id="chat-online"><?= (int) $online ?></span></span>
    </div>

    <form class="chat__form" id="chat-form" method="post" action="<?= $e($base) ?>/api/v1/chat">
      <div class="chat__row">
        <label>
          <span class="visually-hidden">Сообщение</span>
          <input class="field" type="text" id="chat-content" name="content" autocomplete="off"
                 maxlength="<?= (int) $maxLen ?>" placeholder="Сообщение" required>
        </label>
        <label>
          <span class="visually-hidden">Имя</span>
          <input class="field" type="text" id="chat-name" name="name" autocomplete="nickname"
                 maxlength="<?= (int) $maxName ?>" value="Anonymous" placeholder="Имя">
        </label>
      </div>

      <button type="submit" class="button">Send</button>

      <input type="hidden" name="token" id="chat-token" value="<?= $e($token) ?>">
      <!-- Ловушка для ботов: человек этого поля не видит -->
      <div class="hp" aria-hidden="true">
        <label>Не заполняйте это поле
          <input type="text" name="website" id="chat-hp" tabindex="-1" autocomplete="off">
        </label>
      </div>
    </form>
  </section>

  <!-- ------------------------------------------------------------------ -->
  <footer class="site-foot">
    <div class="site-foot__links">
      <a href="https://t.me/randommusic_reborn" target="_blank" rel="noopener">Telegram Chat</a>
      <button type="button" class="theme-toggle" id="theme-toggle">День</button>
    </div>
  </footer>

</div>

<script type="application/json" id="boot"><?= View::json([
    'base'    => $base,
    'initial' => $initial,
    'lastId'  => $lastId,
    'token'   => $token,
    'online'  => $online,
]) ?></script>
<script type="module" src="<?= $e($asset('/assets/js/app.js')) ?>"></script>

<script type="application/ld+json"><?= View::json([
    '@context'    => 'https://schema.org',
    '@type'       => 'WebSite',
    'name'        => 'Random music',
    'url'         => $origin . $base . '/',
    'description' => $description,
]) ?></script>

</body>
</html>
