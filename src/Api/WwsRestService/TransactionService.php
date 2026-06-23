<?php

namespace App\Api\WwsRestService;

/**
 * Transaction Service
 * Handles transaction-related API operations
 */
class TransactionService
{
    private $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client();
    }

    /**
     * Get transaction by ID
     */
    public function getTransaction($transactionId)
    {
        $databaseId = $this->client->getDatabaseId();
        $result = $this->client->get("transaction/{$databaseId}/{$transactionId}");
        return !empty($result) ? $result[0] : null;
    }

    /**
     * Create new transaction
     */
    public function createTransaction(array $transactionData)
    {
        $databaseId = $this->client->getDatabaseId();
        $result = $this->client->post("transaction/{$databaseId}/new", $transactionData);
        return !empty($result) ? $result[0] : null;
    }

    /**
     * Update an existing transaction
     * As per WWS API guide: POST /transaction/{db}/{id} with the full transaction object.
     * Fetch the transaction first via getTransaction(), merge your changes, then pass the
     * result here so all existing fields are preserved.
     */
    public function updateTransaction($transactionId, array $transactionData)
    {
        $databaseId = $this->client->getDatabaseId();
        $result = $this->client->post("transaction/{$databaseId}/{$transactionId}", $transactionData);
        return !empty($result) ? $result[0] : null;
    }

    /**
     * Get transaction PDF
     */
    public function getTransactionPdf($transactionId)
    {
        $databaseId = $this->client->getDatabaseId();
        // This would return binary PDF data
        // Implementation depends on how PDF is handled
        return $this->client->get("transactionPdf/{$databaseId}/{$transactionId}", ['json' => 'false']);
    }
}

