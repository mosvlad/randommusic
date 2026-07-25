<?php
declare(strict_types=1);

namespace App\Http;

final class Request
{
    public readonly string $method;
    public readonly string $path;
    /** @var array<string,mixed> */
    public readonly array $query;
    /** @var array<string,mixed> */
    public readonly array $body;
    /** Префикс, по которому смонтировано приложение ('' или '/v2'). */
    public readonly string $base;

    private function __construct(string $method, string $path, array $query, array $body, string $base)
    {
        $this->method = $method;
        $this->path   = $path;
        $this->query  = $query;
        $this->body   = $body;
        $this->base   = $base;
    }

    public static function capture(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';

        // Приложение должно одинаково работать и в корне сайта, и в
        // подкаталоге (/v2 для обкатки перед выкаткой).
        $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
        if ($base === '.' || $base === '/') {
            $base = '';
        }
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $path = '/' . ltrim($path, '/');
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $body = $_POST;
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if ($body === [] && str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }
        }

        return new self($method, $path, $_GET, $body, $base);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $v = $this->query[$key] ?? null;
        return is_scalar($v) ? (string) $v : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->query[$key] ?? $this->body[$key] ?? null;
        return is_scalar($v) && is_numeric((string) $v) ? (int) $v : $default;
    }

    public function post(string $key, string $default = ''): string
    {
        $v = $this->body[$key] ?? null;
        return is_scalar($v) ? (string) $v : $default;
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($_SERVER[$key] ?? '');
    }

    public function isAjax(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest'
            || str_contains($this->header('Accept'), 'application/json');
    }

    /** Абсолютный URL для канонических ссылок и OpenGraph. */
    public function origin(): string
    {
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off';
        $host  = (string) ($_SERVER['HTTP_HOST'] ?? 'randommusic.insomnia247.nl');
        // Домен не хардкодим: после истории с блокировками переезд должен
        // сводиться к копированию папки.
        return ($https ? 'https://' : 'http://') . $host;
    }
}
