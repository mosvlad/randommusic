<?php
declare(strict_types=1);

namespace App\Donation;

use App\Support\Db;
use PDO;

/**
 * Хранилище донатов.
 *
 * DonationAlerts — внешний сервис. Чтобы главная не ходила туда на каждый
 * просмотр (и продолжала работать, когда сервис недоступен), список
 * донатов забирает таймер bin/donations-poll и кладёт сюда. Отсюда же
 * страница берёт последний донат для показа.
 */
final class Repository
{
    private const MAX_MESSAGE = 300;
    private const MAX_NAME     = 100;

    private PDO $db;

    public function __construct()
    {
        $this->db = Db::get('donations');
    }

    /**
     * Последний донат для главной, либо null, если их ещё нет.
     *
     * @return array<string,mixed>|null
     */
    public function latest(): ?array
    {
        $row = $this->db->query(
            'SELECT id, username, message, message_type, amount, currency, created_at
             FROM donations
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        )->fetch();

        return $row === false ? null : $this->toDto($row);
    }

    /**
     * Вставить донаты, которых ещё нет. Возвращает число новых.
     *
     * Порядок в ответе DonationAlerts значения не имеет: кладём всё,
     * дубли отсекает первичный ключ.
     *
     * @param array<int,array<string,mixed>> $items как их отдаёт DonationAlerts
     */
    public function ingest(array $items): int
    {
        $stmt = $this->db->prepare(
            'INSERT OR IGNORE INTO donations
               (id, username, message, message_type, amount, currency, created_at, fetched_at)
             VALUES (:id, :username, :message, :type, :amount, :currency, :created_at, :fetched_at)'
        );

        $now = time();
        $new = 0;

        foreach ($items as $d) {
            if (!isset($d['id'])) {
                continue;
            }

            $created = isset($d['created_at'])
                ? (int) strtotime((string) $d['created_at'] . ' UTC')
                : $now;

            $stmt->execute([
                ':id'         => (int) $d['id'],
                ':username'   => mb_substr((string) ($d['username'] ?? ''), 0, self::MAX_NAME),
                ':message'    => mb_substr((string) ($d['message'] ?? ''), 0, self::MAX_MESSAGE),
                ':type'       => (string) ($d['message_type'] ?? 'text'),
                ':amount'     => (float) ($d['amount'] ?? 0),
                ':currency'   => (string) ($d['currency'] ?? ''),
                ':created_at' => $created > 0 ? $created : $now,
                ':fetched_at' => $now,
            ]);
            $new += $stmt->rowCount();
        }

        if ($new > 0) {
            $this->bumpVersion();
        }

        return $new;
    }

    public function total(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM donations')->fetchColumn();
    }

    /**
     * Версия ленты для ETag: меняется, когда появляется новый донат,
     * чтобы открытая вкладка узнала об этом одним запросом с 304.
     */
    public function version(): string
    {
        $v = $this->metaGet('feed_version');
        if ($v === null) {
            $v = (string) $this->total();
            $this->metaSet('feed_version', $v);
        }

        return $v;
    }

    public function bumpVersion(): void
    {
        $this->db->exec(
            "INSERT INTO meta (key, value) VALUES ('feed_version', '1')
             ON CONFLICT(key) DO UPDATE SET value = CAST(CAST(value AS INTEGER) + 1 AS TEXT)"
        );
    }

    public function metaGet(string $key): ?string
    {
        $stmt = $this->db->prepare('SELECT value FROM meta WHERE key = ?');
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : (string) $v;
    }

    public function metaSet(string $key, string $value): void
    {
        $this->db->prepare(
            'INSERT INTO meta (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        )->execute([$key, $value]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function toDto(array $row): array
    {
        // У аудио-донатов текста нет — показывать нечего
        $message = (string) $row['message_type'] === 'text' ? (string) $row['message'] : '';

        return [
            'id'       => (int) $row['id'],
            'username' => (string) $row['username'],
            'message'  => $message,
            'amount'   => round((float) $row['amount'], 2),
            'currency' => (string) $row['currency'],
            'time'     => (int) $row['created_at'],
        ];
    }
}
