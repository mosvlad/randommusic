<?php
declare(strict_types=1);

namespace App\Auth;

use App\Support\Config;
use App\Support\Db;
use PDO;

/**
 * Антиспам и CSRF для регистрации и входа — тот же приём, что в
 * Chat\Guard: подписанный токен формы (одновременно защита от CSRF и
 * отметка времени отрисовки) плюс окна антифлуда по client-хешу.
 */
final class Guard
{
    public const OK           = 'ok';
    public const ERR_TOKEN    = 'bad_token';
    public const ERR_TOO_FAST = 'too_fast';
    public const ERR_RATE     = 'rate_limited';
    public const ERR_BOT      = 'bot';

    /** Минимум секунд между отрисовкой формы и отправкой. */
    private const MIN_FILL_TIME = 1.2;

    /** Время жизни токена формы. */
    private const TOKEN_TTL = 1800;

    private PDO $db;

    public function __construct()
    {
        $this->db = Db::get('auth');
    }

    public function issueToken(string $scope, string $client): string
    {
        $ts = time();
        return $ts . '.' . $this->sign($scope, $client, $ts);
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    public function check(string $scope, string $client, string $token, string $honeypot, int $perHour): array
    {
        if ($honeypot !== '') {
            return [self::ERR_BOT, []];
        }

        $tokenCheck = $this->verifyToken($scope, $client, $token);
        if ($tokenCheck !== self::OK) {
            return [$tokenCheck, []];
        }

        $rate = $this->rateCheck($scope, $client, $perHour);
        if ($rate !== null) {
            return [self::ERR_RATE, $rate];
        }

        return [self::OK, []];
    }

    public function record(string $scope, string $client): void
    {
        $this->db->prepare('INSERT INTO rate (scope, client, ts) VALUES (?, ?, ?)')
            ->execute([$scope, $client, time()]);

        if (random_int(1, 20) === 1) {
            $this->db->prepare('DELETE FROM rate WHERE ts < ?')->execute([time() - 86400]);
        }
    }

    /** @return array<string,mixed>|null */
    private function rateCheck(string $scope, string $client, int $perHour): ?array
    {
        if ($perHour <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM rate WHERE scope = ? AND client = ? AND ts > ?');
        $stmt->execute([$scope, $client, time() - 3600]);
        if ((int) $stmt->fetchColumn() >= $perHour) {
            return ['window' => 3600, 'limit' => $perHour];
        }

        return null;
    }

    private function verifyToken(string $scope, string $client, string $token): string
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0])) {
            return self::ERR_TOKEN;
        }

        [$ts, $sig] = $parts;
        $ts = (int) $ts;

        if (!hash_equals($this->sign($scope, $client, $ts), $sig)) {
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

    private function sign(string $scope, string $client, int $ts): string
    {
        $secret = (string) Config::get('CLIENT_SALT', 'insecure-default');
        return substr(hash_hmac('sha256', "$scope|$client|$ts", $secret), 0, 32);
    }
}
