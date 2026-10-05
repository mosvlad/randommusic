<?php
declare(strict_types=1);

namespace App\Upload;

use App\Support\Config;
use App\Support\Db;
use PDO;

/**
 * CSRF-токен и антифлуд формы загрузки — тот же приём, что в Chat\Guard
 * и Auth\Guard, в своей базе: лимит здесь по аккаунту, а не по IP —
 * загружать может только залогиненный, и это более честный ключ.
 */
final class Guard
{
    public const OK           = 'ok';
    public const ERR_TOKEN    = 'bad_token';
    public const ERR_TOO_FAST = 'too_fast';
    public const ERR_RATE     = 'rate_limited';
    public const ERR_BOT      = 'bot';

    private const MIN_FILL_TIME = 1.2;
    private const TOKEN_TTL     = 1800;

    private PDO $db;

    public function __construct()
    {
        $this->db = Db::get('uploads');
    }

    public function issueToken(string $client): string
    {
        $ts = time();
        return $ts . '.' . $this->sign($client, $ts);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    public function check(string $client, string $token, string $honeypot, int $perDay): array
    {
        if ($honeypot !== '') {
            return [self::ERR_BOT, []];
        }

        $tokenCheck = $this->verifyToken($client, $token);
        if ($tokenCheck !== self::OK) {
            return [$tokenCheck, []];
        }

        if ($perDay > 0) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM rate WHERE scope = 'upload' AND client = ? AND ts > ?");
            $stmt->execute([$client, time() - 86400]);
            if ((int) $stmt->fetchColumn() >= $perDay) {
                return [self::ERR_RATE, ['window' => 86400, 'limit' => $perDay]];
            }
        }

        return [self::OK, []];
    }

    public function record(string $client): void
    {
        $this->db->prepare("INSERT INTO rate (scope, client, ts) VALUES ('upload', ?, ?)")
            ->execute([$client, time()]);
    }

    private function verifyToken(string $client, string $token): string
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0])) {
            return self::ERR_TOKEN;
        }

        [$ts, $sig] = $parts;
        $ts = (int) $ts;

        if (!hash_equals($this->sign($client, $ts), $sig)) {
            return self::ERR_TOKEN;
        }

        $age = time() - $ts;
        if ($age > self::TOKEN_TTL || $age < -60) {
            return self::ERR_TOKEN;
        }
        if ($age < self::MIN_FILL_TIME) {
            return self::ERR_TOO_FAST;
        }

        return self::OK;
    }

    private function sign(string $client, int $ts): string
    {
        $secret = (string) Config::get('CLIENT_SALT', 'insecure-default');
        return substr(hash_hmac('sha256', "upload|$client|$ts", $secret), 0, 32);
    }
}
