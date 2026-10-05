<?php
declare(strict_types=1);

namespace App\Auth;

use App\Support\Config;
use App\Support\Db;
use PDO;

/**
 * Аккаунты и сессии. Нужны только для одного: доступа к форме загрузки
 * трека. Без email и без восстановления пароля — забыл пароль, заводи
 * новый аккаунт или проси админа удалить старый через БД.
 */
final class Repository
{
    public const COOKIE = 'rm_user';

    public const ERR_USERNAME_INVALID = 'username_invalid';
    public const ERR_USERNAME_TAKEN   = 'username_taken';
    public const ERR_PASSWORD_WEAK    = 'password_weak';
    public const ERR_PASSWORD_MISMATCH = 'password_mismatch';
    public const ERR_BAD_CREDENTIALS  = 'bad_credentials';

    private PDO $db;

    /** Кэш на время запроса — currentUser() дёргают из нескольких мест. */
    private static ?array $resolved = null;
    private static bool $resolvedSet = false;

    public function __construct()
    {
        $this->db = Db::get('auth');
    }

    /**
     * @return array{0:string,1:array<string,mixed>} код ошибки ('' — успех) и подробности
     */
    public function register(string $username, string $password, string $passwordConfirm): array
    {
        $username = trim($username);

        if (!self::isValidUsername($username)) {
            return [self::ERR_USERNAME_INVALID, ['min' => self::usernameMin(), 'max' => self::usernameMax()]];
        }

        $minPass = Config::int('AUTH_PASSWORD_MIN', 8);
        if (mb_strlen($password) < $minPass) {
            return [self::ERR_PASSWORD_WEAK, ['min' => $minPass]];
        }
        if ($password !== $passwordConfirm) {
            return [self::ERR_PASSWORD_MISMATCH, []];
        }

        if ($this->findByUsername($username) !== null) {
            return [self::ERR_USERNAME_TAKEN, []];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO users (username, username_lower, password_hash, created_at) VALUES (?, ?, ?, ?)'
        );
        try {
            $stmt->execute([$username, mb_strtolower($username), password_hash($password, PASSWORD_DEFAULT), time()]);
        } catch (\PDOException) {
            // Гонка: кто-то успел занять имя между проверкой и вставкой
            return [self::ERR_USERNAME_TAKEN, []];
        }

        $userId = (int) $this->db->lastInsertId();
        $this->issueSession($userId);

        return ['', ['id' => $userId, 'username' => $username]];
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    public function login(string $username, string $password): array
    {
        $user = $this->findByUsername(trim($username));

        // Таймингово-безопасно: и при несуществующем логине тратим время
        // на bcrypt, иначе по скорости ответа угадывается, какие ники заняты.
        $hash = $user['password_hash'] ?? '$2y$10$dBSNBwjs03ogHQCN2d3oPeYEV940keN.FnNNpVq3ImSKTPjDplqoa';
        $ok   = password_verify($password, $hash);

        if ($user === null || !$ok) {
            return [self::ERR_BAD_CREDENTIALS, []];
        }

        $userId = (int) $user['id'];
        $this->db->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([time(), $userId]);
        $this->issueSession($userId);

        return ['', ['id' => $userId, 'username' => $user['username']]];
    }

    /** Текущий пользователь по cookie сессии, либо null. */
    public function currentUser(): ?array
    {
        if (self::$resolvedSet) {
            return self::$resolved;
        }
        self::$resolvedSet = true;

        $raw = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($raw === '') {
            return self::$resolved = null;
        }

        $hash = hash('sha256', $raw);
        $stmt = $this->db->prepare(
            'SELECT s.user_id, u.username FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = ? AND s.expires_at > ?'
        );
        $stmt->execute([$hash, time()]);
        $row = $stmt->fetch();

        if ($row === false) {
            return self::$resolved = null;
        }

        return self::$resolved = ['id' => (int) $row['user_id'], 'username' => (string) $row['username']];
    }

    public function logout(): void
    {
        $raw = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($raw !== '') {
            $this->db->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([hash('sha256', $raw)]);
        }
        self::$resolved = null;
        self::$resolvedSet = true;

        setcookie(self::COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** Чистка просроченных сессий; вызывается таймером (bin/maintenance). */
    public function prune(): void
    {
        $this->db->prepare('DELETE FROM sessions WHERE expires_at < ?')->execute([time()]);
        $this->db->prepare('DELETE FROM rate WHERE ts < ?')->execute([time() - 86400]);
    }

    public static function usernameMin(): int
    {
        return Config::int('AUTH_USERNAME_MIN', 3);
    }

    public static function usernameMax(): int
    {
        return Config::int('AUTH_USERNAME_MAX', 20);
    }

    /**
     * Буквы (включая кириллицу), цифры, подчёркивание и дефис — как в
     * чате ники свободные, незачем сужать до ASCII для другой формы.
     * Пробелы и управляющие символы запрещены: ник уходит в URL
     * (клиентской части — нет, но в "Загрузил: …" в разметке) и в токен.
     */
    public static function isValidUsername(string $username): bool
    {
        $len = mb_strlen($username);
        if ($len < self::usernameMin() || $len > self::usernameMax()) {
            return false;
        }

        return (bool) preg_match('/^[\p{L}\p{N}_-]+$/u', $username);
    }

    /**
     * Сравнение без учёта регистра — по mb_strtolower(), не SQL LOWER():
     * у SQLite эта функция ASCII-only и кириллицу не трогает.
     *
     * @return array<string,mixed>|null
     */
    private function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare('SELECT id, username, password_hash FROM users WHERE username_lower = ?');
        $stmt->execute([mb_strtolower($username)]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function issueSession(int $userId): void
    {
        $raw  = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $ttl  = max(1, Config::int('SESSION_TTL_DAYS', 180)) * 86400;

        $this->db->prepare(
            'INSERT INTO sessions (token_hash, user_id, created_at, expires_at) VALUES (?, ?, ?, ?)'
        )->execute([$hash, $userId, time(), time() + $ttl]);

        setcookie(self::COOKIE, $raw, [
            'expires'  => time() + $ttl,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
