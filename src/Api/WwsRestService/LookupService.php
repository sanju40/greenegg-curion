<?php

namespace App\Api\WwsRestService;

/**
 * Lookup Service
 * Handles master data/lookup table operations
 */
class LookupService
{
    private $client;
    private $cache = [];

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client();
    }

    /**
     * Get all lookup data
     */
    public function getLookupData($tables = null)
    {
        $databaseId = $this->client->getDatabaseId();
        
        $cacheKey = 'lookup_' . $databaseId . '_' . ($tables ?? 'all');
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $endpoint = "lookupData/{$databaseId}";
        if ($tables) {
            $endpoint .= '/' . (is_array($tables) ? implode(',', $tables) : $tables);
        }

        $result = $this->client->get($endpoint);
        $this->cache[$cacheKey] = $result;
        
        return $result;
    }

    /**
     * Get specific lookup table
     */
    public function getLookupTable($tableName)
    {
        $allData = $this->getLookupData($tableName);
        
        if (!empty($allData) && is_array($allData)) {
            foreach ($allData as $lookup) {
                if (isset($lookup['lookupType']) && $lookup['lookupType'] === $tableName) {
                    return $lookup['data'] ?? [];
                }
            }
        }
        
        return [];
    }

    /**
     * Find lookup value by ID
     */
    public function findLookupValue($tableName, $id)
    {
        $data = $this->getLookupTable($tableName);
        
        foreach ($data as $item) {
            if (isset($item['id']) && $item['id'] == $id) {
                return $item;
            }
        }
        
        return null;
    }
}

