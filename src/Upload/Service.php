<?php
declare(strict_types=1);

namespace App\Upload;

use App\Support\Config;
use RuntimeException;

/**
 * Приём, проверка и модерация пользовательских загрузок.
 *
 * Файл до одобрения лежит в var/uploads/pending/ — вне docroot и вне
 * MEDIA_DIRS, так что Track\Scanner его не видит. Формат/оригинальное
 * имя из запроса не значат ничего: решает только ffprobe, реально
 * декодирующий поток. Так переименованный .zip/.exe в .mp3 не пройдёт,
 * даже если расширение и mime выглядят правдоподобно.
 */
final class Service
{
    private const ALLOWED_EXT = ['mp3', 'ogg', 'opus', 'm4a', 'flac', 'wav'];

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return array{ok:bool,error?:string,id?:int}
     */
    public function receive(array $file, int $userId, string $username, string $artist, string $title): array
    {
        $errMap = [
            UPLOAD_ERR_INI_SIZE   => 'too_large',
            UPLOAD_ERR_FORM_SIZE  => 'too_large',
            UPLOAD_ERR_PARTIAL    => 'upload_failed',
            UPLOAD_ERR_NO_TMP_DIR => 'upload_failed',
            UPLOAD_ERR_CANT_WRITE => 'upload_failed',
            UPLOAD_ERR_EXTENSION  => 'upload_failed',
        ];
        $code = (int) $file['error'];
        if ($code !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => $errMap[$code] ?? 'upload_failed'];
        }

        $tmp  = (string) $file['tmp_name'];
        $size = (int) $file['size'];

