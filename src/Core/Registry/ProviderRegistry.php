<?php

namespace App\Core\Registry;

use App\Database\Database;
use PDO;

/**
 * Provider Registry
 * Manages provider registration and capabilities
 */
class ProviderRegistry
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Register a new provider
     * @param array $config Provider configuration
     * @return int Provider ID
     */
    public function registerProvider(array $config): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO providers 
            (name, type, status, base_url, auth_method, auth_config, rate_limit_per_minute, 
             rate_limit_per_hour, pagination_style, supported_formats, capabilities, metadata)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $config['name'],
            $config['type'],
            $config['status'] ?? 'active',
            $config['base_url'] ?? null,
            $config['auth_method'],
            json_encode($config['auth_config'] ?? []),
            $config['rate_limit_per_minute'] ?? 60,
            $config['rate_limit_per_hour'] ?? 1000,
            $config['pagination_style'] ?? 'offset_limit',
            json_encode($config['supported_formats'] ?? ['json']),
            json_encode($config['capabilities'] ?? []),
            json_encode($config['metadata'] ?? []),
        ]);

        $providerId = $this->db->lastInsertId();

        // Register capabilities
        if (!empty($config['capabilities'])) {
            $this->updateCapabilities($providerId, $config['capabilities']);
        }

        return $providerId;
    }

    /**
     * Get provider by name
     * @param string $name
     * @return array|null
     */
    public function getProvider(string $name): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM providers WHERE name = ?");
        $stmt->execute([$name]);
        $provider = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($provider) {
            $provider['auth_config'] = json_decode($provider['auth_config'], true);
            $provider['supported_formats'] = json_decode($provider['supported_formats'], true);
            $provider['capabilities'] = json_decode($provider['capabilities'], true);
            $provider['metadata'] = json_decode($provider['metadata'], true);
        }

        return $provider ?: null;
    }

    /**
     * Get provider by ID
     * @param int $id
     * @return array|null
     */
    public function getProviderById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM providers WHERE id = ?");
        $stmt->execute([$id]);
        $provider = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($provider) {
            $provider['auth_config'] = json_decode($provider['auth_config'], true);
            $provider['supported_formats'] = json_decode($provider['supported_formats'], true);
            $provider['capabilities'] = json_decode($provider['capabilities'], true);
            $provider['metadata'] = json_decode($provider['metadata'], true);
        }

        return $provider ?: null;
    }

    /**
     * Get all providers
     * @param string|null $type Filter by type (erp, ecommerce, wms)
     * @param string|null $status Filter by status
     * @return array
     */
    public function getProviders(?string $type = null, ?string $status = null): array
    {
        $sql = "SELECT * FROM providers WHERE 1=1";
        $params = [];

        if ($type) {
            $sql .= " AND type = ?";
            $params[] = $type;
        }

        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($providers as &$provider) {
            $provider['auth_config'] = json_decode($provider['auth_config'], true);
            $provider['supported_formats'] = json_decode($provider['supported_formats'], true);
            $provider['capabilities'] = json_decode($provider['capabilities'], true);
            $provider['metadata'] = json_decode($provider['metadata'], true);
        }

        return $providers;
    }

    /**
     * Get providers by type
     * @param string $type
     * @return array
     */
    public function getProvidersByType(string $type): array
    {
        return $this->getProviders($type);
    }

    /**
     * Get active providers
     * @param string|null $type
     * @return array
     */
    public function getActiveProviders(?string $type = null): array
    {
        return $this->getProviders($type, 'active');
    }

    /**
     * Update provider capabilities
     * @param int $providerId
     * @param array $capabilities Array of capability strings
     */
    public function updateCapabilities(int $providerId, array $capabilities): void
    {
        // Delete existing capabilities
        $stmt = $this->db->prepare("DELETE FROM provider_capabilities WHERE provider_id = ?");
        $stmt->execute([$providerId]);

        // Insert new capabilities
        $stmt = $this->db->prepare("
            INSERT INTO provider_capabilities (provider_id, capability, supported)
            VALUES (?, ?, ?)
        ");

        foreach ($capabilities as $capability) {
            $stmt->execute([$providerId, $capability, true]);
        }

        // Update capabilities JSON in providers table
        $stmt = $this->db->prepare("UPDATE providers SET capabilities = ? WHERE id = ?");
        $stmt->execute([json_encode($capabilities), $providerId]);
    }

    /**
     * Check if provider supports capability
     * @param string $providerName
     * @param string $capability
     * @return bool
     */
    public function checkCapability(string $providerName, string $capability): bool
    {
        $stmt = $this->db->prepare("
            SELECT pc.supported 
            FROM provider_capabilities pc
            JOIN providers p ON pc.provider_id = p.id
            WHERE p.name = ? AND pc.capability = ? AND p.status = 'active'
        ");
        $stmt->execute([$providerName, $capability]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result && $result['supported'] == 1;
    }

    /**
     * Update provider status
     * @param string $name
     * @param string $status
     */
    public function updateStatus(string $name, string $status): void
    {
        $stmt = $this->db->prepare("UPDATE providers SET status = ? WHERE name = ?");
        $stmt->execute([$status, $name]);
    }

    /**
     * Delete provider
     * @param string $name
     */
    public function deleteProvider(string $name): void
    {
        $stmt = $this->db->prepare("DELETE FROM providers WHERE name = ?");
        $stmt->execute([$name]);
    }
}

