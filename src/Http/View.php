<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Рендер PHP-шаблонов. Никакого шаблонизатора: сайт того не стоит,
 * а лишняя зависимость противоречит идее «работает из чистого clone».
 */
final class View
{
    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = []): string
    {
        $file = APP_ROOT . '/templates/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Нет шаблона: $template");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $file;

        return (string) ob_get_clean();
    }

    /** Экранирование для HTML-контекста. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Значение для встраивания в JS/JSON внутри страницы. */
    public static function json(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }
}
