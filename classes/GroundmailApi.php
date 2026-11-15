<?php

/**
 * Groundmail for PrestaShop
 *
 * @author    Groundmail
 * @copyright Groundmail
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The two requests the module makes, both to api.groundmail.dev: sending an
 * email, and checking an API key when one is saved.
 */
class GroundmailApi
{
    public const BASE_URL = 'https://api.groundmail.dev';

    /**
     * @var callable|null Replaces the HTTP call in tests:
     *                    function (string $method, string $url, array $headers, ?string $body): array
     *                    returning ['status' => int, 'body' => string, 'error' => string]
     */
    private $transport;

    /**
     * @var string
     */
    private $userAgent;

    /**
     * @param string $userAgent
     * @param callable|null $transport
     */
    public function __construct($userAgent, $transport = null)
    {
        $this->userAgent = (string) $userAgent;
        $this->transport = $transport;
    }

    /**
     * Send one email.
     *
     * @param array $body request body for POST /emails
     * @param string $apiKey
     *
     * @return array{sent: bool, id: string, held_back: array, error: string, status: int}
     */
    public function send(array $body, $apiKey)
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return self::failure('The email could not be encoded for sending: ' . json_last_error_msg(), 0);
        }

        $response = $this->request('POST', '/emails', $apiKey, $json, 30);
        if ($response['error'] !== '') {
            return self::failure('Could not reach Groundmail: ' . $response['error'], 0);
        }

        $code = (int) $response['status'];
        $data = json_decode((string) $response['body'], true);
        if (!is_array($data)) {
            $data = [];
        }

        if ($code >= 200 && $code < 300 && !empty($data['id']) && is_string($data['id'])) {
            return [
                'sent' => true,
                'id' => $data['id'],
                'held_back' => isset($data['held_back']) && is_array($data['held_back']) ? $data['held_back'] : [],
                'error' => '',
                'status' => $code,
            ];
        }

        return self::failure(self::errorMessage($code, $data), $code);
    }

    /**
     * Check an API key by listing one email. Only a 401 means the key is
     * wrong: a send-only key gets a 403 there and still sends, and a network
     * error says nothing about the key.
     *
     * @param string $apiKey
     *
     * @return string 'valid', 'invalid' or 'unknown'
     */
    public function checkKey($apiKey)
    {
        $response = $this->request('GET', '/emails?limit=1', $apiKey, null, 15);
        if ($response['error'] !== '') {
            return 'unknown';
        }

        $code = (int) $response['status'];
        if ($code === 401) {
            return 'invalid';
        }
        if (($code >= 200 && $code < 300) || $code === 403) {
            return 'valid';
        }

        return 'unknown';
    }

    /**
     * A readable error from an API error response.
     *
     * @param int $code HTTP status
     * @param array $data decoded response body
     *
     * @return string
     */
    public static function errorMessage($code, array $data)
    {
        $message = '';
        if (isset($data['error']['message']) && is_string($data['error']['message'])) {
            $message = $data['error']['message'];
        } elseif (isset($data['error']) && is_string($data['error'])) {
            $message = $data['error'];
        } elseif (isset($data['message']) && is_string($data['message'])) {
            $message = $data['message'];
        }

        if ($message === '') {
            return sprintf('Groundmail answered with HTTP %d.', (int) $code);
        }
        if (isset($data['error']['code']) && is_string($data['error']['code'])) {
            $message .= ' (' . $data['error']['code'] . ')';
        }

        return $message;
    }

    /**
     * @param string $method
     * @param string $path
     * @param string $apiKey
     * @param string|null $body
     * @param int $timeout
     *
     * @return array{status: int, body: string, error: string}
     */
    private function request($method, $path, $apiKey, $body, $timeout)
    {
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
            'User-Agent: ' . $this->userAgent,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        if ($this->transport !== null) {
            $result = call_user_func($this->transport, $method, self::BASE_URL . $path, $headers, $body);

            return [
                'status' => isset($result['status']) ? (int) $result['status'] : 0,
                'body' => isset($result['body']) ? (string) $result['body'] : '',
                'error' => isset($result['error']) ? (string) $result['error'] : '',
            ];
        }

        if (!extension_loaded('curl')) {
            return ['status' => 0, 'body' => '', 'error' => 'the PHP curl extension is not available'];
        }

        $curl = curl_init(self::BASE_URL . $path);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($curl);
        $error = $responseBody === false ? curl_error($curl) : '';
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) {
            // A no-op from PHP 8.0, deprecated from 8.5.
            curl_close($curl);
        }

        return [
            'status' => $status,
            'body' => is_string($responseBody) ? $responseBody : '',
            'error' => $error,
        ];
    }

    /**
     * @param string $error
     * @param int $status
     *
     * @return array{sent: bool, id: string, held_back: array, error: string, status: int}
     */
    private static function failure($error, $status)
    {
        return [
            'sent' => false,
            'id' => '',
            'held_back' => [],
            'error' => $error,
            'status' => (int) $status,
        ];
    }
}
