<?php

namespace App\Api\Shopify;

/**
 * Shopify Order Service
 * Handles Shopify order operations
 */
class OrderService
{
    private $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client();
    }

    /**
     * Get order by ID
     * @param mixed $orderId
     * @return array|null
     */
    public function getOrder($orderId)
    {
        $result = $this->client->get("orders/{$orderId}.json");
        return $result['order'] ?? null;
    }

    /**
     * Fetch Shopify Bundles "Part of:" grouping for each order line item (GraphQL only).
     *
     * REST/webhook order payloads do not include parent/child bundle links. Shopify stores
     * that relationship on lineItemGroup (admin UI: "Part of: [Bundle title]").
     *
     * @return array<string, array> Map of REST line_item id => group metadata
     */
    public function getLineItemGroupMap($orderId): array
    {
        $orderId = (string) $orderId;
        $orderGid = str_starts_with($orderId, 'gid://')
            ? $orderId
            : 'gid://shopify/Order/' . $orderId;

        $query = <<<'GQL'
        query OrderLineItemGroups($id: ID!) {
            order(id: $id) {
                lineItems(first: 250) {
                    edges {
                        node {
                            id
                            lineItemGroup {
                                id
                                title
                                quantity
                                productId
                                variantId
                            }
                        }
                    }
                }
            }
        }
        GQL;

        try {
            $result = $this->client->graphql($query, ['id' => $orderGid]);
        } catch (\Exception $e) {
            return [];
        }

        $map = [];
        foreach ($result['data']['order']['lineItems']['edges'] ?? [] as $edge) {
            $node  = $edge['node'] ?? null;
            $group = $node['lineItemGroup'] ?? null;
            if (!$node || !$group || empty($node['id'])) {
                continue;
            }

            $lineItemId = $this->legacyIdFromGid($node['id']);
            if ($lineItemId === '') {
                continue;
            }

            $map[$lineItemId] = [
                'group_id'           => $this->legacyIdFromGid($group['id'] ?? ''),
                'group_title'        => (string) ($group['title'] ?? ''),
                'bundle_quantity'    => max(1, (int) ($group['quantity'] ?? 1)),
                'parent_product_id'  => $this->legacyIdFromGid($group['productId'] ?? ''),
                'parent_variant_id'  => $this->legacyIdFromGid($group['variantId'] ?? ''),
            ];
        }

        return $map;
    }

    /**
     * @param string|null $gid
     */
    private function legacyIdFromGid($gid): string
    {
        if (!$gid || !is_string($gid)) {
            return '';
        }
        if (preg_match('/(\d+)$/', $gid, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Get orders (paginated)
     * @param array $params Query parameters (status, limit, page, etc.)
     * @return array Array of orders
     */
    public function getOrders(array $params = [])
    {
        $result = $this->client->get('orders.json', $params);
        return $result['orders'] ?? [];
    }

    /**
     * Create order
     * @param array $orderData
     * @return array|null Created order
     */
    public function createOrder(array $orderData)
    {
        $result = $this->client->post('orders.json', ['order' => $orderData]);
        return $result['order'] ?? null;
    }

    /**
     * Update order
     * @param mixed $orderId
     * @param array $orderData
     * @return array|null Updated order
     */
    public function updateOrder($orderId, array $orderData)
    {
        $result = $this->client->put("orders/{$orderId}.json", ['order' => $orderData]);
        return $result['order'] ?? null;
    }

    /**
     * Cancel order
     * @param mixed $orderId
     * @return array|null Cancelled order
     */
    public function cancelOrder($orderId)
    {
        $result = $this->client->post("orders/{$orderId}/cancel.json");
        return $result['order'] ?? null;
    }

    /**
     * Get order count
     * @param array $params Query parameters
     * @return int
     */
    public function getOrderCount(array $params = [])
    {
        $result = $this->client->get('orders/count.json', $params);
        return $result['count'] ?? 0;
    }
}
