-- Индекс медиатеки. Заменяет обход трёх каталогов opendir() на каждый запрос
-- и клиентский разбор ID3 повторной загрузкой mp3.

CREATE TABLE tracks (
  id          INTEGER PRIMARY KEY,
  path        TEXT    NOT NULL UNIQUE,       -- '/music/919.mp3', относительно docroot
  hash        TEXT    NOT NULL,              -- быстрый хеш: размер + края файла
  dup_key     TEXT,                          -- нормализованный 'артист|название|длительность'
  size        INTEGER NOT NULL,
  mtime       INTEGER NOT NULL,
  duration    REAL,
  bitrate     INTEGER,
  artist      TEXT,
  title       TEXT,
  album       TEXT,
  year        INTEGER,
  genre       TEXT,
  loudness    REAL,                          -- EBU R128 LUFS, считается фоновым заданием
  source      TEXT    NOT NULL,              -- '1000' | 'music' | 'upload'
  added_at    INTEGER NOT NULL,
  seen_at     INTEGER NOT NULL,              -- когда последний раз видели на диске
  present     INTEGER NOT NULL DEFAULT 1,    -- файл есть на диске
  duplicate   INTEGER NOT NULL DEFAULT 0,    -- байтовый дубль другого трека
  active      INTEGER NOT NULL DEFAULT 1,    -- present AND NOT duplicate; ротация идёт по нему
  weight      REAL    NOT NULL DEFAULT 1.0
);

CREATE INDEX idx_tracks_active  ON tracks(active);
CREATE INDEX idx_tracks_present ON tracks(present);
CREATE INDEX idx_tracks_hash    ON tracks(hash);
CREATE INDEX idx_tracks_dupkey  ON tracks(dup_key);
CREATE INDEX idx_tracks_added   ON tracks(added_at DESC);
CREATE INDEX idx_tracks_loud    ON tracks(loudness) WHERE loudness IS NULL;

-- Полнотекстовый поиск по библиотеке
CREATE VIRTUAL TABLE tracks_fts USING fts5(
  artist, title, album,
  content='tracks', content_rowid='id', tokenize='unicode61'
);

CREATE TRIGGER tracks_fts_ai AFTER INSERT ON tracks BEGIN
  INSERT INTO tracks_fts(rowid, artist, title, album)
  VALUES (new.id, new.artist, new.title, new.album);
END;

CREATE TRIGGER tracks_fts_ad AFTER DELETE ON tracks BEGIN
  INSERT INTO tracks_fts(tracks_fts, rowid, artist, title, album)
  VALUES ('delete', old.id, old.artist, old.title, old.album);
END;

CREATE TRIGGER tracks_fts_au AFTER UPDATE ON tracks BEGIN
  INSERT INTO tracks_fts(tracks_fts, rowid, artist, title, album)
  VALUES ('delete', old.id, old.artist, old.title, old.album);
  INSERT INTO tracks_fts(rowid, artist, title, album)
  VALUES (new.id, new.artist, new.title, new.album);
END;

-- Статистика прослушиваний. Нужна для весов в случайной выборке.
CREATE TABLE plays (
  id        INTEGER PRIMARY KEY,
  track_id  INTEGER NOT NULL REFERENCES tracks(id) ON DELETE CASCADE,
  ts        INTEGER NOT NULL,
  listened  REAL,
  skipped   INTEGER NOT NULL DEFAULT 0,
  client    TEXT
);

CREATE INDEX idx_plays_track ON plays(track_id, ts);
CREATE INDEX idx_plays_ts    ON plays(ts);

-- Служебные значения сканера (время последнего прохода и т.п.)
CREATE TABLE meta (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);
