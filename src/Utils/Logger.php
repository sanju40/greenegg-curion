<?php

namespace App\Utils;

use App\Database\Database;
use PDO;

/**
 * Logger Utility
 * Legacy logger for API and sync operations
 * For new code, use AppLogger instead
 * 
 * @deprecated Use AppLogger for new code. This class is kept for backward compatibility.
 */
class Logger
{
    private $db;
    private $config;
    private $appLogger;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->config = \App\Core\Config::get();
        $this->appLogger = new AppLogger();
    }

    /**
     * Log API call to database
     */
    public function logApiCall($apiType, $endpoint, $method, $requestData = null, $responseData = null, $statusCode = null, $errorMessage = null, $executionTime = null)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO api_logs 
                (api_type, endpoint, method, request_data, response_data, status_code, error_message, execution_time)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $requestJson = $requestData ? json_encode($requestData, JSON_UNESCAPED_UNICODE) : null;
            $responseJson = $responseData ? json_encode($responseData, JSON_UNESCAPED_UNICODE) : null;

            $stmt->execute([
                $apiType,
                $endpoint,
                $method,
                $requestJson,
                $responseJson,
                $statusCode,
                $errorMessage,
                $executionTime
            ]);

            $logId = $this->db->lastInsertId();
            
            // Also log to AppLogger for critical API errors
            if ($statusCode >= 500 || $errorMessage) {
                $this->appLogger->error('API call failed', [
                    'api_type' => $apiType,
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'status_code' => $statusCode,
                    'error' => $errorMessage,
                ]);
            }
            
            return $logId;
        } catch (\Exception $e) {
            // Use AppLogger for critical logging failures
            $this->appLogger->critical('Failed to log API call', [
                'api_type' => $apiType,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Log sync operation
     */
    public function logSync($operationType, $entityType, $entityId, $shopifyId = null, $status = 'pending', $requestData = null, $responseData = null, $errorMessage = null)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO sync_logs 
                (operation_type, entity_type, entity_id, shopify_id, status, request_data, response_data, error_message)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $requestJson = $requestData ? json_encode($requestData, JSON_UNESCAPED_UNICODE) : null;
            $responseJson = $responseData ? json_encode($responseData, JSON_UNESCAPED_UNICODE) : null;

            $stmt->execute([
                $operationType,
                $entityType,
                $entityId,
                $shopifyId,
                $status,
                $requestJson,
                $responseJson,
                $errorMessage
            ]);

            $logId = $this->db->lastInsertId();

            // Update completed_at if status is success or failed
            if (in_array($status, ['success', 'failed'])) {
                $this->updateSyncLogStatus($logId, $status);
            }

            // Also log to AppLogger for critical sync failures
            if ($status === 'failed') {
                $this->appLogger->error('Sync operation failed', [
                    'operation_type' => $operationType,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'error' => $errorMessage,
                ]);
            }
            
            return $logId;
        } catch (\Exception $e) {
            // Use AppLogger for critical logging failures
            $this->appLogger->critical('Failed to log sync operation', [
                'operation_type' => $operationType,
                'entity_type' => $entityType,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Update sync log status
     */
    public function updateSyncLogStatus($logId, $status, $responseData = null, $errorMessage = null, $shopifyId = null)
    {
        try {
            $responseJson = $responseData ? json_encode($responseData, JSON_UNESCAPED_UNICODE) : null;
            
            $stmt = $this->db->prepare("
                UPDATE sync_logs 
                SET status = ?, 
                    response_data = ?, 
                    error_message = ?,
                    shopify_id = ?,
                    completed_at = NOW()
                WHERE id = ?
            ");

            $stmt->execute([
                $status,
                $responseJson,
                $errorMessage,
                $shopifyId,
                $logId
            ]);

            return true;
        } catch (\Exception $e) {
            $this->appLogger->warning('Failed to update sync log', [
                'log_id' => $logId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Increment retry count for sync log
     */
    public function incrementRetryCount($logId)
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE sync_logs 
                SET retry_count = retry_count + 1,
                    status = 'retrying'
                WHERE id = ?
            ");

            $stmt->execute([$logId]);
            return true;
        } catch (\Exception $e) {
            $this->appLogger->warning('Failed to increment retry count', [
                'log_id' => $logId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get sync logs
     */
    public function getSyncLogs($limit = 100, $status = null, $operationType = null)
    {
        try {
            $sql = "SELECT * FROM sync_logs WHERE 1=1";
            $params = [];

            if ($status) {
                $sql .= " AND status = ?";
                $params[] = $status;
            }

            if ($operationType) {
                $sql .= " AND operation_type = ?";
                $params[] = $operationType;
            }

            $sql .= " ORDER BY created_at DESC LIMIT ?";
            $params[] = $limit;

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            $this->appLogger->warning('Failed to get sync logs', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get API logs
     */
    public function getApiLogs($limit = 100, $apiType = null)
    {
        try {
            $sql = "SELECT * FROM api_logs WHERE 1=1";
            $params = [];

            if ($apiType) {
                $sql .= " AND api_type = ?";
                $params[] = $apiType;
            }

            $sql .= " ORDER BY created_at DESC LIMIT ?";
            $params[] = $limit;

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            $this->appLogger->warning('Failed to get API logs', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}

