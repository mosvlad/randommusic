<?php
declare(strict_types=1);

namespace App;

use App\Chat\Moderation;
use App\Chat\Repository as ChatRepo;
use App\Http\Request;
use App\Http\Response;
use App\Http\View;
use App\Track\Index as TrackIndex;
use App\Track\Stats;

/**
 * Модераторская. Доступ по токену из .env: либо заголовком, либо
 * параметром ?token= (тогда он же кладётся в cookie на сутки).
 */
final class Admin
{
    private const COOKIE = 'rm_admin';

    public static function handle(Request $req): Response
    {
        $token = self::token($req);

        if (!Moderation::checkToken($token)) {
            return Response::html(View::render('admin/login', ['base' => $req->base]), 401)
                ->withHeader('Cache-Control', 'no-store');
        }

        // Токен пришёл в URL — переложим в cookie, чтобы не светился в логах
        if (($req->get('token') ?? '') !== '') {
            self::setCookie($token);
            return Response::redirect($req->base . '/admin');
        }

        $mod = new Moderation();

        if ($req->method === 'POST') {
            return self::action($req, $mod);
        }

        if ($req->path === '/admin/logout') {
            self::clearCookie();
            return Response::redirect($req->base . '/');
        }

        $chat  = new ChatRepo();
        $index = new TrackIndex();

        return Response::html(View::render('admin/index', [
            'base'     => $req->base,
            'messages' => $mod->recent(120),
            'bans'     => $mod->bans(),
            'top'      => $mod->topPosters(3600, 15),
            'library'  => $index->stats(),
            'chat'     => ['messages' => $chat->total(), 'online' => $chat->online()],
            'playback' => (new Stats())->summary(30),
        ]))->withHeader('Cache-Control', 'no-store')
           ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private static function action(Request $req, Moderation $mod): Response
    {
        $do = $req->post('do');
        $id = $req->int('id', 0);

        switch ($do) {
            case 'delete':
                $mod->deleteMessage($id);
                break;

            case 'restore':
                $mod->restoreMessage($id);
                break;

            case 'ban':
            case 'shadow':
                $seconds = max(0, $req->int('seconds', 3600));
                $client  = $mod->banAuthorOf($id, $seconds, $do === 'shadow', $req->post('reason'));
                if ($client !== null && $req->post('purge') === '1') {
                    $mod->purgeClient($client);
                }
                break;

            case 'unban':
                $mod->unban($req->post('client'));
                break;

            case 'rescan':
                // Сканирование долгое — запускаем отдельным процессом
                $bin = escapeshellarg(APP_ROOT . '/bin/scan');
                @exec("nohup php $bin --quiet > /dev/null 2>&1 &");
                break;

            case 'weights':
                (new Stats())->recomputeWeights();
                \App\Support\Cache::flush();
                break;
        }

        return Response::redirect($req->base . '/admin');
    }

    private static function token(Request $req): string
    {
        $fromQuery = (string) ($req->get('token') ?? '');
        if ($fromQuery !== '') {
            return $fromQuery;
        }

        $header = $req->header('X-Admin-Token');
        if ($header !== '') {
            return $header;
        }

        return (string) ($_COOKIE[self::COOKIE] ?? '');
    }

    private static function setCookie(string $token): void
    {
        setcookie(self::COOKIE, $token, [
            'expires'  => time() + 86400,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private static function clearCookie(): void
    {
        setcookie(self::COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}
