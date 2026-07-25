<?php
declare(strict_types=1);

namespace App\Track;

use App\Support\Cache;
use App\Support\Db;
use PDO;

/**
 * Чтение индекса треков. Всё, что отдаётся клиенту, проходит через toDto():
 * наружу уезжают только те поля, которые нужны плееру.
 */
final class Index
{
    private const COLUMNS = 'id, path, artist, title, album, year, genre, duration, bitrate, loudness, source, added_at';

    private PDO $db;

    public function __construct()
    {
        $this->db = Db::get('tracks');
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM tracks WHERE id = ? AND present = 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->toDto($row);
    }

    /**
     * Взвешенная случайная выборка.
     *
     * Список активных id с весами держим в кэше: 3900 записей — это ~60 КБ,
     * зато выбор трека становится обращением в память вместо запроса к диску.
     *
     * @param int[] $exclude недавно игравшие — чтобы не повторяться в пределах сессии
     */
    public function random(array $exclude = []): ?array
    {
        $index = $this->weightedIndex();
        if ($index['total'] <= 0.0) {
            return null;
        }

        $skip = array_flip(array_map('intval', $exclude));

        // Несколько попыток попасть мимо исключённых; если не вышло —
        // берём что выпало (список исключений мог накрыть всю библиотеку)
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $id = $this->pick($index);
            if ($id === null) {
                return null;
            }
            if (!isset($skip[$id]) || $attempt === 11) {
                return $this->find($id);
            }
        }

        return null;
    }

    /** @return array{ids:int[],cum:float[],total:float} */
    private function weightedIndex(): array
    {
        $cached = Cache::get('tracks.index');
        if (is_array($cached) && isset($cached['ids'], $cached['cum'], $cached['total'])) {
            return $cached;
        }

        $ids = [];
        $cum = [];
        $total = 0.0;

        $rows = $this->db->query('SELECT id, weight FROM tracks WHERE active = 1 ORDER BY id');
        foreach ($rows as $r) {
            $w = max(0.0, (float) $r['weight']);
            if ($w <= 0.0) {
                continue;
            }
            $total += $w;
            $ids[] = (int) $r['id'];
            $cum[] = $total;
        }

        $index = ['ids' => $ids, 'cum' => $cum, 'total' => $total];
        Cache::set('tracks.index', $index, 300);

        return $index;
    }

    /** @param array{ids:int[],cum:float[],total:float} $index */
    private function pick(array $index): ?int
    {
        $n = count($index['ids']);
        if ($n === 0) {
            return null;
        }

        $target = (mt_rand() / mt_getrandmax()) * $index['total'];

        $lo = 0;
        $hi = $n - 1;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($index['cum'][$mid] < $target) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $index['ids'][$lo];
    }

    /** @return array<int,array<string,mixed>> */
    public function search(string $query, int $limit = 30): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        // FTS5: пользовательский ввод превращаем в префиксный запрос,
        // спецсимволы синтаксиса вырезаем
        $terms = preg_split('/\s+/u', preg_replace('/["\'*^:()-]+/u', ' ', $query) ?? '') ?: [];
        $terms = array_filter($terms, static fn($t) => mb_strlen($t) >= 2);
        if ($terms === []) {
            return [];
        }
        $expr = implode(' ', array_map(static fn($t) => '"' . $t . '"*', $terms));

        $stmt = $this->db->prepare(
            'SELECT t.' . str_replace(', ', ', t.', self::COLUMNS) . '
             FROM tracks_fts f JOIN tracks t ON t.id = f.rowid
             WHERE tracks_fts MATCH ? AND t.active = 1
             ORDER BY bm25(tracks_fts) LIMIT ?'
        );

        try {
            $stmt->execute([$expr, max(1, min(100, $limit))]);
        } catch (\PDOException) {
            return [];
        }

        return array_map([$this, 'toDto'], $stmt->fetchAll());
    }

    /** @return array<string,int|string|null> */
    public function stats(): array
    {
        $cached = Cache::get('stats.summary');
        if (is_array($cached)) {
            return $cached;
        }

        $row = $this->db->query(
            'SELECT COUNT(*) FILTER (WHERE active = 1)    AS active,
                    COUNT(*) FILTER (WHERE duplicate = 1) AS duplicates,
                    COUNT(*) FILTER (WHERE present = 0)   AS missing,
                    COUNT(*)                              AS total,
                    COALESCE(SUM(duration) FILTER (WHERE active = 1), 0) AS seconds
             FROM tracks'
        )->fetch() ?: [];

        $meta = [];
        foreach ($this->db->query('SELECT key, value FROM meta') as $m) {
            $meta[$m['key']] = $m['value'];
        }

        $stats = [
            'tracks'     => (int) ($row['active'] ?? 0),
            'duplicates' => (int) ($row['duplicates'] ?? 0),
            'missing'    => (int) ($row['missing'] ?? 0),
            'total'      => (int) ($row['total'] ?? 0),
            'hours'      => (int) round(((float) ($row['seconds'] ?? 0)) / 3600),
            'last_scan'  => isset($meta['last_scan']) ? (int) $meta['last_scan'] : null,
        ];

        Cache::set('stats.summary', $stats, 120);

        return $stats;
    }

    /** @param array<string,mixed> $row */
    private function toDto(array $row): array
    {
        $artist = $row['artist'] !== null && $row['artist'] !== '' ? (string) $row['artist'] : null;
        $title  = $row['title']  !== null && $row['title']  !== '' ? (string) $row['title']  : null;

        return [
            'id'       => (int) $row['id'],
            'url'      => $this->publicUrl((string) $row['path']),
            'artist'   => $artist,
            // Сканер оставляет title пустым, только если и тег, и имя файла
            // ничего не говорят (загрузки ботом названы id видео)
            'title'    => $title ?? 'Без названия',
            'album'    => $row['album'] ?: null,
            'year'     => $row['year'] ? (int) $row['year'] : null,
            'genre'    => $row['genre'] ?: null,
            'duration' => $row['duration'] !== null ? (float) $row['duration'] : null,
            'bitrate'  => $row['bitrate'] !== null ? (int) $row['bitrate'] : null,
            'loudness' => $row['loudness'] !== null ? (float) $row['loudness'] : null,
        ];
    }

    /**
     * Путь к файлу отдаём как есть, но с корректным кодированием сегментов:
     * в именах полно пробелов, апострофов и кириллицы.
     */
    private function publicUrl(string $path): string
    {
        $parts = array_map('rawurlencode', explode('/', ltrim($path, '/')));
        return '/' . implode('/', $parts);
    }
}
