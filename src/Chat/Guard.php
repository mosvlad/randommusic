<?php
declare(strict_types=1);

namespace App\Chat;

use App\Support\Config;
use App\Support\Db;
use PDO;

/**
 * Антиспам чата.
 *
 * В v1 стояла Google reCAPTCHA, но её токен никуда не отправлялся и на
 * сервере не проверялся — то есть защиты не было вовсе, а зависимость от
 * Google в критическом пути была. Здесь несколько дешёвых слоёв, каждый из
 * которых отсекает свой класс злоупотреблений, и ни один не требует
 * внешнего сервиса.
 */
final class Guard
{
    public const OK              = 'ok';
    public const SHADOW          = 'shadow';   // пропустить, но показать только автору
    public const ERR_EMPTY       = 'empty';
    public const ERR_TOO_LONG    = 'too_long';
    public const ERR_NAME_LONG   = 'name_too_long';
    public const ERR_RATE        = 'rate_limited';
    public const ERR_BANNED      = 'banned';
    public const ERR_TOKEN       = 'bad_token';
    public const ERR_TOO_FAST    = 'too_fast';
    public const ERR_BOT         = 'bot';
    public const ERR_SPAM        = 'spam';

    /** Минимум секунд между отрисовкой формы и отправкой. */
    private const MIN_FILL_TIME = 1.2;

    /** Время жизни токена формы. */
    private const TOKEN_TTL = 43200;

    private PDO $db;

    public function __construct()
    {
        $this->db = Db::get('chat');
    }

    /**
     * Токен формы. Одновременно CSRF-защита и отметка времени отрисовки,
     * по которой видно ботов, отправляющих форму мгновенно.
     */
    public function issueToken(string $client): string
    {
        $ts = time();
        return $ts . '.' . $this->sign($client, $ts);
    }

    /**
     * @return array{0:string,1:array<string,mixed>} код результата и подробности
     */
    public function check(string $client, string $name, string $content, string $token, string $honeypot): array
    {
        if ($honeypot !== '') {
            // Поле скрыто через CSS; заполнить его может только автомат
            return [self::ERR_BOT, []];
        }

        $name    = trim($name);
        $content = trim($content);

        if ($name === '' || $content === '') {
            return [self::ERR_EMPTY, []];
        }

        $maxLen  = Config::int('CHAT_MAX_LEN', 256);
        $maxName = Config::int('CHAT_MAX_NAME', 32);

        if (mb_strlen($content) > $maxLen) {
            return [self::ERR_TOO_LONG, ['max' => $maxLen]];
        }
        if (mb_strlen($name) > $maxName) {
            return [self::ERR_NAME_LONG, ['max' => $maxName]];
        }

        $tokenCheck = $this->verifyToken($client, $token);
        if ($tokenCheck !== self::OK) {
            return [$tokenCheck, []];
        }

        $ban = $this->banFor($client);
        if ($ban !== null) {
            if ((int) $ban['shadow'] === 1) {
                return [self::SHADOW, []];
            }
            return [self::ERR_BANNED, ['until' => (int) $ban['until'], 'reason' => $ban['reason']]];
        }

        $rate = $this->rateCheck($client);
        if ($rate !== null) {
            return [self::ERR_RATE, $rate];
        }

        if ($this->looksLikeSpam($content)) {
            return [self::ERR_SPAM, []];
        }

        return [self::OK, []];
    }

    /** Зафиксировать отправку в окне антифлуда. */
    public function record(string $client): void
    {
        $this->db->prepare('INSERT INTO rate (client, ts) VALUES (?, ?)')->execute([$client, time()]);

        // Подчищаем своё же окно, чтобы таблица не росла
        if (random_int(1, 20) === 1) {
            $this->db->prepare('DELETE FROM rate WHERE ts < ?')->execute([time() - 7200]);
        }
    }

    /** @return array<string,mixed>|null */
    private function rateCheck(string $client): ?array
    {
        $now = time();

        $windows = [
            ['seconds' => 3,    'limit' => Config::int('CHAT_RATE_PER_3S', 1)],
            ['seconds' => 60,   'limit' => Config::int('CHAT_RATE_PER_MIN', 10)],
            ['seconds' => 3600, 'limit' => Config::int('CHAT_RATE_PER_HOUR', 100)],
        ];

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM rate WHERE client = ? AND ts > ?');

        foreach ($windows as $w) {
            if ($w['limit'] <= 0) {
                continue;
            }
            $stmt->execute([$client, $now - $w['seconds']]);
            if ((int) $stmt->fetchColumn() >= $w['limit']) {
                return ['window' => $w['seconds'], 'limit' => $w['limit'], 'retry_after' => $w['seconds']];
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function banFor(string $client): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT until, shadow, reason FROM bans WHERE client = ? AND (until = 0 OR until > ?)'
        );
        $stmt->execute([$client, time()]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
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
        return substr(hash_hmac('sha256', "chat|$client|$ts", $secret), 0, 32);
    }

    /**
     * Грубая эвристика: сообщение, состоящее в основном из ссылок,
     * и повторы одного символа во весь экран.
     */
    private function looksLikeSpam(string $content): bool
    {
        $links = preg_match_all('~https?://|www\.|t\.me/|\b[\w-]+\.(?:ru|com|net|org|xyz|top|club|online)\b~iu', $content);
        if ($links >= 3) {
            return true;
        }

        $len = mb_strlen($content);
        if ($links >= 1 && $len < 25) {
            return true;   // голая ссылка без текста
        }

        if ($len >= 20 && preg_match('/(.)\1{19,}/u', $content)) {
            return true;   // «ааааааааа...»
        }

        return false;
    }
}
