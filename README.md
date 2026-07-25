# RandomMusic 2.0

Сайт со случайной музыкой и общим чатом: https://randommusic.insomnia247.nl

PHP 8.2 без фреймворка, SQLite, нативные ES-модули. Ни одной внешней
зависимости в рантайме — приложение поднимается из чистого `git clone`.

## Раскладка

```
apps/randommusic/          ← код, вне docroot
├── src/                   приложение (App\*, PSR-4 без composer)
├── templates/             PHP-шаблоны
├── migrations/            SQL-миграции по базам
├── bin/                   CLI: scan, loudness, migrate, backup, deploy…
├── public/                то, что уезжает в веб-корень
├── deploy/systemd/        таймеры systemd --user
├── var/db/                chat.sqlite, tracks.sqlite (не в git)
└── legacy/v1/             снимок продакшена до переработки

public_html/randommusic.insomnia247.nl/   ← docroot
├── index.php  .htaccess  assets/         из public/
├── 1000/ music/ upload/                  симлинки на медиатеку
└── css/ js/ img/ dist/                   остатки v1 для старых вкладок
```

## Запуск с нуля

```bash
cp .env.example .env && chmod 600 .env    # заполнить ADMIN_TOKEN, CLIENT_SALT
bin/migrate                                # создать базы
bin/import-legacy                          # перенести чат из messages.json
bin/scan --full                            # обойти медиатеку (~6 мин на 3900 файлов)
bin/loudness --all                         # замерить громкость (~30 мин)
bin/deploy staging                         # выложить в /v2 для проверки
bin/deploy prod                            # выложить в корень (снимет бэкап)
```

## Эксплуатация

| Команда | Что делает |
|---|---|
| `bin/scan` | Инкрементальный обход медиатеки (секунды) |
| `bin/scan --full` | Перечитать все теги заново |
| `bin/loudness` | Замерить громкость партии треков |
| `bin/maintenance` | Чистка таблиц, пересчёт весов, WAL, VACUUM |
| `bin/backup` | Снимок баз в `/coldstorage/faust_z/backup/randommusic-db` |
| `bin/deploy prod` | Выкатка (с автоматическим бэкапом docroot) |

Таймеры `systemd --user` (включены, `Linger=yes`):

* `randommusic-scan.timer` — каждые 15 мин, подхватывает загрузки из Telegram
* `randommusic-loudness.timer` — каждые 30 мин, догоняет замер новых треков
* `randommusic-maintenance.timer` — ежедневно в 04:30, обслуживание + бэкап

```bash
systemctl --user list-timers 'randommusic-*'
journalctl --user -u randommusic-scan -n 50
```

## API

| Метод | Путь | Назначение |
|---|---|---|
| GET | `/api/v1/track/random?exclude=1,2,3` | Случайный трек с тегами |
| GET | `/api/v1/track/{id}` | Метаданные трека |
| GET | `/api/v1/track/search?q=` | Поиск (FTS5) |
| POST | `/api/v1/track/{id}/event` | `event=played|skipped`, `listened=сек` |
| GET | `/api/v1/chat?since={id}` | Инкрементальная лента (ETag → 304) |
| POST | `/api/v1/chat` | Отправка: `name`, `content`, `token`, `website` (honeypot) |
| GET | `/api/v1/chat/history?before={id}` | Подгрузка истории вверх |
| GET | `/api/v1/stats` | Сводка |
| GET | `/api/v1/health` | Состояние для мониторинга |

Совместимость с v1: `/messages.json`, `/getfile.php` и `POST /` продолжают
работать — у части посетителей вкладка открыта неделями.

## Модерация

`/admin?token=<ADMIN_TOKEN из .env>` — удаление сообщений, баны (в том числе
теневые), список активных, пересканирование, пересчёт весов.

## Откат

```bash
/coldstorage/faust_z/backup/randommusic-v1-20260725/rollback.sh
```

## Права

Веб работает под `faust_z-www`, CLI — под `faust_z`, оба в группе `faust_z`.
Каталоги `var/*` имеют setgid, приложение выставляет `umask(0002)` — иначе
файлы, созданные одной стороной, не сможет записать другая.
