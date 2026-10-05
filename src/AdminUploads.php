<?php
declare(strict_types=1);

namespace App;

use App\Http\Request;
use App\Http\Response;
use App\Http\View;
use App\Upload\Repository as UploadRepo;
use App\Upload\Service as UploadService;

/**
 * Модерация пользовательских загрузок — отдельная страница рядом с
 * основной модераторской (/admin), тот же токен и cookie (см.
 * Admin::requireAuth()), чтобы не заводить вторую систему доступа.
 */
final class AdminUploads
{
    public static function handle(Request $req): Response
    {
        $guard = Admin::requireAuth($req);
        if ($guard !== null) {
            return $guard;
        }

        if (preg_match('~^/admin/uploads/stream/(\d+)$~', $req->path, $m)) {
            return self::stream($req, (int) $m[1]);
        }

        if ($req->method === 'POST') {
            return self::action($req);
        }

        $repo = new UploadRepo();

        return Response::html(View::render('admin/uploads', [
            'base'    => $req->base,
            'pending' => $repo->listPending(),
            'decided' => $repo->listDecided(50),
        ]))->withHeader('Cache-Control', 'no-store')
           ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private static function action(Request $req): Response
    {
        $do      = $req->post('do');
        $id      = $req->int('id', 0);
        $service = new UploadService();

        switch ($do) {
            case 'approve':
                $service->approve(
                    $id,
                    $req->post('artist') !== '' ? $req->post('artist') : null,
                    $req->post('title') !== '' ? $req->post('title') : null
                );
                break;

            case 'reject':
                $service->reject($id, $req->post('reason'));
                break;
        }

        return Response::redirect($req->base . '/admin/uploads');
    }

    /** Прослушать трек на модерации — файл лежит вне docroot, отдаём сами. */
    private static function stream(Request $req, int $id): Response
    {
        $row = (new UploadRepo())->find($id);
        if ($row === null) {
            return Response::error('not_found', 404);
        }

        $path = (new UploadService())->pendingFilePath($row);
        $body = @file_get_contents($path);
        if ($body === false) {
            return Response::error('not_found', 404);
        }

        return Response::text($body, 200, ['Content-Type' => (string) $row['mime']])
            ->withHeader('Content-Disposition', 'inline; filename="preview"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
