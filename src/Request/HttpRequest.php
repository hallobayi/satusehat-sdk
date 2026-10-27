<?php
namespace agumil\SatuSehatSDK\Request;

use agumil\SatuSehatSDK\Auth\Oauth2;
use agumil\SatuSehatSDK\Config\Connection;
use agumil\SatuSehatSDK\Helper\ValidatorHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

class HttpRequest
{
    private $token;
    private $oauth2;

    protected int $timeout = 60;

    public function __construct(Oauth2 $oauth2, Connection | array $config = [])
    {
        if (!($config instanceof Connection)) {
            if (isset($config['timeout'])) {
                ValidatorHelper::unsignedInt('timeout', $config['timeout']);

                $this->timeout = (int) $config['timeout'];
            }
        } else {
            $this->timeout = $config->getTimeout();
        }

        $this->oauth2 = $oauth2;
        $this->token = $oauth2->getToken();
    }

    private function request(string $method, string $url, $payloads = null, bool $retry = true)
    {
        $guzzle = $this->createClient();
        $options = $this->initializeOptions($method, $payloads);
        try {
            $response = $guzzle->request($method, $url, $options);
        } catch (ConnectException $e) {
            $response = $e->getHandlerContext();
        } catch (RequestException $e) {
            // RequestException menaungi ClientException (4xx), ServerException
            // (5xx), dan kegagalan transfer lain. Kontrak SDK adalah "selalu
            // mengembalikan Response", jadi semuanya harus ditangkap di sini —
            // bukan dibiarkan bocor ke aplikasi pemanggil.
            $error_response = $e->getResponse();

            if ($error_response === null) {
                $response = $e->getHandlerContext();
            } elseif (
                $error_response->getStatusCode() === 401 &&
                $this->oauth2->isTokenAutoRefresh() &&
                $retry &&
                in_array('invalid-access-token', $this->readIssueCodes($error_response), true)
            ) {
                $this->token = $this->oauth2->getToken(true);
                return $this->request($method, $url, $payloads, false);
            } else {
                $response = $error_response;
            }
        }

        return $response;
    }

    /**
     * Seam untuk pengujian: subclass dapat mengganti client dengan handler tiruan
     * tanpa menyentuh jaringan, dan nanti bisa dipakai untuk connection reuse.
     */
    protected function createClient(): Client
    {
        return new Client();
    }

    /**
     * Mengambil seluruh kode issue dari body error tanpa menghabiskan stream.
     *
     * Body dibaca sekali lalu stream di-rewind, karena `Response` membaca body
     * lewat getContents() saat konstruksi. Tanpa rewind, pesan error asli dari
     * SATUSEHAT hilang dan `getErrorMessages()` mengembalikan array kosong.
     *
     * `issue` pada OperationOutcome adalah array (0..*), tetapi ada server yang
     * mengirim objek tunggal — keduanya diterima. Bentuk array inilah yang
     * membuat pemeriksaan lama `->issue->code` selalu null sehingga auto-refresh
     * token tidak pernah terpicu.
     */
    private function readIssueCodes(ResponseInterface $response): array
    {
        $stream = $response->getBody();
        $seekable = $stream->isSeekable();

        if ($seekable) {
            $stream->rewind();
        }

        $body = $stream->getContents();

        if ($seekable) {
            $stream->rewind();
        }

        // Body error bisa saja bukan JSON (mis. halaman HTML dari gateway),
        // jadi hasil decode tidak boleh diakses tanpa pemeriksaan.
        $decoded = json_decode($body, true);

        if (!is_array($decoded) || !isset($decoded['issue'])) {
            return [];
        }

        $issues = $decoded['issue'];

        if (isset($issues['code'])) {
            return [(string) $issues['code']];
        }

        if (!is_array($issues)) {
            return [];
        }

        $codes = [];

        foreach ($issues as $issue) {
            if (is_array($issue) && isset($issue['code'])) {
                $codes[] = (string) $issue['code'];
            }
        }

        return $codes;
    }

    private function initializeOptions($method, $payloads)
    {
        $options['headers'] = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->token,
        ];
        $options['timeout'] = $this->timeout;

        if ($payloads === null || $payloads === [] || $payloads === '') {
            return $options;
        }

        // Tipe payload dideteksi eksplisit. Sebelumnya `$payloads->build()`
        // dibungkus `catch (\Throwable)` sebagai cara membedakan builder dari
        // array; akibatnya exception validasi dari builder ikut tertelan dan
        // SDK tetap mengirim body `{}` (objek ber-properti private di-encode
        // jadi objek kosong). Sekarang exception builder dibiarkan naik supaya
        // gagal cepat di sisi pemanggil, bukan jadi 400 yang membingungkan.
        if (is_array($payloads)) {
            // GET tidak punya body; array pada GET adalah query string.
            if ($method === 'GET') {
                $options['query'] = $payloads;
            } else {
                $options['json'] = $payloads;
            }

            return $options;
        }

        if (is_object($payloads) && method_exists($payloads, 'build')) {
            $options['json'] = $payloads->build();

            return $options;
        }

        if (is_string($payloads)) {
            $options['body'] = $payloads;
        } else {
            $options['body'] = json_encode($payloads);
        }

        return $options;
    }

    public function get(string $url, array $payloads = [])
    {
        return $this->request('GET', $url, $payloads);
    }

    public function post(string $url, $payloads = null)
    {
        return $this->request('POST', $url, $payloads);
    }

    public function put(string $url, $payloads = null)
    {
        return $this->request('PUT', $url, $payloads);
    }

    /**
     * PATCH dipakai SATUSEHAT untuk update parsial (JSON Patch, RFC 6902).
     * `Patient` hanya mendukung PATCH, tidak ada PUT.
     */
    public function patch(string $url, $payloads = null)
    {
        return $this->request('PATCH', $url, $payloads);
    }

    public function delete(string $url, $payloads = null)
    {
        return $this->request('DELETE', $url, $payloads);
    }
}
