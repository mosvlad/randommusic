<?php
declare(strict_types=1);

namespace App\Track;

use App\Support\Cache;
use App\Support\Config;
use App\Support\Db;
use PDO;

/**
 * Обход медиатеки и наполнение индекса.
 *
 * v1 на каждый клик RANDOM делал три opendir() по ~4900 файлам и отдавал
 * случайный путь; теги клиент потом добирал повторной загрузкой mp3.
 * Здесь всё это делается один раз при сканировании.
 *
 * Инкрементальность по (path, size, mtime): повторный проход по неизменной
 * медиатеке занимает секунды.
 */
final class Scanner
{
    private const AUDIO_EXT = ['mp3', 'ogg', 'oga', 'opus', 'm4a', 'flac', 'wav', 'aac'];

    /** Сколько байт с начала и с конца файла берём в быстрый хеш. */
    private const HASH_CHUNK = 131072;

    private PDO $db;
    private string $docroot;
    private string $ffprobe;

    /** @var callable|null */
    private $progress;

    public function __construct(?callable $progress = null)
    {
        $this->db      = Db::get('tracks');
        $this->docroot = Config::docroot();
        $this->ffprobe = (string) Config::get('FFPROBE', '/usr/bin/ffprobe');
        $this->progress = $progress;
    }

    /**
     * @return array{scanned:int,added:int,updated:int,unchanged:int,removed:int,failed:int,duplicates:int}
     */
    public function run(bool $full = false): array
    {
        $started = time();
        $stats = ['scanned' => 0, 'added' => 0, 'updated' => 0, 'unchanged' => 0,
                  'removed' => 0, 'failed' => 0, 'duplicates' => 0];

        // Текущее содержимое индекса — чтобы понять, что изменилось
        $known = [];
        $rows = $this->db->query('SELECT id, path, size, mtime FROM tracks');
        foreach ($rows as $r) {
            $known[$r['path']] = $r;
        }

        $seen = [];
        $this->db->exec('BEGIN');
        try {
            foreach (Config::list('MEDIA_DIRS', ['1000', 'music', 'upload']) as $source) {
                $dir = $this->docroot . '/' . $source;
                if (!is_dir($dir)) {
                    $this->note("пропускаю отсутствующий каталог: $source");
                    continue;
                }

                foreach ($this->walk($dir) as $abs) {
                    $stats['scanned']++;
                    $rel = '/' . $source . '/' . ltrim(substr($abs, strlen($dir)), '/');
                    $seen[$rel] = true;

                    $size  = (int) @filesize($abs);
                    $mtime = (int) @filemtime($abs);
                    if ($size <= 0) {
                        $stats['failed']++;
                        continue;
                    }

                    $prev = $known[$rel] ?? null;
                    if (!$full && $prev && (int) $prev['size'] === $size && (int) $prev['mtime'] === $mtime) {
                        $stats['unchanged']++;
                        continue;
                    }

                    $meta = $this->probe($abs);
                    if ($meta === null) {
                        $stats['failed']++;
                        $this->note("ffprobe не смог прочитать: $rel");
                        continue;
                    }

                    $meta['path']    = $rel;
                    $meta['source']  = $source;
                    $meta['size']    = $size;
                    $meta['mtime']   = $mtime;
                    $meta['hash']    = $this->quickHash($abs, $size);
                    $meta['dup_key'] = $this->dupKey($meta);

                    if ($prev) {
                        $this->update((int) $prev['id'], $meta);
                        $stats['updated']++;
                    } else {
                        $this->insert($meta);
                        $stats['added']++;
                    }

                    if (($stats['added'] + $stats['updated']) % 250 === 0) {
                        $this->note(sprintf(
                            '  обработано %d (добавлено %d, обновлено %d)',
                            $stats['scanned'], $stats['added'], $stats['updated']
                        ));
                    }
                }
            }

            // Пропавшие с диска файлы помечаем present=0, но не удаляем:
            // статистика прослушиваний и ссылки на них должны пережить
            // временный отвал архивного раздела. Вернётся файл — вернётся и трек.
            if ($seen !== []) {
                $off = $this->db->prepare('UPDATE tracks SET present = 0 WHERE present = 1 AND path = ?');
                $on  = $this->db->prepare('UPDATE tracks SET present = 1 WHERE present = 0 AND path = ?');
                foreach ($known as $path => $_row) {
                    if (isset($seen[$path])) {
                        $on->execute([$path]);
                    } else {
                        $off->execute([$path]);
                        $stats['removed'] += $off->rowCount();
                    }
                }
            }

            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }

        $stats['duplicates'] = $this->resolveDuplicatesAndActivate();

        $this->setMeta('last_scan', (string) $started);
        $this->setMeta('last_scan_duration', (string) (time() - $started));
        $this->setMeta('last_scan_stats', json_encode($stats, JSON_UNESCAPED_UNICODE));

        Cache::flush();

        return $stats;
    }

