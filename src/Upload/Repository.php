<?php
declare(strict_types=1);

namespace App\Upload;

use App\Support\Db;
use PDO;

/**
 * Очередь модерации. Хранилище — Chat\Repository по духу: простой CRUD
 * вокруг одной таблицы, версию ленты не считаем — админка не поллит.
 */
final class Repository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Db::get('uploads');
    }

    /** @param array<string,mixed> $row */
    public function insert(array $row): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO pending_uploads
               (user_id, username, original_name, stored_name, mime, size, duration, artist, title, created_at)
             VALUES (:user_id, :username, :original_name, :stored_name, :mime, :size, :duration, :artist, :title, :created_at)'
        );
        $stmt->execute([
            ':user_id'       => $row['user_id'],
            ':username'      => $row['username'],
            ':original_name' => $row['original_name'],
            ':stored_name'   => $row['stored_name'],
            ':mime'          => $row['mime'],
            ':size'          => $row['size'],
            ':duration'      => $row['duration'],
            ':artist'        => $row['artist'] ?: null,
            ':title'         => $row['title'] ?: null,
            ':created_at'    => time(),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM pending_uploads WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function listPending(int $limit = 100): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM pending_uploads WHERE status = 'pending' ORDER BY created_at ASC LIMIT ?"
        );
        $stmt->bindValue(1, max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** Недавно решённые — короткий журнал на странице модерации. */
    public function listDecided(int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM pending_uploads WHERE status != 'pending' ORDER BY decided_at DESC LIMIT ?"
        );
        $stmt->bindValue(1, max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countPendingForUser(int $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM pending_uploads WHERE user_id = ? AND status = 'pending'"
        );
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<int,array<string,mixed>> загрузки пользователя — для его личной страницы */
    public function listForUser(int $userId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM pending_uploads WHERE user_id = ? ORDER BY created_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function markApproved(int $id): void
    {
        $this->db->prepare(
            "UPDATE pending_uploads SET status = 'approved', decided_at = ? WHERE id = ?"
        )->execute([time(), $id]);
    }

    public function markRejected(int $id, string $note = ''): void
    {
        $this->db->prepare(
            "UPDATE pending_uploads SET status = 'rejected', decided_at = ?, decided_note = ? WHERE id = ?"
        )->execute([time(), $note !== '' ? $note : null, $id]);
    }

    /** Старые решённые записи и окно антифлуда; вызывается таймером (bin/maintenance). */
    public function prune(int $decidedDays = 180): void
    {
        $this->db->prepare(
            "DELETE FROM pending_uploads WHERE status != 'pending' AND decided_at < ?"
        )->execute([time() - $decidedDays * 86400]);
        $this->db->prepare('DELETE FROM rate WHERE ts < ?')->execute([time() - 86400]);
    }
}
