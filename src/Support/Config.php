<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Конфигурация из .env. Никаких секретов в коде.
 */
final class Config
{
    private static ?array $values = null;

    public static function load(?string $envFile = null): void
    {
        if (self::$values !== null) {
            return;
        }

        $envFile ??= APP_ROOT . '/.env';
        $values = [];

        if (is_readable($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $pos = strpos($line, '=');
                if ($pos === false) {
                    continue;
                }
                $key = trim(substr($line, 0, $pos));
                $val = trim(substr($line, $pos + 1));
                if (strlen($val) > 1 && ($val[0] === '"' || $val[0] === "'") && $val[-1] === $val[0]) {
                    $val = substr($val, 1, -1);
                }
                $values[$key] = $val;
            }
        }

        self::$values = $values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        $v = self::$values[$key] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v === null ? $default : (int) $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return string[] */
    public static function list(string $key, array $default = []): array
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return array_values(array_filter(array_map('trim', explode(',', (string) $v)), 'strlen'));
    }

    public static function docroot(): string
    {
        return rtrim((string) self::get('DOCROOT', '/home/faust_z/public_html/randommusic.insomnia247.nl'), '/');
    }

    public static function varDir(): string
    {
        return rtrim((string) self::get('VAR_DIR', APP_ROOT . '/var'), '/');
    }

    public static function isDebug(): bool
    {
        return self::bool('APP_DEBUG', false);
    }
}
