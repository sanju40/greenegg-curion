<?php

namespace App\Api\UpPromote;

use App\Utils\LogHelper;
use App\Exceptions\ApiException;

/**
 * UpPromote API Client
 * Handles HTTP communication with the UpPromote Public API v2
 */
class Client
{
    private const BASE_URL = 'https://aff-api.uppromote.com/api/v2';

    private $apiKey;
    private $config;

    public function __construct()
    {
        $this->config = \App\Core\Config::get();
        $this->apiKey = $this->config['uppromote']['api_key'] ?? '';
    }

    /**
     * Make GET request
     */
    public function get(string $endpoint, array $params = []): ?array
    {
        return $this->request('GET', $endpoint, null, $params);
    }

    /**
     * Verify an incoming UpPromote webhook signature
     * UpPromote sends HMAC-SHA256 of the raw body in X-UpPromote-Signature
     */
    public static function verifyWebhookSignature(string $rawPayload, string $signature, string $secret): bool
    {
        $calculated = hash_hmac('sha256', $rawPayload, $secret);
        return hash_equals($calculated, $signature);
    }

    /**
     * Make HTTP request
     */
    private function request(string $method, string $endpoint, ?array $data, array $params): ?array
    {
        $url = self::BASE_URL . '/' . ltrim($endpoint, '/');

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $this->apiKey,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ]);

        if ($method === 'POST' && $data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            LogHelper::error('UpPromote API CURL error', [
                'endpoint'   => $endpoint,
                'curl_error' => $curlError,
            ]);
            throw new ApiException("UpPromote CURL error: {$curlError}", 0);
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            LogHelper::error('UpPromote API HTTP error', [
                'endpoint'  => $endpoint,
                'http_code' => $httpCode,
                'response'  => $decoded,
            ]);
            throw new ApiException(
                "UpPromote HTTP {$httpCode}: " . ($decoded['message'] ?? 'Unknown error'),
                $httpCode
            );
        }

        return $decoded;
    }
}