        // move_uploaded_file() и этот сторож — чтобы в движение шёл только
        // файл, реально пришедший через HTTP-загрузку, а не произвольный путь
        if (!is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'upload_failed'];
        }

        $maxBytes = Config::int('UPLOAD_MAX_BYTES', 26214400);
        if ($size <= 0 || $size > $maxBytes) {
            return ['ok' => false, 'error' => 'too_large'];
        }

        // Расширение и mime — только быстрый отсев явного мусора.
        // Настоящая проверка ниже, через ffprobe.
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            return ['ok' => false, 'error' => 'bad_format'];
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!$this->looksLikeAudioMime($mime)) {
            return ['ok' => false, 'error' => 'bad_format'];
        }

        $probe = $this->probeAudio($tmp);
        if ($probe === null) {
            return ['ok' => false, 'error' => 'bad_format'];
        }

        $maxDuration = Config::int('UPLOAD_MAX_DURATION_SECONDS', 7200);
        if ($probe['duration'] > $maxDuration) {
            return ['ok' => false, 'error' => 'too_long'];
        }

        // Имя на диске — полностью машинное, оригинальное храним только
        // для отображения (экранируется при выводе, как любой чужой текст)
        $stored = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest   = $this->pendingDir() . '/' . $stored;

        if (!move_uploaded_file($tmp, $dest)) {
            return ['ok' => false, 'error' => 'upload_failed'];
        }
        @chmod($dest, 0664);

        $id = (new Repository())->insert([
            'user_id'       => $userId,
            'username'      => $username,
            'original_name' => mb_substr((string) $file['name'], 0, 255),
            'stored_name'   => $stored,
            'mime'          => $mime,
            'size'          => $size,
            'duration'      => $probe['duration'],
            'artist'        => trim($artist) !== '' ? mb_substr(trim($artist), 0, 190) : null,
            'title'         => trim($title) !== '' ? mb_substr(trim($title), 0, 190) : null,
        ]);

        return ['ok' => true, 'id' => $id];
    }

    /** Абсолютный путь к файлу на модерации — для стрима админу. */
    public function pendingFilePath(array $row): string
    {
        return $this->pendingDir() . '/' . $row['stored_name'];
    }

    /**
     * Одобрение: копия в docroot/upload/ (тот же каталог, что уже сканируется),
     * по возможности — с вшитыми тегами, и немедленное пересканирование.
     *
     * @return array{ok:bool,error?:string}
     */
    public function approve(int $id, ?string $artist, ?string $title): array
    {
        $repo = new Repository();
        $row  = $repo->find($id);
        if ($row === null || $row['status'] !== 'pending') {
            return ['ok' => false, 'error' => 'not_pending'];
        }

        $src = $this->pendingFilePath($row);
        if (!is_file($src)) {
            return ['ok' => false, 'error' => 'file_missing'];
        }

        $dst = rtrim(Config::docroot(), '/') . '/upload/' . $row['stored_name'];

        $artist = $artist !== null && trim($artist) !== '' ? trim($artist) : (string) ($row['artist'] ?? '');
        $title  = $title  !== null && trim($title)  !== '' ? trim($title)  : (string) ($row['title'] ?? '');

        $tagged = ($artist !== '' || $title !== '') ? $this->remux($src, $artist, $title) : null;
        $copyFrom = $tagged ?? $src;

        $ok = @copy($copyFrom, $dst) && (int) @filesize($dst) > 0;

        if ($tagged !== null) {
            @unlink($tagged);
        }

        if (!$ok) {
            @unlink($dst);
            return ['ok' => false, 'error' => 'copy_failed'];
        }

        @chmod($dst, 0664);
        @unlink($src);
        $repo->markApproved($id);

        // Пересканировать сейчас же, не ждать таймер — тот же приём, что
        // Admin::action() использует для ручного 'rescan'.
        $bin = escapeshellarg(APP_ROOT . '/bin/scan');
        @exec("nohup php $bin --quiet > /dev/null 2>&1 &");

        return ['ok' => true];
    }

    /** @return array{ok:bool,error?:string} */
    public function reject(int $id, string $note = ''): array
    {
        $repo = new Repository();
        $row  = $repo->find($id);
        if ($row === null || $row['status'] !== 'pending') {
            return ['ok' => false, 'error' => 'not_pending'];
        }

        @unlink($this->pendingFilePath($row));
        $repo->markRejected($id, $note);

        return ['ok' => true];
    }

    public function pendingDir(): string
    {
        $dir = Config::varDir() . '/uploads/pending';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Не удалось создать каталог загрузок: $dir");
        }

        return $dir;
    }

    private function looksLikeAudioMime(string $mime): bool
    {
        if (str_starts_with($mime, 'audio/')) {
            return true;
        }

        // m4a нередко определяется как контейнер mp4/ogg, а не audio/*;
        // octet-stream — частый откат finfo на неполной базе magic.
        // Это не финальное решение, просто пропуск к ffprobe.
        return in_array($mime, ['video/mp4', 'application/ogg', 'application/octet-stream'], true);
    }

    /**
     * ffprobe на недоверенном файле: с таймаутом (в отличие от
     * Track\Scanner::probe(), которому таймаут не нужен — тот работает
     * только с уже лежащими в медиатеке файлами).
     *
     * @return array{duration:float}|null
     */
    private function probeAudio(string $path): ?array
    {
        $ffprobe = (string) Config::get('FFPROBE', '/usr/bin/ffprobe');
        $cmd = sprintf(
            'timeout 10 %s -v quiet -print_format json -show_format -show_streams -i %s 2>/dev/null',
            escapeshellcmd($ffprobe),
            escapeshellarg($path)
        );
        $json = @shell_exec($cmd);
        if (!is_string($json) || trim($json) === '') {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['format'])) {
            return null;
        }

        $hasAudio = false;
        foreach (($data['streams'] ?? []) as $stream) {
            if (($stream['codec_type'] ?? '') === 'audio') {
                $hasAudio = true;
                break;
            }
        }
        if (!$hasAudio) {
            return null;
        }

        $duration = isset($data['format']['duration']) ? (float) $data['format']['duration'] : 0.0;
        if ($duration <= 0.0) {
            return null;
        }

        return ['duration' => $duration];
    }

    /** Remux с вшитыми тегами, без перекодирования. null — не вышло, берём как есть. */
    private function remux(string $src, string $artist, string $title): ?string
    {
        $ffmpeg = (string) Config::get('FFMPEG', '/usr/bin/ffmpeg');
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        $out = $src . '.tagged.' . $ext;
        @unlink($out);

        $cmd = sprintf(
            'timeout 20 %s -y -v error -i %s -map 0 -c copy -metadata %s -metadata %s %s 2>&1',
            escapeshellcmd($ffmpeg),
            escapeshellarg($src),
            escapeshellarg('artist=' . $artist),
            escapeshellarg('title=' . $title),
            escapeshellarg($out)
        );
        @exec($cmd, $outputLines, $status);

        if ($status !== 0 || !is_file($out) || (int) @filesize($out) <= 0) {
            @unlink($out);
            return null;
        }

        return $out;
    }
}