    /**
     * Дедупликация по байтовому хешу: одинаковые файлы в разных каталогах
     * (1000/, music/, upload/ наполнялись независимо) выпадают из ротации.
     */
    private function resolveDuplicatesAndActivate(): int
    {
        // Из каждой группы байтово одинаковых файлов в ротации остаётся
        // самый ранний id, остальные помечаются дублями. Считается заново
        // каждый проход, так что удаление файла с диска возвращает его копию.
        $this->db->exec('UPDATE tracks SET duplicate = 0');
        $this->db->exec(
            'UPDATE tracks SET duplicate = 1
             WHERE present = 1
               AND id NOT IN (SELECT MIN(id) FROM tracks WHERE present = 1 GROUP BY hash)'
        );

        // active — производное поле, чтобы выборка шла по одному индексу
        $this->db->exec('UPDATE tracks SET active = CASE WHEN present = 1 AND duplicate = 0 THEN 1 ELSE 0 END');

        return (int) $this->db->query('SELECT COUNT(*) FROM tracks WHERE duplicate = 1')->fetchColumn();
    }

    /**
     * Совпадения по artist+title+длительности НЕ трогаем автоматически:
     * при неряшливых тегах так легко зарубить лайв-версии и ремиксы.
     * Только считаем — разбор через админку.
     */
    public function softDuplicateCount(): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) FROM tracks WHERE active = 1 AND dup_key IS NOT NULL AND dup_key IN (
                SELECT dup_key FROM tracks WHERE active = 1 AND dup_key IS NOT NULL
                GROUP BY dup_key HAVING COUNT(*) > 1
             )'
        )->fetchColumn();
    }

    /** @return \Generator<string> */
    private function walk(string $dir): \Generator
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, self::AUDIO_EXT, true)) {
                continue;
            }
            yield $file->getPathname();
        }
    }

    /**
     * Быстрый хеш: размер + первые и последние 128 КБ.
     *
     * Полный sha1 аудиопотока (как в плане) означал бы вычитывание 27 ГБ
     * с медленного архивного раздела на каждом полном проходе. Края файла
     * плюс точный размер дают достаточную уверенность для поиска дублей
     * и стоят пары миллисекунд.
     */
    private function quickHash(string $path, int $size): string
    {
        $ctx = hash_init('sha1');
        hash_update($ctx, (string) $size);

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return hash_final($ctx);
        }

        $head = fread($fh, self::HASH_CHUNK);
        if ($head !== false) {
            hash_update($ctx, $head);
        }

        if ($size > self::HASH_CHUNK * 2) {
            fseek($fh, -self::HASH_CHUNK, SEEK_END);
            $tail = fread($fh, self::HASH_CHUNK);
            if ($tail !== false) {
                hash_update($ctx, $tail);
            }
        }

        fclose($fh);
        return hash_final($ctx);
    }

    /**
     * Метаданные через ffprobe. Если тегов нет — разбираем имя файла,
     * в этой медиатеке оно почти всегда «Артист - Название.mp3».
     *
     * @return array<string,mixed>|null
     */
    private function probe(string $path): ?array
    {
        $cmd = sprintf(
            '%s -v quiet -print_format json -show_format -show_streams -i %s 2>/dev/null',
            escapeshellcmd($this->ffprobe),
            escapeshellarg($path)
        );
        $json = @shell_exec($cmd);
        if (!is_string($json) || trim($json) === '') {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['format'])) {
            return null;
        }

        $format = $data['format'];
        $tags = [];
        foreach (($format['tags'] ?? []) as $k => $v) {
            $tags[strtolower((string) $k)] = is_scalar($v) ? trim((string) $v) : '';
        }
        // У некоторых файлов теги висят на потоке, а не на контейнере
        foreach (($data['streams'][0]['tags'] ?? []) as $k => $v) {
            $k = strtolower((string) $k);
            if (!isset($tags[$k]) || $tags[$k] === '') {
                $tags[$k] = is_scalar($v) ? trim((string) $v) : '';
            }
        }

        // Теги в этой медиатеке разного качества: в 1000/ они честные,
        // в music/ попадается мусор вроде artist="Track", title="01",
        // а в upload/ (загрузки ботом с YouTube) их нет вовсе.
        // Мусорный тег хуже разобранного имени файла, поэтому проверяем оба.
        $artist = $this->clean($tags['artist'] ?? $tags['album_artist'] ?? $tags['performer'] ?? '');
        $title  = $this->clean($tags['title'] ?? '');

        if ($this->isJunk($artist)) {
            $artist = '';
        }
        if ($this->isJunk($title)) {
            $title = '';
        }

        if ($artist === '' || $title === '') {
            [$fnArtist, $fnTitle] = $this->parseFilename($path);
            if ($artist === '' && !$this->isJunk($fnArtist)) {
                $artist = $fnArtist;
            }
            if ($title === '' && !$this->isJunk($fnTitle)) {
                $title = $fnTitle;
            }
        }

        $year = 0;
        $date = (string) ($tags['date'] ?? $tags['year'] ?? $tags['originalyear'] ?? '');
        if (preg_match('/(19|20)\d{2}/', $date, $m)) {
            $year = (int) $m[0];
        }

        $duration = isset($format['duration']) ? (float) $format['duration'] : null;
        $bitrate  = isset($format['bit_rate']) ? (int) round(((int) $format['bit_rate']) / 1000) : null;

        return [
            'artist'   => $artist !== '' ? $artist : null,
            'title'    => $title  !== '' ? $title  : null,
            'album'    => $this->clean($tags['album'] ?? '') ?: null,
            'genre'    => $this->clean($tags['genre'] ?? '') ?: null,
            'year'     => $year ?: null,
            'duration' => ($duration !== null && $duration > 0) ? round($duration, 2) : null,
            'bitrate'  => ($bitrate !== null && $bitrate > 0) ? $bitrate : null,
        ];
    }

    /** @return array{0:string,1:string} */
    private function parseFilename(string $path): array
    {
        $name = pathinfo($path, PATHINFO_FILENAME);
        $name = str_replace('_', ' ', $name);
        $name = preg_replace('/^\s*\d{1,3}\s*[.\-]\s*/u', '', $name) ?? $name;

        foreach ([' - ', ' — ', ' – '] as $sep) {
            $pos = mb_strpos($name, $sep);
            if ($pos !== false) {
                return [
                    $this->clean(mb_substr($name, 0, $pos)),
                    $this->clean(mb_substr($name, $pos + mb_strlen($sep))),
                ];
            }
        }

        return ['', $this->clean($name)];
    }

    /**
     * Значение бесполезно для показа: заглушка тега, порядковый номер
     * или машинный идентификатор (YouTube video id, Telegram file_id).
     */
    private function isJunk(string $s): bool
    {
        $s = trim($s);
        if ($s === '') {
            return true;
        }

        $low = mb_strtolower($s);

        static $placeholders = [
            'track', 'tracks', 'unknown', 'unknown artist', 'unknown album', 'untitled',
            'artist', 'title', 'various', 'various artists', 'va', 'none', 'no artist',
            'audiotrack', 'audio track', 'sound', 'music', 'default',
            'неизвестен', 'неизвестный исполнитель', 'без названия',
        ];
        if (in_array($low, $placeholders, true)) {
            return true;
        }

        // «01», «Track 5», «трек 12»
        if (preg_match('/^(track|трек)?\s*\d{1,4}$/u', $low)) {
            return true;
        }

        // Машинные имена: без пробелов, из букв/цифр/-/_ , с цифрами и буквами вперемешку.
        // Так выглядят и YouTube id (11 симв.), и Telegram file_id (длинные).
        if (
            !str_contains($s, ' ')
            && mb_strlen($s) >= 10
            && preg_match('/^[A-Za-z0-9_-]+$/', $s)
            && preg_match('/\d/', $s)
            && preg_match('/[A-Za-z]/', $s)
            && !preg_match('/^[A-Za-z]+\d{1,4}$/', $s)   // «Blink182» — настоящее имя
        ) {
            return true;
        }

        return false;
    }

    private function clean(string $s): string
    {
        $s = str_replace(["\0", "\r", "\n", "\t"], ' ', $s);

        if ($s !== '' && !mb_check_encoding($s, 'UTF-8')) {
            $conv = @mb_convert_encoding($s, 'UTF-8', 'Windows-1251');
            $s = (is_string($conv) && mb_check_encoding($conv, 'UTF-8'))
                ? $conv
                : mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }

        $s = self::repairCyrillic($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return mb_substr(trim($s), 0, 200);
    }

    /**
     * Возврат кириллицы, испорченной при чтении ID3v1.
     *
     * В ID3v1 кодировка не указана, поэтому ffprobe разбирает такие теги
     * как latin-1. Байты cp1251 превращаются в осмысленный UTF-8 из
     * латиницы с диакритикой — «Çâåíèò ÿíâàðñêàÿ âüþãà» вместо «Звенит
     * январская вьюга». Проверка mb_check_encoding такое не ловит:
     * строка формально корректна.
     *
     * Чиним обратным ходом: разбираем строку на исходные байты и читаем
     * их как cp1251. Результат принимаем, только если получилась
     * преимущественно кириллица, — иначе пострадали бы настоящие
     * латинские названия вроде Björk или Sigur Rós.
     */
    public static function repairCyrillic(string $s): string
    {
        if ($s === '' || !mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }

        // Признак подмены: несколько символов из Latin-1 Supplement
        $suspicious = preg_match_all('/[\x{00C0}-\x{00FF}]/u', $s);
        if ($suspicious < 2) {
            return $s;
        }

        // Обратно в байты. Возможно только если все символы ≤ U+00FF
        if (preg_match('/[^\x{0000}-\x{00FF}]/u', $s)) {
            return $s;
        }

        $bytes = @mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
        if (!is_string($bytes) || $bytes === '') {
            return $s;
        }

        $candidate = @mb_convert_encoding($bytes, 'UTF-8', 'Windows-1251');
        if (!is_string($candidate) || !mb_check_encoding($candidate, 'UTF-8')) {
            return $s;
        }

        $cyrillic = preg_match_all('/[\x{0400}-\x{04FF}]/u', $candidate);
        $letters  = preg_match_all('/[\p{L}]/u', $candidate);

        // Больше половины букв стали кириллицей — значит, угадали
        if ($letters > 0 && $cyrillic / $letters >= 0.5) {
            return $candidate;
        }

        return $s;
    }

    /** @param array<string,mixed> $m */
    private function dupKey(array $m): ?string
    {
        if (empty($m['artist']) || empty($m['title'])) {
            return null;
        }
        $norm = static fn(string $s): string =>
            preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s)) ?? '';

        return $norm((string) $m['artist']) . '|' . $norm((string) $m['title'])
             . '|' . (int) round((float) ($m['duration'] ?? 0));
    }

    /** @param array<string,mixed> $m */
    private function insert(array $m): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO tracks
             (path, hash, dup_key, size, mtime, duration, bitrate, artist, title, album, year, genre,
              source, added_at, seen_at, active, weight)
             VALUES (:path, :hash, :dup_key, :size, :mtime, :duration, :bitrate, :artist, :title,
                     :album, :year, :genre, :source, :now, :now, 1, 1.0)'
        );
        $stmt->execute($this->bind($m) + ['now' => time()]);
    }

    /** @param array<string,mixed> $m */
    private function update(int $id, array $m): void
    {
        // loudness сбрасываем, только если файл на диске действительно
        // изменился. Справа от «=» SQLite видит ещё старые значения строки,
        // так что сравнение работает. Иначе `bin/scan --full` стирал бы
        // тысячи замеров, каждый из которых стоит секунды работы ffmpeg.
        //
        // Имена :size_was/:mtime_was отдельные не для красоты: PDO не даёт
        // переиспользовать один именованный параметр дважды в запросе.
        $stmt = $this->db->prepare(
            'UPDATE tracks SET hash = :hash, dup_key = :dup_key,
                    loudness = CASE WHEN size <> :size_was OR mtime <> :mtime_was
                                    THEN NULL ELSE loudness END,
                    size = :size, mtime = :mtime,
                    duration = :duration, bitrate = :bitrate, artist = :artist, title = :title,
                    album = :album, year = :year, genre = :genre, source = :source,
                    seen_at = :now, present = 1
             WHERE id = :id'
        );
        // path в этом запросе не участвует — строка ищется по id.
        // Лишний параметр PDO не прощает: SQLITE_RANGE.
        $params = $this->bind($m);
        unset($params['path']);

        $stmt->execute($params + [
            'now'       => time(),
            'id'        => $id,
            'size_was'  => $m['size'],
            'mtime_was' => $m['mtime'],
        ]);
    }

    /**
     * @param array<string,mixed> $m
     * @return array<string,mixed>
     */
    private function bind(array $m): array
    {
        return [
            'path'     => $m['path'],
            'hash'     => $m['hash'],
            'dup_key'  => $m['dup_key'],
            'size'     => $m['size'],
            'mtime'    => $m['mtime'],
            'duration' => $m['duration'],
            'bitrate'  => $m['bitrate'],
            'artist'   => $m['artist'],
            'title'    => $m['title'],
            'album'    => $m['album'],
            'year'     => $m['year'],
            'genre'    => $m['genre'],
            'source'   => $m['source'],
        ];
    }

    private function setMeta(string $key, string $value): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO meta (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute([$key, $value]);
    }

    private function note(string $msg): void
    {
        if ($this->progress !== null) {
            ($this->progress)($msg);
        }
    }
}
