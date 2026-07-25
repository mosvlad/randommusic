<?php
declare(strict_types=1);

namespace App\Http;

final class Response
{
    private function __construct(
        private int $status,
        private string $body,
        /** @var array<string,string> */
        private array $headers = []
    ) {
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{"error":"encoding_failed"}';
            $status = 500;
        }

        return new self($status, $json, $headers + [
            'Content-Type'  => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public static function error(string $code, int $status = 400, array $extra = []): self
    {
        return self::json(['error' => $code] + $extra, $status);
    }

    public static function html(string $html, int $status = 200, array $headers = []): self
    {
        return new self($status, $html, $headers + [
            'Content-Type'  => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public static function text(string $text, int $status = 200, array $headers = []): self
    {
        return new self($status, $text, $headers + ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function xml(string $xml, int $status = 200, array $headers = []): self
    {
        return new self($status, $xml, $headers + ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    public static function notModified(string $etag): self
    {
        return new self(304, '', ['ETag' => $etag, 'Cache-Control' => 'no-cache']);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self($status, '', ['Location' => $to]);
    }

    public static function noContent(): self
    {
        return new self(204, '');
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header("$name: $value");
            }
        }
        echo $this->body;
    }
}
