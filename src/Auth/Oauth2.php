<?php
namespace agumil\SatuSehatSDK\Auth;

use agumil\SatuSehatSDK\Config\Endpoint;
use agumil\SatuSehatSDK\Exception\SSEnvException;
use agumil\SatuSehatSDK\Exception\SSOauth2Exception;
use agumil\SatuSehatSDK\Helper\EnvHelper;
use agumil\SatuSehatSDK\Helper\StrHelper;
use agumil\SatuSehatSDK\Response\Response;
use DateInterval;
use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class Oauth2
{
    private $base_url;

    private $organization_id;

    private $client_id;

    private $client_secret;

    private $token = null;

    private $token_expired_at = 0;

    // Opt-in
    private $auto_refresh_token = false;

    private TokenStoreInterface $token_store;

    private $event_listener = [
        'token_changed' => []
    ];

    public function __construct(array $config = [])
    {
        if (EnvHelper::isStaging() || $config['environment'] === 'staging') {
            $this->base_url = Endpoint::STG_OAUTH2;
        } elseif (EnvHelper::isProduction() || $config['environment'] === 'production') {
            $this->base_url = Endpoint::PROD_OAUTH2;
        } else {
            $env = EnvHelper::ENV;
            throw new SSEnvException("Oauth2 - Environment '{$env}' OR Parameter 'environment' must be provided. Valid environment value is must be 'staging' or 'production'.");
        }

        if (isset($config['organization_id'])) {
            $this->organization_id = $config['organization_id'];
        } else {
            $this->organization_id = EnvHelper::getOrganizationId();
        }

        // if (empty($this->organization_id)) {
        //     $env_organizationid = EnvHelper::ORGANIZATION_ID;
        //     throw new SSEnvException("Oauth2 - Environment '{$env_organizationid}' OR Configuration 'organization_id' must be provided.");
        // }

        if (isset($config['client_id'])) {
            $this->client_id = $config['client_id'];
        } else {
            $this->client_id = EnvHelper::getClientId();
        }

        if (empty($this->client_id)) {
            $env_clientid = EnvHelper::CLIENT_ID;
            throw new SSEnvException("Oauth2 - Environment '{$env_clientid}' OR Configuration 'client_id' must be provided.");
        }

        if (isset($config['client_secret'])) {
            $this->client_secret = $config['client_secret'];
        } else {
            $this->client_secret = EnvHelper::getClientSecret();
        }

        if (empty($this->client_secret)) {
            $env_clientsecret = EnvHelper::CLIENT_SECRET;
            throw new SSEnvException("Oauth2 - Environment '{$env_clientsecret}' OR Configuration 'client_secret' must be provided.");
        }

        if (isset($config['token'])) {
            $this->token = $config['token'];
        }

        if (isset($config['token_expired_at'])) {
            $this->token_expired_at = $config['token_expired_at'];
        }

        if (isset($config['auto_refresh_token'])) {
            $this->auto_refresh_token = $config['auto_refresh_token'];
        }

        // Default tetap per-instance (perilaku lama). Isi dengan FileTokenStore
        // bila token perlu dipakai bersama antar proses.
        $token_store = $config['token_store'] ?? new InMemoryTokenStore();

        if (!($token_store instanceof TokenStoreInterface)) {
            throw new SSEnvException('Oauth2 - Configuration \'token_store\' must implement ' . TokenStoreInterface::class . '.');
        }

        $this->token_store = $token_store;

        if ($this->token !== null && $this->token_expired_at > 0) {
            $this->token_store->set($this->token, (int) $this->token_expired_at);
        }
    }

    public function getContent()
    {
        return $this->generateToken()->getContent();
    }

    public function getToken(bool $refreshToken = false)
    {
        if ($refreshToken) {
            $this->adoptToken(null, 0);
            $this->token_store->delete();
        }

        $now = (new DateTime('now'))->getTimestamp();

        if ($this->token !== null && $now < $this->token_expired_at) {
            return $this->token;
        }

        // Token mungkin sudah diperbarui proses lain; store lintas proses
        // diperiksa lebih dulu supaya tidak ada request token yang sia-sia.
        $cached = $this->token_store->get();

        if ($cached !== null && $now < $cached['expires_at']) {
            $this->adoptToken($cached['token'], $cached['expires_at']);

            return $this->token;
        }

        // refresh() mengunci store selama request token berjalan, sehingga
        // beberapa proses yang bersamaan tetap hanya menghasilkan satu request.
        $fresh = $this->token_store->refresh(function () {
            return $this->requestNewToken();
        });

        $this->adoptToken($fresh['token'], $fresh['expires_at']);

        return $this->token;
    }

    /**
     * Meminta access token baru ke SATUSEHAT.
     *
     * @return array{token: string, expires_at: int}
     */
    protected function requestNewToken(): array
    {
        $response = $this->generateToken();

        if ($response->getHttpStatus() !== $response::STATUS_2XX) {
            $this->adoptToken(null, 0);
            $this->token_store->delete();

            throw new SSOauth2Exception($response->getContent());
        }

        $body = json_decode($response->getContent());

        // 2xx tanpa access_token/expires_in (mis. halaman HTML dari proxy)
        // harus gagal di sini, bukan menjadi token null di tengah request.
        if (!is_object($body) || !isset($body->access_token) || !isset($body->expires_in)) {
            $this->adoptToken(null, 0);
            $this->token_store->delete();

            throw new SSOauth2Exception('Oauth2 - Token response is not recognized: ' . $response->getContent());
        }

        // Dikurangi 60 detik agar token tidak kedaluwarsa di tengah request,
        // dan tidak pernah negatif bila server memberi expires_in < 60.
        $lifetime = max(0, (int) $body->expires_in - 60);
        $expiredAt = (new DateTime('now'))->add(new DateInterval("PT{$lifetime}S"))->getTimestamp();

        return [
            'token' => (string) $body->access_token,
            'expires_at' => $expiredAt,
        ];
    }

    /**
     * Menetapkan token sekaligus memicu `token_changed` hanya bila nilainya
     * benar-benar berubah.
     */
    private function adoptToken(?string $token, int $expiredAt): void
    {
        if ($this->token === $token && $this->token_expired_at === $expiredAt) {
            return;
        }

        $this->token = $token;
        $this->token_expired_at = $expiredAt;
        $this->fireTokenChanged();
    }

    private function fireTokenChanged()
    {
        foreach ($this->event_listener['token_changed'] as $callback) {
            $callback($this->token, $this->token_expired_at);
        }
    }

    private function generateToken()
    {
        $url = StrHelper::appendSlash($this->base_url) . 'accesstoken';
        try {
            $guzzle = new Client();
            $options['query']['grant_type'] = 'client_credentials';
            $options['form_params']['client_id'] = $this->client_id;
            $options['form_params']['client_secret'] = $this->client_secret;
            $response = $guzzle->post($url, $options);
        } catch (ClientException $e) {
            $response = $e->getResponse();
        }

        return new Response($response);
    }

    public function getOrganizationId()
    {
        return $this->organization_id;
    }

    public function isTokenAutoRefresh()
    {
        return $this->auto_refresh_token;
    }

    public function addEventListener(string $type, callable $callback) {
        $this->event_listener[$type][] = $callback;
        return $this;
    }
}
