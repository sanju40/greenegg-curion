<?php

namespace App\Api\Shopify;

use App\Utils\Logger;
use App\Utils\LogHelper;
use App\Exceptions\ShopifyException;
use CurlHandle;

/**
 * Shopify API Client
 * Base HTTP client for Shopify API
 */
class Client
{
    private $shopDomain;
    private $accessToken;
    private $apiVersion;
    private $logger;
    private $config;

    public function __construct()
    {
        $this->config = \App\Core\Config::get();
        $shopifyConfig = $this->config['shopify'];
        
        $this->shopDomain = rtrim($shopifyConfig['shop_domain'], '/');
        $this->accessToken = $shopifyConfig['access_token'];
        $this->apiVersion = $shopifyConfig['api_version'];
        
        $this->logger = new Logger();
    }

    /**
     * Get base URL
     */
    private function getBaseUrl()
    {
        return "https://{$this->shopDomain}/admin/api/{$this->apiVersion}";
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
     * Make GraphQL request
     */
    public function graphql(string $query, array $variables = [])
    {
        $payload = [
            'query' => $query,
            'variables' => (object)$variables,
        ];

        return $this->request('POST', 'graphql.json', $payload);
    }

    /**
     * Make PUT request
     */
    public function put($endpoint, $data = null, $params = [])
    {
        return $this->request('PUT', $endpoint, $data, $params);
    }

    /**
     * Make DELETE request
     */
    public function delete($endpoint, $params = [])
    {
        return $this->request('DELETE', $endpoint, null, $params);
    }

    /**
     * Make HTTP request with automatic rate-limit handling.
     *
     * Shopify REST limit: 2 req/s (leaky-bucket 40/40 for most plans).
     * Strategy:
     *   1. After each successful response, check X-Shopify-Shop-Api-Call-Limit.
     *      If bucket is ≥ 80% full, sleep 500 ms to let it drain.
     *   2. On HTTP 429, sleep for the Retry-After header value (default 2 s)
     *      and retry up to MAX_RETRIES times with exponential back-off.
     */
    private const MAX_RETRIES        = 5;
    private const RETRY_BACKOFF_BASE = 2; // seconds

    private function request($method, $endpoint, $data = null, $params = [])
    {
        $attempt = 0;

        while (true) {
            $attempt++;
            $startTime = microtime(true);

            // Build URL
            $url = $this->getBaseUrl() . '/' . ltrim($endpoint, '/');
            if (!empty($params)) {
                $url .= '?' . http_build_query($params);
            }

            $ch = curl_init();

            $headers = [
                'Content-Type: application/json',
                'X-Shopify-Access-Token: ' . $this->accessToken,
            ];

            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADER         => true, // include response headers in output
            ]);

            if (in_array($method, ['POST', 'PUT'])) {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
            } elseif ($method === 'DELETE') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            }

            $rawResponse   = curl_exec($ch);
            $httpCode      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize    = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $curlError     = curl_error($ch);
            $executionTime = microtime(true) - $startTime;
            curl_close($ch);

            // Split headers from body
            $rawHeaders = substr($rawResponse, 0, $headerSize);
            $response   = substr($rawResponse, $headerSize);

            if ($curlError) {
                LogHelper::error('Shopify API CURL Error', [
                    'endpoint' => $endpoint,
                    'method'   => $method,
                    'error'    => $curlError,
                ]);
                throw new ShopifyException("CURL Error: $curlError", 0, null);
            }

            // ── Rate-limit: 429 → retry with back-off ────────────────────────
            if ($httpCode === 429) {
                if ($attempt > self::MAX_RETRIES) {
                    LogHelper::error('Shopify 429: max retries exhausted', [
                        'endpoint' => $endpoint,
                        'attempts' => $attempt,
                    ]);
                    throw new ShopifyException("HTTP Error 429: rate limit exceeded after {$attempt} retries", 429);
                }

                // Honour Retry-After header if present, otherwise use exponential back-off
                $retryAfter = $this->parseHeader($rawHeaders, 'Retry-After');
                $sleepSec   = $retryAfter ? (float)$retryAfter : (self::RETRY_BACKOFF_BASE ** $attempt);
                $sleepSec   = min($sleepSec, 30); // cap at 30 s

                LogHelper::warning('Shopify 429 — sleeping before retry', [
                    'endpoint'    => $endpoint,
                    'attempt'     => $attempt,
                    'sleep_sec'   => $sleepSec,
                ]);
                usleep((int)($sleepSec * 1_000_000));
                continue; // retry
            }

            // ── Proactive throttle: slow down when bucket is nearly full ─────
            $callLimit = $this->parseHeader($rawHeaders, 'X-Shopify-Shop-Api-Call-Limit');
            if ($callLimit) {
                [$used, $total] = array_map('intval', explode('/', $callLimit));
                if ($total > 0 && ($used / $total) >= 0.8) {
                    // 80%+ of bucket used — pause 500 ms to let it drain
                    usleep(500_000);
                }
            }

            // ── Log API call ─────────────────────────────────────────────────
            $responseData = json_decode($response, true);
            $this->logger->logApiCall(
                'shopify',
                $endpoint,
                $method,
                $data,
                $responseData,
                $httpCode,
                null,
                $executionTime
            );

            if ($httpCode >= 400) {
                $errorMessage = 'Unknown error';
                $errors       = null;

                if (isset($responseData['error'])) {
                    $errorMessage = $responseData['error'];
                } elseif (isset($responseData['errors']) && is_array($responseData['errors'])) {
                    $errorMessage = json_encode($responseData['errors']);
                    $errors       = $responseData['errors'];
                } elseif (isset($responseData['errors'])) {
                    $errorMessage = $responseData['errors'];
                } elseif (isset($responseData['error_description'])) {
                    $errorMessage = $responseData['error_description'];
                }

                // Determine log level for the error:
                //  • 422 on inventory_levels — expected when Shopify Bundles manages
                //    a component's inventory; caller handles it, no noise needed.
                //  • Other 422s — business-logic rejection, log as warning.
                //  • 4xx/5xx — real errors, log as error.
                $isInventory422 = ($httpCode === 422 && strpos($endpoint, 'inventory_levels') !== false);
                $logLevel = $isInventory422 ? 'debug' : ($httpCode === 422 ? 'warning' : 'error');
                LogHelper::$logLevel('Shopify API Error', [
                    'http_code' => $httpCode,
                    'endpoint'  => $endpoint,
                    'method'    => $method,
                    'response'  => $responseData,
                ]);

                throw new ShopifyException("HTTP Error $httpCode: $errorMessage", $httpCode, $responseData, $errors);
            }

            if (json_last_error() !== JSON_ERROR_NONE && $response) {
                LogHelper::error('Shopify API Invalid JSON response', [
                    'endpoint'   => $endpoint,
                    'method'     => $method,
                    'http_code'  => $httpCode,
                    'json_error' => json_last_error_msg(),
                ]);
                throw new ShopifyException("Invalid JSON response: " . json_last_error_msg(), $httpCode);
            }

            return $responseData;
        }
    }

    /**
     * Extract a single header value from a raw HTTP header string.
     */
    private function parseHeader(string $rawHeaders, string $name): ?string
    {
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (stripos($line, $name . ':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }
        return null;
    }
}
