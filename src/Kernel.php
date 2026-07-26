<?php
declare(strict_types=1);

namespace App;

use App\Chat\Guard;
use App\Chat\Moderation;
use App\Chat\Repository as ChatRepo;
use App\Http\Request;
use App\Http\Response;
use App\Http\View;
use App\Support\Cache;
use App\Support\Client;
use App\Support\Config;
use App\Track\Index as TrackIndex;
use App\Track\Stats;

/**
 * Единая точка входа и маршрутизация.
 */
final class Kernel
{
    public const VERSION = '2.0.0';

    public static function handle(Request $req): Response
    {
        try {
            return self::route($req);
        } catch (\Throwable $e) {
            error_log('[randommusic] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

            if (Config::isDebug()) {
                return Response::text(
                    (string) $e . "\n",
                    500
                );
            }

            return $req->isAjax() || str_starts_with($req->path, '/api/')
                ? Response::error('internal', 500)
                : Response::html(View::render('error', ['code' => 500]), 500);
        }
    }

    private static function route(Request $req): Response
    {
        $path = $req->path;

        // --- Страницы ------------------------------------------------------
        if ($path === '/' || $path === '/index.php') {
            // v1 отправлял форму чата POST-ом на саму страницу.
            // У кого-то вкладка открыта неделями — не ломаем.
            if ($req->method === 'POST' && $req->post('content') !== '' && $req->post('name') !== '') {
                return self::legacyChatPost($req);
            }

            return self::home($req, null);
        }

        if (preg_match('~^/t/(\d+)$~', $path, $m)) {
            return self::home($req, (int) $m[1]);
        }

        // --- API -----------------------------------------------------------
        if ($path === '/api/v1/track/random') {
            return self::trackRandom($req);
        }

        if (preg_match('~^/api/v1/track/(\d+)$~', $path, $m)) {
            $track = (new TrackIndex())->find((int) $m[1]);
            return $track === null ? Response::error('not_found', 404) : Response::json($track);
        }

        if (preg_match('~^/api/v1/track/(\d+)/event$~', $path, $m)) {
            return self::trackEvent($req, (int) $m[1]);
        }

        if ($path === '/api/v1/track/search') {
            return Response::json([
                'items' => (new TrackIndex())->search((string) $req->get('q', ''), $req->int('limit', 30)),
            ]);
        }

        if ($path === '/api/v1/chat') {
            return $req->method === 'POST' ? self::chatPost($req) : self::chatGet($req);
        }

        if ($path === '/api/v1/chat/history') {
            $repo = new ChatRepo();
            return Response::json([
                'messages' => $repo->before($req->int('before', PHP_INT_MAX), Client::id(), $req->int('limit', 50)),
            ]);
        }

        if ($path === '/api/v1/stats') {
            return self::stats();
        }

        if ($path === '/api/v1/health') {
            return self::health();
        }

        // --- Служебное -------------------------------------------------------
        // Отдаём динамически, а не файлами: sitemap требует абсолютных URL,
        // а домен в статике тихо сломал бы переезд на другой адрес.
        if ($path === '/robots.txt') {
            return self::robots($req);
        }

        if ($path === '/sitemap.xml') {
            return self::sitemap($req);
        }

        // --- Совместимость с v1 --------------------------------------------
        // У части посетителей открыта старая вкладка: она опрашивает
        // /messages.json и шлёт POST на /. Пусть работает, пока не закроют.
        if ($path === '/messages.json') {
            return self::legacyMessages();
        }

        if ($path === '/getfile.php') {
            return self::legacyGetFile();
        }

        // --- Админка ---------------------------------------------------------
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            return Admin::handle($req);
        }

        return $req->isAjax() || str_starts_with($path, '/api/')
            ? Response::error('not_found', 404)
            : Response::html(View::render('error', ['code' => 404]), 404);
    }

    // ---------------------------------------------------------------------

    private static function home(Request $req, ?int $trackId): Response
    {
        $index = new TrackIndex();
        $chat  = new ChatRepo();
        $guard = new Guard();
        $client = Client::id();

        $chat->touch($client);

        // Первый трек отдаём вместе со страницей: у v1 старт проигрывания
        // ждал отдельного запроса к getfile.php плюс повторную загрузку mp3
        // ради ID3-тегов.
        $initial = $trackId !== null ? $index->find($trackId) : $index->random();

        $messages = $chat->latest($client, ChatRepo::PAGE);

        return Response::html(View::render('home', [
            'base'        => $req->base,
            'origin'      => $req->origin(),
            'initial'     => $initial,
            'isPermalink' => $trackId !== null,
            'messages'    => $messages,
            'lastId'      => $messages === [] ? 0 : (int) end($messages)['id'],
            'token'       => $guard->issueToken($client),
            'stats'       => $index->stats(),
            'online'      => $chat->online(),
            'maxLen'      => Config::int('CHAT_MAX_LEN', 256),
            'maxName'     => Config::int('CHAT_MAX_NAME', 32),
            'assetVer'    => self::assetVersion(),
        ]));
    }

