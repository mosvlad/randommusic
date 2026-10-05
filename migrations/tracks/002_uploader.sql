-- Атрибуция треков, пришедших через модерацию загрузок. NULL у всей
-- остальной библиотеки — колонки заполняет только bin/scan, и только
-- для путей из одобренных pending_uploads.

ALTER TABLE tracks ADD COLUMN uploader_user_id  INTEGER;
ALTER TABLE tracks ADD COLUMN uploader_username TEXT;

CREATE INDEX idx_tracks_uploader ON tracks(uploader_user_id);
