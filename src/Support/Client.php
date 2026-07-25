<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Идентификация посетителя для антифлуда и банов.
 *
 * Сам IP нигде не сохраняется — только необратимый хеш с секретной солью.
 * Этого достаточно, чтобы отличить флудера от остальных, и недостаточно,
 * чтобы по базе восстановить, кто откуда заходил.
 */
final class Client
{
    private static ?string $id = null;

    public static function id(): string
    {
        if (self::$id !== null) {
            return self::$id;
        }

        $salt = (string) Config::get('CLIENT_SALT', 'insecure-default');
        return self::$id = substr(hash_hmac('sha256', self::ip(), $salt), 0, 32);
    }

    public static function ip(): string
    {
        // Прямой Apache без внешнего прокси: REMOTE_ADDR доверенный,
        // заголовки X-Forwarded-For сознательно игнорируем — иначе бан
        // обходится одной строкой в curl.
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }
}