    private static function trackRandom(Request $req): Response
    {
        $exclude = array_slice(array_filter(
            array_map('intval', explode(',', (string) $req->get('exclude', ''))),
            static fn(int $v): bool => $v > 0
        ), -30);

        $track = (new TrackIndex())->random($exclude);

        return $track === null
            ? Response::error('library_empty', 503)
            : Response::json($track);
    }

    private static function trackEvent(Request $req, int $trackId): Response
    {
        if ($req->method !== 'POST') {
            return Response::error('method_not_allowed', 405);
        }

        $event    = $req->post('event', 'played');
        $listened = (float) $req->post('listened', '0');

        if (!in_array($event, ['played', 'skipped'], true)) {
            return Response::error('bad_event', 400);
        }

        (new Stats())->record($trackId, $event, $listened, Client::id());

        return Response::noContent();
    }

    private static function chatGet(Request $req): Response
    {
        $repo   = new ChatRepo();
        $client = Client::id();

        $repo->touch($client);

        // ETag по версии ленты: при неизменном чате отдаём 304 и не трогаем
        // ни сериализацию, ни диск. Поллинг — 94% всех запросов к сайту.
        $etag = '"' . $repo->version() . '-' . substr($client, 0, 8) . '"';
        if ($req->header('If-None-Match') === $etag) {
            return Response::notModified($etag);
        }

        $since = $req->int('since', -1);
        $messages = $since < 0
            ? $repo->latest($client, ChatRepo::PAGE)
            : $repo->since($since, $client, ChatRepo::PAGE);

        return Response::json([
            'messages' => $messages,
            'last_id'  => $repo->lastId(),
            'online'   => $repo->online(),
        ])->withHeader('ETag', $etag);
    }

    private static function chatPost(Request $req): Response
    {
        $repo   = new ChatRepo();
        $guard  = new Guard();
        $client = Client::id();

        // Обычная отправка формы, а не fetch: значит, JS не сработал.
        // Показать посетителю голый JSON — худший из возможных исходов,
        // поэтому такой запрос обрабатываем и возвращаем на страницу.
        $wantsHtml = !$req->isAjax();

        $name    = trim($req->post('name'));
        $content = trim($req->post('content'));

        [$verdict, $details] = $guard->check(
            $client,
            $name,
            $content,
            $req->post('token'),
            trim($req->post('website'))   // honeypot
        );

        if ($verdict !== Guard::OK && $verdict !== Guard::SHADOW) {
            $status = match ($verdict) {
                Guard::ERR_RATE   => 429,
                Guard::ERR_BANNED => 403,
                default           => 400,
            };

            if ($wantsHtml) {
                return Response::redirect($req->base . '/?chat=' . rawurlencode($verdict), 303);
            }

            // Ботам и флудерам новый токен не выдаём
            return Response::error($verdict, $status, $details);
        }

        $guard->record($client);

        $trackId = $req->int('track', 0);
        $id = $repo->add(
            $name,
            $content,
            $client,
            $trackId > 0 ? $trackId : null,
            $verdict === Guard::SHADOW
        );

        if ($wantsHtml) {
            // 303, чтобы обновление страницы не переотправляло сообщение
            return Response::redirect($req->base . '/', 303);
        }

        return Response::json([
            'id'    => $id,
            'token' => $guard->issueToken($client),
        ], 201);
    }

    private static function stats(): Response
    {
        $index = new TrackIndex();
        $chat  = new ChatRepo();
        $stats = new Stats();

        return Response::json([
            'library'  => $index->stats(),
            'chat'     => ['messages' => $chat->total(), 'online' => $chat->online()],
            'playback' => $stats->summary(30),
            'version'  => self::VERSION,
        ]);
    }

