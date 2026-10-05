-- Аккаунты пользователей. Нужны только для одной вещи — загрузки треков;
-- общий чат остаётся анонимным, этой базы не касается.

CREATE TABLE users (
  id             INTEGER PRIMARY KEY,
  username       TEXT    NOT NULL,         -- как ввёл сам, для отображения
  username_lower TEXT    NOT NULL,         -- mb_strtolower(username), для сравнения
  password_hash  TEXT    NOT NULL,
  created_at     INTEGER NOT NULL,
  last_login_at  INTEGER
);

-- Уникальность и поиск без учёта регистра: Vasya и vasya — один и тот же
-- логин. Считаем на стороне PHP (mb_strtolower), а не SQL-функцией LOWER():
-- у SQLite она ASCII-only и не трогает кириллицу, а ники в проекте — с ней.
CREATE UNIQUE INDEX idx_users_username_lower ON users(username_lower);

CREATE TABLE sessions (
  token_hash TEXT    PRIMARY KEY,         -- sha256 от токена из cookie; сам токен не храним
  user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at INTEGER NOT NULL,
  expires_at INTEGER NOT NULL
);

CREATE INDEX idx_sessions_user    ON sessions(user_id);
CREATE INDEX idx_sessions_expires ON sessions(expires_at);

-- Антифлуд регистрации/входа — тот же приём, что в чате (chat.rate)
CREATE TABLE rate (
  scope  TEXT    NOT NULL,
  client TEXT    NOT NULL,
  ts     INTEGER NOT NULL
);
CREATE INDEX idx_rate ON rate(scope, client, ts);

CREATE TABLE meta (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);
