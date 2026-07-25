-- Чат. В v1 хранились последние 50 сообщений в messages.json,
-- всё остальное уничтожалось безвозвратно (~5800 сообщений за время жизни).

CREATE TABLE messages (
  id        INTEGER PRIMARY KEY,          -- продолжает нумерацию v1
  ts        INTEGER NOT NULL,
  name      TEXT    NOT NULL,
  content   TEXT    NOT NULL,
  client    TEXT    NOT NULL DEFAULT '',  -- хеш(ip + соль), сам IP не храним
  track_id  INTEGER,                      -- что играло у автора
  deleted   INTEGER NOT NULL DEFAULT 0,
  shadow    INTEGER NOT NULL DEFAULT 0,   -- теневой бан: видно только автору
  source    TEXT    NOT NULL DEFAULT 'web'
);

CREATE INDEX idx_messages_live ON messages(id) WHERE deleted = 0;
CREATE INDEX idx_messages_ts   ON messages(ts);
CREATE INDEX idx_messages_cli  ON messages(client, ts);

CREATE TABLE bans (
  client  TEXT PRIMARY KEY,
  until   INTEGER NOT NULL,               -- 0 = навсегда
  shadow  INTEGER NOT NULL DEFAULT 0,
  reason  TEXT,
  created INTEGER NOT NULL
);

-- Скользящее окно антифлуда
CREATE TABLE rate (
  client TEXT    NOT NULL,
  ts     INTEGER NOT NULL
);
CREATE INDEX idx_rate ON rate(client, ts);

-- Онлайн-присутствие: считаем читателей без внешних счётчиков
CREATE TABLE presence (
  client   TEXT PRIMARY KEY,
  last_hit INTEGER NOT NULL
);
CREATE INDEX idx_presence_hit ON presence(last_hit);

CREATE TABLE meta (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);
