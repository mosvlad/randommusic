<?php
declare(strict_types=1);

namespace App\Track;

use App\Support\Db;
use PDO;

/**
 * Учёт прослушиваний. В v1 аналитики фактически не было: счётчик
 * Universal Analytics перестал собирать данные в 2023 году, а вызов
 * gtag('ButtonNext','Next') был сделан до определения функции.
 *
 * Здесь считаем сами и храним у себя.
 */
final class Stats
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Db::get('tracks');
    }

    /**
     * @param string $event 'played' — дослушал, 'skipped' — переключил
     */
    public function record(int $trackId, string $event, float $listened, ?string $client = null): void
    {
        if ($trackId <= 0) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO plays (track_id, ts, listened, skipped, client)
             SELECT ?, ?, ?, ?, ? WHERE EXISTS (SELECT 1 FROM tracks WHERE id = ?)'
        );
        $stmt->execute([
            $trackId,
            time(),
            max(0.0, round($listened, 1)),
            $event === 'skipped' ? 1 : 0,
            $client !== null ? substr($client, 0, 32) : null,
            $trackId,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function top(int $days = 30, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.id, t.artist, t.title, COUNT(*) AS plays,
                    SUM(p.skipped) AS skips
             FROM plays p JOIN tracks t ON t.id = p.track_id
             WHERE p.ts > ? AND t.active = 1
             GROUP BY t.id ORDER BY plays DESC LIMIT ?'
        );
        $stmt->bindValue(1, time() - $days * 86400, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<string,int> */
    public function summary(int $days = 30): array
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS total, SUM(skipped) AS skipped,
                    COALESCE(SUM(listened), 0) AS seconds
             FROM plays WHERE ts > ?'
        );
        $stmt->execute([time() - $days * 86400]);
        $row = $stmt->fetch() ?: [];

        return [
            'plays'   => (int) ($row['total'] ?? 0),
            'skips'   => (int) ($row['skipped'] ?? 0),
            'hours'   => (int) round(((float) ($row['seconds'] ?? 0)) / 3600),
        ];
    }

    /**
     * Пересчёт весов ротации по доле скипов в первые 15 секунд.
     *
     * Диапазон намеренно узкий (0.35…1.15): библиотека должна оставаться
     * случайной, а не превращаться в подборку из десяти любимых треков.
     * Треки с малой статистикой не трогаем.
     */
    public function recomputeWeights(int $minPlays = 8): int
    {
        $sql = '
            WITH s AS (
                SELECT track_id,
                       COUNT(*) AS n,
                       AVG(CASE WHEN skipped = 1 AND listened < 15 THEN 1.0 ELSE 0.0 END) AS early_skip
                FROM plays
                WHERE ts > :since
                GROUP BY track_id
                HAVING COUNT(*) >= :min
            )
            UPDATE tracks
            SET weight = MAX(0.35, MIN(1.15, 1.15 - 0.9 * (
                SELECT early_skip FROM s WHERE s.track_id = tracks.id
            )))
            WHERE id IN (SELECT track_id FROM s)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['since' => time() - 180 * 86400, 'min' => $minPlays]);

        return $stmt->rowCount();
    }
}
