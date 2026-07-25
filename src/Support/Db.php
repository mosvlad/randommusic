<?php
declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

/**
 * Фабрика подключений к SQLite. WAL, чтобы чтения не блокировали запись —
 * при полусотне читателей чата это обязательное условие.
 */
final class Db
{
    /** @var array<string, PDO> */
    private static array $connections = [];

    public static function get(string $name): PDO
    {
        if (isset(self::$connections[$name])) {
            return self::$connections[$name];
        }

        $dir = Config::varDir() . '/db';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Не удалось создать каталог БД: $dir");
        }

        $path = "$dir/$name.sqlite";
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);

        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        return self::$connections[$name] = $pdo;
    }

    /**
     * Применяет миграции из migrations/{name}/*.sql по порядку имён.
     * Идемпотентно: применённые версии помечаются в _migrations.
     *
     * @return string[] имена применённых на этом запуске миграций
     */
    public static function migrate(string $name): array
    {
        $pdo = self::get($name);
        $pdo->exec('CREATE TABLE IF NOT EXISTS _migrations (
            version TEXT PRIMARY KEY,
            applied_at INTEGER NOT NULL
        )');

        $applied = $pdo->query('SELECT version FROM _migrations')->fetchAll(PDO::FETCH_COLUMN);
        $applied = array_flip($applied);

        $dir = APP_ROOT . "/migrations/$name";
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob("$dir/*.sql") ?: [];
        sort($files, SORT_STRING);

        $done = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (isset($applied[$version])) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Не читается миграция: $file");
            }
            $pdo->exec('BEGIN');
            try {
                $pdo->exec($sql);
                $stmt = $pdo->prepare('INSERT INTO _migrations (version, applied_at) VALUES (?, ?)');
                $stmt->execute([$version, time()]);
                $pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                $pdo->exec('ROLLBACK');
                throw new RuntimeException("Миграция $version упала: " . $e->getMessage(), 0, $e);
            }
            $done[] = $version;
        }

        return $done;
    }
}
