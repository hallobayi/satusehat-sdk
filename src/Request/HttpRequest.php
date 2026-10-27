<?php
namespace agumil\SatuSehatSDK\Request;

use agumil\SatuSehatSDK\Auth\Oauth2;
use agumil\SatuSehatSDK\Config\Connection;
use agumil\SatuSehatSDK\Helper\ValidatorHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;

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
        $guzzle = new Client();
        $options = $this->initializeOptions($method, $payloads);
        try {
            $response = $guzzle->request($method, $url, $options);
        } catch (ConnectException $e) {
            $response = $e->getHandlerContext();
        } catch (ClientException $e) {
            if (
                $e->getCode() === 401 &&
                $this->oauth2->isTokenAutoRefresh() &&
                $retry &&
                json_decode($e->getResponse()->getBody()->getContents())->issue->code === 'invalid-access-token'
            ) {
                $this->token = $this->oauth2->getToken(true);
                return $this->request($method, $url, $payloads, false);
            }

            $response = $e->getResponse();
        }

        return $response;
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

    public function delete(string $url, $payloads = null)
    {
        return $this->request('DELETE', $url, $payloads);
    }
}
