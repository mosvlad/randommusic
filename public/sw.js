/**
 * Service worker.
 *
 * Кэшируется только оболочка: разметка, стили, скрипты, шрифты, фоны.
 * Музыка НЕ кэшируется намеренно — 27 ГБ медиатеки в хранилище браузера
 * не нужны никому, а Range-запросы через Cache API работают плохо.
 */

const VERSION = 'rm-v2.0.1';
const SHELL = `${VERSION}-shell`;

// Только то, что запрашивается без ?v=. CSS и JS сюда класть нельзя:
// страница просит их с версией в адресе, и предзагруженная копия без
// версии всё равно не совпадёт.
const SHELL_FILES = [
  '/',
  '/assets/css/fonts.css',
  '/assets/img/bg_night.jpg',
  '/assets/img/icon-192.png',
  '/assets/fonts/yanone-kaffeesatz-cyrillic.woff2',
  '/assets/fonts/yanone-kaffeesatz-latin.woff2',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL).then((cache) => cache.addAll(SHELL_FILES)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;

  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  // Аудио и диапазонные запросы отдаём напрямую сети
  if (request.headers.has('range') || /\.(mp3|ogg|opus|m4a|flac|wav)$/i.test(url.pathname)) return;

  // API всегда свежий: чат и случайный трек кэшировать бессмысленно
  if (url.pathname.startsWith('/api/')) return;

  // Разметка — сеть, с откатом на кэш, если связи нет
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((res) => {
          const copy = res.clone();
          caches.open(SHELL).then((c) => c.put('/', copy)).catch(() => {});
          return res;
        })
        .catch(() => caches.match('/', { ignoreSearch: true }).then((r) => r || Response.error()))
    );
    return;
  }

  // Ассеты версионированы (?v=hash), поэтому кэш можно отдавать сразу.
  //
  // Сравнение строго по полному адресу, вместе со строкой запроса.
  // С ignoreSearch кэш отвечал старым файлом на новый ?v=, то есть
  // ровно отменял версионирование: страница приезжала свежая, а стили
  // к ней — прошлые. Один раз это уже сломало вёрстку на проде.
  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) return cached;

      return fetch(request)
        .then((res) => {
          if (res.ok) {
            const copy = res.clone();
            caches.open(SHELL).then((c) => c.put(request, copy)).catch(() => {});
          }
          return res;
        })
        .catch(() => caches.match(request, { ignoreSearch: true }));
    })
  );
});
