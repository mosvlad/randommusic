-- Очередь модерации пользовательских загрузок.
--
-- Файл до одобрения лежит в var/uploads/pending/ — вне docroot и вне
-- MEDIA_DIRS, так что Track\Scanner его не видит и в ротацию он не
-- попадает. Одобрение копирует файл в docroot/upload/ (тот же каталог,
-- что уже сканируется) и запускает пересканирование.

CREATE TABLE pending_uploads (
  id                INTEGER PRIMARY KEY,
  user_id           INTEGER NOT NULL,
  username          TEXT    NOT NULL,     -- снимок на момент загрузки
  original_name     TEXT    NOT NULL,     -- только для отображения, не доверяем
  stored_name       TEXT    NOT NULL UNIQUE, -- машинное имя файла на диске
  mime              TEXT    NOT NULL,
  size              INTEGER NOT NULL,
  duration          REAL,
  artist            TEXT,
  title             TEXT,
  status            TEXT    NOT NULL DEFAULT 'pending', -- pending|approved|rejected
  created_at        INTEGER NOT NULL,
  decided_at        INTEGER,
  decided_note      TEXT,
  approved_track_id INTEGER                -- заполняется bin/scan после пересканирования
);

CREATE INDEX idx_pending_status ON pending_uploads(status, created_at);
CREATE INDEX idx_pending_user   ON pending_uploads(user_id, status);

-- Антифлуд загрузок — свой, по account id, а не по IP
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
