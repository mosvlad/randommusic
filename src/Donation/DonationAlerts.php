<?php
declare(strict_types=1);

namespace App\Donation;

use App\Support\Config;
use RuntimeException;

/**
 * Клиент DonationAlerts API.
 *
 * Донаты приходят на публичную страницу автора
 * (https://www.donationalerts.com/r/faust_z). Забрать их список можно
 * официальным REST: GET /api/v1/alerts/donations с Bearer-токеном и
 * правом oauth-donation-index.
 *
 * Авторизация — OAuth2 Authorization Code, один раз через bin/donations-auth.
 * Access-токен DonationAlerts живёт около 20 лет, так что обновление по
 * refresh_token — редкий путь. Полученные токены складываются в
 * donations.sqlite (meta); значения в .env — начальные/резервные.
 */
final class DonationAlerts
{
    private const API    = 'https://www.donationalerts.com/api/v1';
    private const OAUTH   = 'https://www.donationalerts.com/oauth/token';
    public const AUTHORIZE = 'https://www.donationalerts.com/oauth/authorize';

    /** Профиль нужен только чтобы авторизация не падала на пустом scope. */
    public const SCOPES = 'oauth-user-show oauth-donation-index';

    public function __construct(private Repository $repo)
    {
    }

    /** Действующий access-токен: свежий из базы либо начальный из .env. */
    public function accessToken(): string
    {
        $token = $this->repo->metaGet('access_token')
              ?? (string) Config::get('DONATIONALERTS_ACCESS_TOKEN', '');

        if ($token === '') {
            throw new RuntimeException(
                'DonationAlerts: нет access-токена. Выполните bin/donations-auth.'
            );
        }

        return $token;
    }

    /**
     * Последние донаты — первая страница ответа (до 30 штук).
     * При 401 один раз пробуем обновить токен и повторяем запрос.
     *
     * @return array<int,array<string,mixed>>
     */
    public function recentDonations(): array
    {
        [$status, $body] = $this->get('/alerts/donations');

        if ($status === 401) {
            $this->refresh();
            [$status, $body] = $this->get('/alerts/donations');
        }

        if ($status !== 200) {
            throw new RuntimeException(
                "DonationAlerts /alerts/donations вернул $status: " . substr($body, 0, 200)
            );
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
            throw new RuntimeException('DonationAlerts: неожиданный формат ответа');
        }

        return $data['data'];
    }

    /**
     * Обновить пару токенов по refresh_token и сохранить в базу.
     * .env при этом не трогаем — начальное значение в нём остаётся.
     */
    public function refresh(): void
    {
        $refreshToken = $this->repo->metaGet('refresh_token')
                     ?? (string) Config::get('DONATIONALERTS_REFRESH_TOKEN', '');

        if ($refreshToken === '') {
            throw new RuntimeException('DonationAlerts: нет refresh-токена для обновления');
        }

        [$id, $secret] = $this->clientCredentials();

        [$status, $body] = $this->form(self::OAUTH, [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $id,
            'client_secret' => $secret,
            'scope'         => self::SCOPES,
        ]);

        if ($status !== 200) {
            throw new RuntimeException("DonationAlerts refresh вернул $status: " . substr($body, 0, 200));
        }

        $this->storeTokens($body);
    }

    /**
     * Обмен authorization code на токены — вызывается из bin/donations-auth.
     *
     * @return array{access_token:string,refresh_token:string}
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        [$id, $secret] = $this->clientCredentials();

        [$status, $body] = $this->form(self::OAUTH, [
            'grant_type'    => 'authorization_code',
            'client_id'     => $id,
            'client_secret' => $secret,
            'redirect_uri'  => $redirectUri,
            'code'          => $code,
        ]);

        if ($status !== 200) {
            throw new RuntimeException("Обмен кода вернул $status: " . substr($body, 0, 300));
        }

        return $this->storeTokens($body);
    }

    /** @return array{0:string,1:string} */
    private function clientCredentials(): array
    {
        $id     = (string) Config::get('DONATIONALERTS_CLIENT_ID', '');
        $secret = (string) Config::get('DONATIONALERTS_CLIENT_SECRET', '');

        if ($id === '' || $secret === '') {
            throw new RuntimeException(
                'Заполните DONATIONALERTS_CLIENT_ID и DONATIONALERTS_CLIENT_SECRET в .env'
            );
        }

        return [$id, $secret];
    }

    /** @return array{access_token:string,refresh_token:string} */
    private function storeTokens(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new RuntimeException('DonationAlerts: в ответе нет access_token');
        }

        $access  = (string) $data['access_token'];
        $refresh = (string) ($data['refresh_token'] ?? '');

        $this->repo->metaSet('access_token', $access);
        if ($refresh !== '') {
            $this->repo->metaSet('refresh_token', $refresh);
        }
        $this->repo->metaSet('token_updated_at', (string) time());

        return ['access_token' => $access, 'refresh_token' => $refresh];
    }

    /** @return array{0:int,1:string} */
    private function get(string $path): array
    {
        return $this->request('GET', self::API . $path, null, [
            'Authorization: Bearer ' . $this->accessToken(),
        ]);
    }

    /**
     * @param array<string,string> $fields
     * @return array{0:int,1:string}
     */
    private function form(string $url, array $fields): array
    {
        return $this->request('POST', $url, http_build_query($fields), [
            'Content-Type: application/x-www-form-urlencoded',
        ]);
    }

    /**
     * @param string[] $headers
     * @return array{0:int,1:string} [http-код, тело]
     */
    private function request(string $method, string $url, ?string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => $body,
        ]);

        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("DonationAlerts: сетевая ошибка — $err");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, (string) $resp];
    }
}
