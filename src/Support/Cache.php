<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Кэш: APCu, если доступен (общая память между процессами prefork),
 * иначе файлы в var/cache. Второе нужно для CLI, где apcu.enable_cli
 * обычно выключен.
 */
final class Cache
{
    private static ?bool $apcu = null;

    private static function apcu(): bool
    {
        return self::$apcu ??= (function_exists('apcu_fetch') && apcu_enabled());
    }

    public static function get(string $key): mixed
    {
        if (self::apcu()) {
            $ok = false;
            $val = apcu_fetch(self::ns($key), $ok);
            return $ok ? $val : null;
        }

        $file = self::file($key);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($data) || !isset($data['exp'], $data['val'])) {
            return null;
        }
        if ($data['exp'] > 0 && $data['exp'] < time()) {
            @unlink($file);
            return null;
        }
        return $data['val'];
    }

    public static function set(string $key, mixed $value, int $ttl = 300): void
    {
        if (self::apcu()) {
            apcu_store(self::ns($key), $value, $ttl);
            return;
        }

        $dir = Config::varDir() . '/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $payload = serialize(['exp' => $ttl > 0 ? time() + $ttl : 0, 'val' => $value]);
        $tmp = self::file($key) . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            @rename($tmp, self::file($key));
        }
    }

    public static function delete(string $key): void
    {
        if (self::apcu()) {
            apcu_delete(self::ns($key));
            return;
        }
        @unlink(self::file($key));
    }

    /** Сбросить всё, что кэширует приложение (после сканирования медиатеки). */
    public static function flush(): void
    {
        if (self::apcu()) {
            foreach (['tracks.index', 'tracks.count', 'stats.summary'] as $k) {
                apcu_delete(self::ns($k));
            }
        }
        foreach (glob(Config::varDir() . '/cache/*.cache') ?: [] as $f) {
            @unlink($f);
        }
    }

    private static function ns(string $key): string
    {
        return 'rm2:' . $key;
    }

    private static function file(string $key): string
    {
        return Config::varDir() . '/cache/' . sha1($key) . '.cache';
    }
}
