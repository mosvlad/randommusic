<?php
/**
 * @var string $base
 * @var array  $pending
 * @var array  $decided
 */

use App\Http\View;

$e = static fn($v): string => View::e($v);
$dt = static fn(int $ts): string => gmdate('d.m H:i', $ts);
$fmtSize = static fn(int $b): string => round($b / 1048576, 1) . ' МБ';
$fmtDur = static function (?float $s): string {
    $s = (int) round((float) $s);
    return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
};
$statusLabel = ['approved' => 'в библиотеке', 'rejected' => 'отклонён'];
?>
<!doctype html>
<html lang="ru" data-theme="night">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Модерация загрузок — Random music</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="<?= $e($base) ?>/assets/css/app.css">
<style>
  body { background-image: none; background: #1a1a1a; padding: 1.5rem 1rem 4rem; }
  .admin { width: min(1100px, 96vw); margin: 0 auto; display: grid; gap: 1.5rem; }
  table { width: 100%; border-collapse: collapse; font-size: .9rem; }
  th, td { padding: .4rem .5rem; text-align: left; border-bottom: 1px solid #2e2e2e; vertical-align: top; }
  th { color: #999; font-weight: 400; }
  tr.rejected td { opacity: .5; text-decoration: line-through; }
  .mono { font-family: var(--font-mono); font-size: .78rem; color: #888; }
  .inline { display: inline; }
  .mini { border: none; background: #2e2e2e; color: #ccc; border-radius: 3px; padding: .2rem .45rem;
          font-family: var(--font); font-size: .8rem; cursor: pointer; }
  .mini:hover { background: var(--accent); color: #fff; }
  h2 { color: #ddd; font-weight: 400; font-size: 1.4rem; margin-top: .5rem; }
  .toolbar { display: flex; gap: .5rem; flex-wrap: wrap; }
  .tagfield { width: 7rem; background: #2e2e2e; border: 1px solid #3a3a3a; color: #ddd; border-radius: 3px;
              padding: .2rem .35rem; font-family: var(--font); font-size: .8rem; }
  audio.mini-player { height: 28px; max-width: 220px; }
</style>
</head>
<body>
<div class="admin">

  <h1 class="site-title" style="font-size:2rem">Модерация загрузок</h1>

  <div class="toolbar">
    <a class="mini" href="<?= $e($base) ?>/admin">← основная модераторская</a>
    <a class="mini" href="<?= $e($base) ?>/admin/logout">Выйти</a>
  </div>

  <?php if (!$pending): ?>
    <p style="color:#999">Очередь пуста.</p>
  <?php else: ?>
    <h2>На модерации (<?= count($pending) ?>)</h2>
    <table>
      <tr><th>Кто</th><th>Файл</th><th>Теги</th><th>Прослушать</th><th></th><th>Действия</th></tr>
      <?php foreach ($pending as $p): ?>
        <tr>
          <td><?= $e($p['username']) ?><br><span class="mono"><?= $e($dt((int) $p['created_at'])) ?></span></td>
          <td>
            <?= $e($p['original_name']) ?><br>
            <span class="mono"><?= $fmtSize((int) $p['size']) ?> · <?= $fmtDur($p['duration'] !== null ? (float) $p['duration'] : null) ?></span>
          </td>
          <td class="mono"><?= $e(trim((($p['artist'] ?? '') . ' — ' . ($p['title'] ?? '')), ' —')) ?: '—' ?></td>
          <td><audio class="mini-player" controls preload="none"
                     src="<?= $e($base) ?>/admin/uploads/stream/<?= (int) $p['id'] ?>"></audio></td>
          <td></td>
          <td style="white-space:nowrap">
            <form method="post" action="<?= $e($base) ?>/admin/uploads" class="inline">
              <input type="hidden" name="do" value="approve">
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <input class="tagfield" type="text" name="artist" placeholder="исполнитель" value="<?= $e($p['artist'] ?? '') ?>">
              <input class="tagfield" type="text" name="title" placeholder="название" value="<?= $e($p['title'] ?? '') ?>">
              <button class="mini" type="submit">в библиотеку</button>
            </form>
            <form method="post" action="<?= $e($base) ?>/admin/uploads" class="inline">
              <input type="hidden" name="do" value="reject">
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button class="mini" type="submit">отклонить</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php if ($decided): ?>
    <h2>Недавно решённые</h2>
    <table>
      <tr><th>Кто</th><th>Файл</th><th>Решение</th><th>Когда</th></tr>
      <?php foreach ($decided as $d): ?>
        <tr class="<?= $d['status'] === 'rejected' ? 'rejected' : '' ?>">
          <td><?= $e($d['username']) ?></td>
          <td><?= $e($d['original_name']) ?></td>
          <td><?= $e($statusLabel[$d['status']] ?? $d['status']) ?></td>
          <td class="mono"><?= $d['decided_at'] ? $e($dt((int) $d['decided_at'])) : '' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

</div>
</body>
</html>
