<?php

namespace App\Api\WwsRestService;

use App\Utils\Logger;
use App\Utils\LogHelper;
use App\Exceptions\ApiException;
use CurlHandle;

/**
 * WwsRestService API Client
 * Base HTTP client for WwsRestService API
 *
 * Use the default constructor for catalog/product/customer sync (WWS_BASE_URL).
 * Pass true for order flows when WWS_ORDERS_* overrides apply: base URL, database id,
 * and optionally WWS_ORDERS_USERNAME / WWS_ORDERS_PASSWORD (else catalog credentials are reused).
 */
class Client
{
    private $baseUrl;
    private $databaseId;
    private $username;
    private $password;
    private $verifySsl;
    private $logger;
    private $config;
    private $wwsConfig;

    /**
     * @param bool $useOrdersEndpoint If true, apply non-empty WWS_ORDERS_* config (URL, DB id, user/pass)
     */
    public function __construct(bool $useOrdersEndpoint = false)
    {
        $this->config = \App\Core\Config::get();
        $wws = $this->config['wws'];

        if ($useOrdersEndpoint) {
            $ordersUrl = trim((string) ($wws['orders_base_url'] ?? ''));
            if ($ordersUrl !== '') {
                $wws['base_url'] = $ordersUrl;
            }
            $ordersDb = trim((string) ($wws['orders_database_id'] ?? ''));
            if ($ordersDb !== '') {
                $wws['database_id'] = $ordersDb;
            }
            $ordersUser = trim((string) ($wws['orders_username'] ?? ''));
            if ($ordersUser !== '') {
                $wws['username'] = $ordersUser;
            }
            $ordersPass = (string) ($wws['orders_password'] ?? '');
            if ($ordersPass !== '') {
                $wws['password'] = $ordersPass;
            }
        }

        $this->wwsConfig = $wws;

        $this->baseUrl = rtrim($this->wwsConfig['base_url'], '/');
        $this->databaseId = $this->wwsConfig['database_id'];
        $this->username = $this->wwsConfig['username'];
        $this->password = $this->wwsConfig['password'];
        $this->verifySsl = $this->wwsConfig['verify_ssl'];

        $this->logger = new Logger();
    }

    /**
     * Make GET request
     */
    public function get($endpoint, $params = [])
    {
        return $this->request('GET', $endpoint, null, $params);
    }

    /**
     * Make POST request
     */
    public function post($endpoint, $data = null, $params = [])
    {
        return $this->request('POST', $endpoint, $data, $params);
    }

    /**
     * Make HTTP request
     */
    private function request($method, $endpoint, $data = null, $params = [])
    {
        $startTime = microtime(true);
        
        // Build URL
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        
        // Add database ID if not in endpoint
        if (strpos($endpoint, '/' . $this->databaseId . '/') === false && 
            strpos($endpoint, '/' . $this->databaseId) === false) {
            $url = $this->baseUrl . '/' . ltrim($endpoint, '/') . '/' . $this->databaseId;
        }
        
        // Add query parameters
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();
        
        // Get timeout settings from config (defaults: 60s timeout, 30s connect)
        $timeout = $this->wwsConfig['timeout'] ?? 60;
        $connectTimeout = $this->wwsConfig['connect_timeout'] ?? 30;
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                ]);
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $executionTime = microtime(true) - $startTime;
        
        curl_close($ch);

        // Log API call
        $this->logger->logApiCall(
            'wws',
            $endpoint,
            $method,
            $data,
            $response ? json_decode($response, true) : null,
            $httpCode,
            $error ?: null,
            $executionTime
        );

        if ($error) {
            LogHelper::error('WWS API CURL Error', [
                'endpoint' => $endpoint,
                'method' => $method,
                'url' => $url,
                'error' => $error,
                'http_code' => $httpCode,
                'connect_timeout' => $connectTimeout,
                'timeout' => $timeout,
            ]);
            throw new ApiException("CURL Error: $error", 0, null);
        }

        if ($httpCode >= 400) {
            $errorData = $response ? json_decode($response, true) : null;
            LogHelper::error('WWS API Error', [
                'endpoint' => $endpoint,
                'method' => $method,
                'http_code' => $httpCode,
                'error_data' => $errorData,
            ]);
            throw new ApiException(
                "HTTP Error $httpCode: " . ($errorData['message'] ?? 'Unknown error'),
                $httpCode,
                $errorData
            );
        }

        $responseData = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            LogHelper::error('WWS API Invalid JSON response', [
                'endpoint' => $endpoint,
                'method' => $method,
                'http_code' => $httpCode,
                'json_error' => json_last_error_msg(),
            ]);
            throw new ApiException("Invalid JSON response: " . json_last_error_msg(), $httpCode);
        }

        // Extract result from response structure
        if (isset($responseData['result']) && is_array($responseData['result'])) {
            return $responseData['result'];
        }

        return $responseData;
    }

    /**
     * Get database ID
     */
    public function getDatabaseId()
    {
        return $this->databaseId;
    }
}

