<?php
declare(strict_types=1);

namespace App\Chat;

use App\Support\Config;
use App\Support\Db;
use PDO;

/**
 * Модерация. В v1 её не было никакой: удалить сообщение или остановить
 * флудера можно было только правкой messages.json руками.
 */
final class Moderation
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Db::get('chat');
    }

    /** Проверка админского токена в постоянном времени. */
    public static function checkToken(string $given): bool
    {
        $expected = (string) Config::get('ADMIN_TOKEN', '');
        if ($expected === '' || strlen($given) < 16) {
            return false;
        }

        return hash_equals($expected, $given);
    }

    public function deleteMessage(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE messages SET deleted = 1 WHERE id = ?');
        $stmt->execute([$id]);
        (new Repository())->bumpVersion();

        return $stmt->rowCount() > 0;
    }

    public function restoreMessage(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE messages SET deleted = 0 WHERE id = ?');
        $stmt->execute([$id]);
        (new Repository())->bumpVersion();

        return $stmt->rowCount() > 0;
    }

    /**
     * Бан по client-хешу.
     *
     * @param int  $seconds 0 — навсегда
     * @param bool $shadow  теневой: автор видит свои сообщения, остальные нет.
     *                      Против упорных работает лучше явного бана — человек
     *                      не понимает, что его отключили, и не идёт менять IP.
     */
    public function ban(string $client, int $seconds = 3600, bool $shadow = false, string $reason = ''): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO bans (client, until, shadow, reason, created) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(client) DO UPDATE SET
                until = excluded.until, shadow = excluded.shadow, reason = excluded.reason'
        );
        $stmt->execute([$client, $seconds > 0 ? time() + $seconds : 0, $shadow ? 1 : 0, $reason, time()]);
    }

    public function unban(string $client): void
    {
        $this->db->prepare('DELETE FROM bans WHERE client = ?')->execute([$client]);
    }

    /** Забанить автора сообщения, не зная его client-хеша. */
    public function banAuthorOf(int $messageId, int $seconds, bool $shadow, string $reason = ''): ?string
    {
        $stmt = $this->db->prepare('SELECT client FROM messages WHERE id = ?');
        $stmt->execute([$messageId]);
        $client = $stmt->fetchColumn();

        if (!is_string($client) || $client === '') {
            return null;
        }

        $this->ban($client, $seconds, $shadow, $reason);

        return $client;
    }

    /** Скрыть все сообщения клиента разом — при набеге удобнее поштучного. */
    public function purgeClient(string $client, int $sinceSeconds = 3600): int
    {
        $stmt = $this->db->prepare('UPDATE messages SET deleted = 1 WHERE client = ? AND ts > ?');
        $stmt->execute([$client, time() - $sinceSeconds]);
        (new Repository())->bumpVersion();

        return $stmt->rowCount();
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $limit = 100): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, ts, name, content, client, deleted, shadow, source
             FROM messages ORDER BY id DESC LIMIT ?'
        );
        $stmt->bindValue(1, max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function bans(): array
    {
        return $this->db->query(
            'SELECT client, until, shadow, reason, created FROM bans ORDER BY created DESC'
        )->fetchAll();
    }

    /**
     * Самые активные за период — по ним видно набег.
     *
     * @return array<int,array<string,mixed>>
     */
    public function topPosters(int $seconds = 3600, int $limit = 15): array
    {
        $stmt = $this->db->prepare(
            'SELECT client, COUNT(*) AS n, MAX(name) AS name, MAX(ts) AS last_ts
             FROM messages WHERE ts > ? GROUP BY client ORDER BY n DESC LIMIT ?'
        );
        $stmt->bindValue(1, time() - $seconds, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
