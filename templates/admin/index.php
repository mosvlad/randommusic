<?php
/**
 * @var string $base
 * @var array  $messages
 * @var array  $bans
 * @var array  $top
 * @var array  $library
 * @var array  $chat
 * @var array  $playback
 */

use App\Http\View;

$e = static fn($v): string => View::e($v);
$dt = static fn(int $ts): string => gmdate('d.m H:i', $ts);
?>
<!doctype html>
<html lang="ru" data-theme="night">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Модераторская — Random music</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="<?= $e($base) ?>/assets/css/app.css">
<style>
  body { background-image: none; background: #1a1a1a; padding: 1.5rem 1rem 4rem; }
  .admin { width: min(1100px, 96vw); margin: 0 auto; display: grid; gap: 1.5rem; }
  .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: .75rem; }
  .card { background: #232323; border: 1px solid #333; border-radius: 6px; padding: .75rem 1rem; }
  .card b { display: block; font-size: 1.7rem; color: var(--accent); line-height: 1.1; }
  .card span { font-size: .85rem; color: #999; }
  table { width: 100%; border-collapse: collapse; font-size: .9rem; }
  th, td { padding: .4rem .5rem; text-align: left; border-bottom: 1px solid #2e2e2e; vertical-align: top; }
  th { color: #999; font-weight: 400; }
  tr.gone td { opacity: .4; text-decoration: line-through; }
  tr.shadow td { color: #b8860b; }
  .mono { font-family: var(--font-mono); font-size: .78rem; color: #888; }
  .inline { display: inline; }
  .mini { border: none; background: #2e2e2e; color: #ccc; border-radius: 3px; padding: .2rem .45rem;
          font-family: var(--font); font-size: .8rem; cursor: pointer; }
  .mini:hover { background: var(--accent); color: #fff; }
  h2 { color: #ddd; font-weight: 400; font-size: 1.4rem; margin-top: .5rem; }
  .toolbar { display: flex; gap: .5rem; flex-wrap: wrap; }
</style>
</head>
<body>
<div class="admin">

  <h1 class="site-title" style="font-size:2rem">Модераторская</h1>

  <div class="cards">
    <div class="card"><b><?= (int) $library['tracks'] ?></b><span>треков в ротации</span></div>
    <div class="card"><b><?= (int) $library['hours'] ?></b><span>часов музыки</span></div>
    <div class="card"><b><?= (int) $library['duplicates'] ?></b><span>дублей исключено</span></div>
    <div class="card"><b><?= (int) $library['missing'] ?></b><span>пропало с диска</span></div>
    <div class="card"><b><?= (int) $chat['messages'] ?></b><span>сообщений в чате</span></div>
    <div class="card"><b><?= (int) $chat['online'] ?></b><span>онлайн сейчас</span></div>
    <div class="card"><b><?= (int) $playback['plays'] ?></b><span>прослушиваний за 30 дн.</span></div>
    <div class="card"><b><?= (int) $playback['skips'] ?></b><span>из них скипов</span></div>
  </div>

  <div class="toolbar">
    <form method="post" action="<?= $e($base) ?>/admin" class="inline">
      <input type="hidden" name="do" value="rescan">
      <button class="mini" type="submit">Пересканировать медиатеку</button>
    </form>
    <form method="post" action="<?= $e($base) ?>/admin" class="inline">
      <input type="hidden" name="do" value="weights">
      <button class="mini" type="submit">Пересчитать веса ротации</button>
    </form>
    <a class="mini" href="<?= $e($base) ?>/api/v1/health" target="_blank">health</a>
    <a class="mini" href="<?= $e($base) ?>/admin/logout">Выйти</a>
  </div>

  <?php if ($top): ?>
    <h2>Активнее всех за час</h2>
    <table>
      <tr><th>Имя</th><th>Сообщений</th><th>Последнее</th><th>client</th></tr>
      <?php foreach ($top as $t): ?>
        <tr>
          <td><?= $e($t['name']) ?></td>
          <td><?= (int) $t['n'] ?></td>
          <td><?= $e($dt((int) $t['last_ts'])) ?></td>
          <td class="mono"><?= $e(substr((string) $t['client'], 0, 12)) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php if ($bans): ?>
    <h2>Баны</h2>
    <table>
      <tr><th>client</th><th>До</th><th>Тип</th><th>Причина</th><th></th></tr>
      <?php foreach ($bans as $b): ?>
        <tr>
          <td class="mono"><?= $e(substr((string) $b['client'], 0, 16)) ?></td>
          <td><?= (int) $b['until'] === 0 ? 'навсегда' : $e($dt((int) $b['until'])) ?></td>
          <td><?= (int) $b['shadow'] === 1 ? 'теневой' : 'явный' ?></td>
          <td><?= $e($b['reason']) ?></td>
          <td>
            <form method="post" action="<?= $e($base) ?>/admin" class="inline">
              <input type="hidden" name="do" value="unban">
              <input type="hidden" name="client" value="<?= $e($b['client']) ?>">
              <button class="mini" type="submit">снять</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <h2>Последние сообщения</h2>
  <table>
    <tr><th>id</th><th>Время</th><th>Имя</th><th>Текст</th><th>Действия</th></tr>
    <?php foreach ($messages as $m): ?>
      <tr class="<?= (int) $m['deleted'] === 1 ? 'gone' : ((int) $m['shadow'] === 1 ? 'shadow' : '') ?>">
        <td class="mono"><?= (int) $m['id'] ?></td>
        <td class="mono"><?= $e($dt((int) $m['ts'])) ?></td>
        <td><?= $e($m['name']) ?></td>
        <td><?= $e($m['content']) ?></td>
        <td style="white-space:nowrap">
          <?php if ((int) $m['deleted'] === 1): ?>
            <form method="post" action="<?= $e($base) ?>/admin" class="inline">
              <input type="hidden" name="do" value="restore">
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <button class="mini" type="submit">вернуть</button>
            </form>
          <?php else: ?>
            <form method="post" action="<?= $e($base) ?>/admin" class="inline">
              <input type="hidden" name="do" value="delete">
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <button class="mini" type="submit">удалить</button>
            </form>
            <form method="post" action="<?= $e($base) ?>/admin" class="inline">
              <input type="hidden" name="do" value="shadow">
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <input type="hidden" name="seconds" value="86400">
              <input type="hidden" name="purge" value="1">
              <button class="mini" type="submit" title="Автор продолжит видеть свои сообщения, остальные — нет">тень 24ч</button>
            </form>
            <form method="post" action="<?= $e($base) ?>/admin" class="inline">
              <input type="hidden" name="do" value="ban">
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <input type="hidden" name="seconds" value="86400">
              <input type="hidden" name="purge" value="1">
              <button class="mini" type="submit">бан 24ч</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

</div>
</body>
</html>