    private static function health(): Response
    {
        // Первым делом конфигурация: если .env не читается, всё остальное
        // работает на умолчаниях и выглядит здоровым
        $problems = Config::audit();

        try {
            $index = new TrackIndex();
            $s = $index->stats();
            if ($s['tracks'] < 1) {
                $problems[] = 'library_empty';
            }
            if ($s['last_scan'] === null || $s['last_scan'] < time() - 86400) {
                $problems[] = 'scan_stale';
            }
        } catch (\Throwable) {
            $problems[] = 'tracks_db';
            $s = [];
        }

        try {
            (new ChatRepo())->lastId();
        } catch (\Throwable) {
            $problems[] = 'chat_db';
        }

        return Response::json([
            'ok'       => $problems === [],
            'problems' => $problems,
            'library'  => $s,
            'version'  => self::VERSION,
        ], $problems === [] ? 200 : 503);
    }

    private static function robots(Request $req): Response
    {
        $body = "User-agent: *\n"
              . "Allow: /\n"
              . "Disallow: /admin\n"
              . "Disallow: /api/\n"
              . "\n"
              . 'Sitemap: ' . $req->origin() . $req->base . "/sitemap.xml\n";

        return Response::text($body)->withHeader('Cache-Control', 'public, max-age=86400');
    }

    private static function sitemap(Request $req): Response
    {
        $home = $req->origin() . $req->base . '/';
        $date = gmdate('Y-m-d');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
             . "  <url>\n"
             . '    <loc>' . htmlspecialchars($home, ENT_XML1) . "</loc>\n"
             . "    <lastmod>$date</lastmod>\n"
             . "    <changefreq>daily</changefreq>\n"
             . "    <priority>1.0</priority>\n"
             . "  </url>\n"
             . "</urlset>\n";

        return Response::xml($xml)->withHeader('Cache-Control', 'public, max-age=86400');
    }

    /**
     * Совместимость: формат v1 — плоский массив последних 50 сообщений.
     */
    private static function legacyMessages(): Response
    {
        $repo = new ChatRepo();
        $out = [];
        foreach ($repo->latest(Client::id(), 50) as $m) {
            $out[] = [
                'id'      => $m['id'],
                'time'    => $m['time'],
                'name'    => $m['name'],
                'content' => $m['content'],
            ];
        }

        return Response::json($out)->withHeader('X-Legacy', 'v1-compat');
    }

    /**
     * Совместимость: приём формы чата от старых вкладок.
     *
     * Токена формы у них нет, так что эту проверку пропускаем, но лимиты
     * и баны применяем полностью — то есть даже так безопаснее, чем в v1,
     * где не проверялось вообще ничего, кроме длины.
     */
    private static function legacyChatPost(Request $req): Response
    {
        $repo   = new ChatRepo();
        $guard  = new Guard();
        $client = Client::id();

        $name    = trim($req->post('name'));
        $content = trim($req->post('content'));

        $maxLen  = Config::int('CHAT_MAX_LEN', 256);
        $maxName = Config::int('CHAT_MAX_NAME', 32);

        if ($name === '' || $content === '' || mb_strlen($content) > $maxLen || mb_strlen($name) > $maxName) {
            return Response::noContent();
        }

        [$verdict] = $guard->check($client, $name, $content, $guard->issueToken($client), '');

        // ERR_TOO_FAST здесь ожидаем всегда: токен выписан секунду назад
        if (!in_array($verdict, [Guard::OK, Guard::SHADOW, Guard::ERR_TOO_FAST], true)) {
            return Response::noContent();
        }

        $guard->record($client);
        $repo->add($name, $content, $client, null, $verdict === Guard::SHADOW, 'web-legacy');

        // v1 не смотрел на тело ответа
        return Response::noContent();
    }

    /**
     * Совместимость: v1 ждал от getfile.php путь к треку простым текстом.
     */
    private static function legacyGetFile(): Response
    {
        $track = (new TrackIndex())->random();

        return Response::text($track === null ? '' : rawurldecode($track['url']))
            ->withHeader('X-Legacy', 'v1-compat');
    }

    /**
     * Версия ассетов для сброса кэша: в v1 стоял max-age на месяц без
     * версионирования, то есть правка CSS доходила до посетителя через месяц.
     */
    public static function assetVersion(): string
    {
        // Считаем на каждый рендер, без кэша. Четыре stat() по горячим
        // файлам стоят микросекунды, а кэш здесь однажды уже создал
        // проблему: APCu веба и CLI — разная память, сброс после выкатки
        // не срабатывал, и с Cache-Control: immutable правка CSS просто
        // не доезжала до посетителя.
        $docroot = Config::docroot();
        $stamp = 0;
        $files = ['/assets/css/app.css', '/assets/js/app.js',
                  '/assets/js/player.js', '/assets/js/chat.js'];
        foreach ($files as $f) {
            $stamp = max($stamp, (int) @filemtime($docroot . $f));
        }

        return substr(md5(self::VERSION . '|' . $stamp), 0, 8);
    }
}
