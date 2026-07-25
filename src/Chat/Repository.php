<?php
declare(strict_types=1);

namespace App\Chat;

use App\Support\Cache;
use App\Support\Db;
use PDO;

/**
 * Хранилище чата.
 *
 * В v1 это был messages.json на 50 последних сообщений: всё, что уезжало
 * за окно, пропадало навсегда (~5800 сообщений за время жизни сайта).
 * Здесь история хранится целиком.
 */
final class Repository
{
    public const PAGE = 50;

    private PDO $db;

    public function __construct()
    {
        $this->db = Db::get('chat');
    }

    /**
     * Сообщения новее $sinceId. Теневые видит только их автор.
     *
     * @return array<int,array<string,mixed>>
     */
    public function since(int $sinceId, string $client, int $limit = self::PAGE): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, ts, name, content, track_id, source, shadow, client
             FROM messages
             WHERE id > :since AND deleted = 0 AND (shadow = 0 OR client = :client)
             ORDER BY id ASC LIMIT :limit'
        );
        $stmt->bindValue(':since', $sinceId, PDO::PARAM_INT);
        $stmt->bindValue(':client', $client, PDO::PARAM_STR);
        $stmt->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'toDto'], $stmt->fetchAll());
    }

    /**
     * Последние сообщения — стартовая порция при загрузке страницы.
     *
     * @return array<int,array<string,mixed>>
     */
    public function latest(string $client, int $limit = self::PAGE): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, ts, name, content, track_id, source, shadow, client
             FROM messages
             WHERE deleted = 0 AND (shadow = 0 OR client = :client)
             ORDER BY id DESC LIMIT :limit'
        );
        $stmt->bindValue(':client', $client, PDO::PARAM_STR);
        $stmt->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'toDto'], array_reverse($stmt->fetchAll()));
    }

    /**
     * Подгрузка истории вверх.
     *
     * @return array<int,array<string,mixed>>
     */
    public function before(int $beforeId, string $client, int $limit = self::PAGE): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, ts, name, content, track_id, source, shadow, client
             FROM messages
             WHERE id < :before AND deleted = 0 AND (shadow = 0 OR client = :client)
             ORDER BY id DESC LIMIT :limit'
        );
        $stmt->bindValue(':before', $beforeId, PDO::PARAM_INT);
        $stmt->bindValue(':client', $client, PDO::PARAM_STR);
        $stmt->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'toDto'], array_reverse($stmt->fetchAll()));
    }

    public function add(
        string $name,
        string $content,
        string $client,
        ?int $trackId = null,
        bool $shadow = false,
        string $source = 'web'
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO messages (ts, name, content, client, track_id, shadow, source)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([time(), $name, $content, $client, $trackId, $shadow ? 1 : 0, $source]);
        $this->bumpVersion();

        return (int) $this->db->lastInsertId();
    }

    public function lastId(): int
    {
        return (int) ($this->db->query('SELECT COALESCE(MAX(id), 0) FROM messages')->fetchColumn() ?: 0);
    }

    /**
     * Версия ленты для ETag.
     *
     * Считать здесь COUNT(*) по сообщениям нельзя: этот запрос выполняется
     * на каждый опрос чата, то есть чаще всего остального вместе взятого.
     * Держим счётчик в meta и увеличиваем его при каждом изменении ленты.
     */
    public function version(): string
    {
        $v = $this->db->query("SELECT value FROM meta WHERE key = 'feed_version'")->fetchColumn();

        if ($v === false) {
            // Первый запуск или база из миграции без счётчика
            $last = (int) $this->db->query('SELECT COALESCE(MAX(id), 0) FROM messages')->fetchColumn();
            $this->db->prepare("INSERT OR REPLACE INTO meta (key, value) VALUES ('feed_version', ?)")
                     ->execute([(string) $last]);
            return (string) $last;
        }

        return (string) $v;
    }

    /** Лента изменилась — сбросить ETag у всех клиентов. */
    public function bumpVersion(): void
    {
        $this->db->exec(
            "INSERT INTO meta (key, value) VALUES ('feed_version', '1')
             ON CONFLICT(key) DO UPDATE SET value = CAST(CAST(value AS INTEGER) + 1 AS TEXT)"
        );
    }

    public function total(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM messages WHERE deleted = 0')->fetchColumn();
    }

    /**
     * Отметить посетителя живым (для счётчика «онлайн»).
     *
     * Запись в БД не чаще раза в минуту на клиента: иначе каждый опрос чата
     * превращался бы в запись на диск — ровно та нагрузка, ради снижения
     * которой затевался ETag.
     */
    public function touch(string $client): void
    {
        $key = 'presence.' . $client;
        if (Cache::get($key) !== null) {
            return;
        }
        Cache::set($key, 1, 60);

        $stmt = $this->db->prepare(
            'INSERT INTO presence (client, last_hit) VALUES (?, ?)
             ON CONFLICT(client) DO UPDATE SET last_hit = excluded.last_hit'
        );
        $stmt->execute([$client, time()]);
    }

    public function online(int $window = 120): int
    {
        $cached = Cache::get('chat.online');
        if (is_int($cached)) {
            return $cached;
        }

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM presence WHERE last_hit > ?');
        $stmt->execute([time() - $window]);
        $n = (int) $stmt->fetchColumn();

        Cache::set('chat.online', $n, 15);

        return $n;
    }

    /** Чистка служебных таблиц; вызывается таймером. */
    public function prune(): void
    {
        $this->db->prepare('DELETE FROM presence WHERE last_hit < ?')->execute([time() - 86400]);
        $this->db->prepare('DELETE FROM rate WHERE ts < ?')->execute([time() - 7200]);
        $this->db->prepare('DELETE FROM bans WHERE until > 0 AND until < ?')->execute([time()]);
    }

    /** @param array<string,mixed> $row */
    private function toDto(array $row): array
    {
        return [
            'id'      => (int) $row['id'],
            'time'    => (int) $row['ts'],
            'name'    => (string) $row['name'],
            'content' => (string) $row['content'],
            'track'   => $row['track_id'] !== null ? (int) $row['track_id'] : null,
            'source'  => (string) $row['source'],
        ];
    }
}
