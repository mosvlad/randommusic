-- Донаты с DonationAlerts. Внешний сервис опрашивает таймер
-- (bin/donations-poll) и складывает донаты сюда; главная показывает
-- последний, не обращаясь наружу на каждый просмотр.

CREATE TABLE donations (
  id           INTEGER PRIMARY KEY,           -- id доната в DonationAlerts
  username     TEXT    NOT NULL DEFAULT '',   -- имя, как его ввёл донатер
  message      TEXT    NOT NULL DEFAULT '',
  message_type TEXT    NOT NULL DEFAULT 'text', -- text | audio
  amount       REAL    NOT NULL DEFAULT 0,    -- сумма, как её отправил донатер
  currency     TEXT    NOT NULL DEFAULT '',
  created_at   INTEGER NOT NULL,              -- unix, из created_at ответа (UTC)
  fetched_at   INTEGER NOT NULL               -- когда мы это забрали
);

CREATE INDEX idx_donations_created ON donations(created_at DESC);

-- Токены OAuth (после первой авторизации) и счётчик версии ленты для ETag.
CREATE TABLE meta (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);
