<?php

namespace App\Core\Services;

use App\Api\Shopify\Client;
use App\Api\Shopify\OrderService;
use App\Core\Contracts\ErpProviderInterface;
use App\Database\Repository\ProductMappingRepository;
use App\Utils\LogHelper;

/**
 * Curion bundle detection helpers shared by product sync and order processing.
 *
 * Parent/child bundle links on orders come from Shopify GraphQL lineItemGroup
 * (admin UI: "Part of: [Bundle title]"). REST webhook payloads do not include this.
 *
 * Curion bundles are identified by the CURIONBUNDLE tag on the parent Shopify product.
 * WWS partsList is used as a fallback when GraphQL grouping is unavailable.
 */
class CurionBundleHelper
{
    private ErpProviderInterface $erpProvider;
    private ProductMappingRepository $mappingRepository;
    private OrderService $orderService;
    private Client $shopifyClient;
    private array $config;

    /** @var array<string, string[]> */
    private array $productTagsCache = [];

    /** @var int[]|null */
    private ?array $curionBundleChildWwsIds = null;

    public function __construct(
        ?ErpProviderInterface $erpProvider = null,
        ?ProductMappingRepository $mappingRepository = null,
        ?OrderService $orderService = null,
        ?Client $shopifyClient = null
    ) {
        $this->config = \App\Core\Config::get();
        $this->erpProvider = $erpProvider ?? \App\Core\Factory\ProviderFactory::createErpProvider('wws');
        $this->mappingRepository = $mappingRepository ?? new ProductMappingRepository();
        $this->orderService = $orderService ?? new OrderService();
        $this->shopifyClient = $shopifyClient ?? new Client();
    }

    /**
     * Whether a Shopify order line item is a Curion bundle parent (not a component).
     */
    public function isCurionBundleParentLineItem(array $lineItem): bool
    {
        $productId = isset($lineItem['product_id']) ? (string) $lineItem['product_id'] : null;
        if ($productId && $this->isCurionBundleParentProductId($productId)) {
            return true;
        }

        $sku = trim($lineItem['sku'] ?? '');
        if ($sku === '') {
            return false;
        }

        $mapping = $this->mappingRepository->findBySku($sku);
        if (!$mapping || empty($mapping['wws_product_id'])) {
            return false;
        }

        return $this->isCurionBundleWwsProductId($mapping['wws_product_id']);
    }

    /**
     * Whether a Shopify product is a Curion-managed bundle parent (CURIONBUNDLE tag).
     */
    public function isCurionBundleParentProductId(string $shopifyProductId): bool
    {
        $tags = $this->resolveShopifyProductTags($shopifyProductId);
        return in_array('CURIONBUNDLE', $tags, true);
    }

    /**
     * Product tags from product_mappings, falling back to a live Shopify product fetch.
     *
     * @return string[]
     */
    private function resolveShopifyProductTags(string $shopifyProductId): array
    {
        if (isset($this->productTagsCache[$shopifyProductId])) {
            return $this->productTagsCache[$shopifyProductId];
        }

        $tags = $this->mappingRepository->getShopifyTags($shopifyProductId);
        if (!empty($tags)) {
            return $this->productTagsCache[$shopifyProductId] = $tags;
        }

        try {
            $result = $this->shopifyClient->get("products/{$shopifyProductId}.json");
            $tagString = $result['product']['tags'] ?? '';
            $tags = array_values(array_filter(array_map('trim', explode(',', (string) $tagString))));
        } catch (\Exception $e) {
            LogHelper::debug('Could not fetch Shopify product tags for bundle check', [
                'shopify_product_id' => $shopifyProductId,
                'error'              => $e->getMessage(),
            ]);
            $tags = [];
        }

        return $this->productTagsCache[$shopifyProductId] = $tags;
    }

    /**
     * SKUs of partsList children for a Curion bundle parent line item (legacy path).
     *
     * @return string[]
     */
    public function getComponentSkusForBundleLineItem(array $lineItem): array
    {
        $bundleWwsId = $this->resolveBundleWwsProductId($lineItem);
        if ($bundleWwsId === null) {
            return [];
        }

        try {
            $wwsProduct = $this->erpProvider->getProduct($bundleWwsId);
        } catch (\Exception $e) {
            LogHelper::warning('Could not load WWS bundle for order child filter', [
                'wws_product_id' => $bundleWwsId,
                'sku'            => $lineItem['sku'] ?? null,
                'error'          => $e->getMessage(),
            ]);
            return [];
        }

        if (!is_array($wwsProduct)) {
            return [];
        }

        $skus = [];
        foreach ($wwsProduct['partsList'] ?? [] as $part) {
            if (empty($part['productId'])) {
                continue;
            }
            $childMapping = $this->mappingRepository->findByWwsProductId($part['productId']);
            $childSku = trim($childMapping['wws_product_sku'] ?? '');
            if ($childSku !== '') {
                $skus[] = $childSku;
            }
        }

        return array_values(array_unique($skus));
    }

    /**
     * Collapse Curion bundle component lines into parent bundle SKUs for WWS.
     *
     * 1. GraphQL lineItemGroup — primary (matches Shopify "Part of:" UI)
     * 2. Legacy — parent CURIONBUNDLE line + WWS partsList child SKU skip
     *
     * @return array{order_data: array, skipped: int, collapsed: int}
     */
    public function filterOrderLineItems(array $orderData): array
    {
        if (!($this->config['order_processing']['skip_curion_bundle_child_line_items'] ?? true)) {
            return ['order_data' => $orderData, 'skipped' => 0, 'collapsed' => 0];
        }

        $lineItems = $orderData['line_items'] ?? [];
        if (empty($lineItems)) {
            return ['order_data' => $orderData, 'skipped' => 0, 'collapsed' => 0];
        }

        $shopifyOrderId = $orderData['id'] ?? null;
        if ($shopifyOrderId) {
            $groupMap = $this->orderService->getLineItemGroupMap((string) $shopifyOrderId);
            if (!empty($groupMap)) {
                return $this->filterOrderLineItemsByLineItemGroup($orderData, $groupMap);
            }

            LogHelper::debug('No lineItemGroup data from GraphQL — using legacy bundle filter', [
                'shopify_order_id' => $shopifyOrderId,
            ]);
        }

        return $this->filterOrderLineItemsLegacy($orderData);
    }

    /**
     * Group children via Shopify lineItemGroup; collapse Curion (CURIONBUNDLE) parents only.
     *
     * @param array<string, array> $groupMap From OrderService::getLineItemGroupMap()
     * @return array{order_data: array, skipped: int, collapsed: int}
     */
    private function filterOrderLineItemsByLineItemGroup(array $orderData, array $groupMap): array
    {
        $groups     = [];
        $standalone = [];

        foreach ($orderData['line_items'] as $item) {
            $lineId = (string) ($item['id'] ?? '');
            $meta   = $groupMap[$lineId] ?? null;

            if (!$meta || empty($meta['group_id'])) {
                $standalone[] = $item;
                continue;
            }

            $groupId = $meta['group_id'];
            if (!isset($groups[$groupId])) {
                $groups[$groupId] = [
                    'parent_product_id' => $meta['parent_product_id'],
                    'parent_variant_id' => $meta['parent_variant_id'],
                    'bundle_quantity'   => $meta['bundle_quantity'],
                    'title'             => $meta['group_title'],
                    'children'          => [],
                ];
            }
            $groups[$groupId]['children'][] = $item;
        }

        $filtered  = $standalone;
        $skipped   = 0;
        $collapsed = 0;

        foreach ($groups as $groupId => $group) {
            $parentProductId = (string) ($group['parent_product_id'] ?? '');

            if ($parentProductId === '' || !$this->isCurionBundleParentProductId($parentProductId)) {
                foreach ($group['children'] as $child) {
                    $filtered[] = $child;
                }
                continue;
            }

            $parentLine = $this->buildCollapsedBundleLineItem($group, $groupId);
            if ($parentLine === null) {
                LogHelper::warning('Could not collapse Curion bundle group — keeping component lines', [
                    'shopify_order_id'     => $orderData['id'] ?? null,
                    'shopify_order_number' => $orderData['order_number'] ?? $orderData['name'] ?? null,
                    'group_id'             => $groupId,
                    'bundle_title'         => $group['title'] ?? null,
                    'parent_product_id'    => $parentProductId,
                ]);
                foreach ($group['children'] as $child) {
                    $filtered[] = $child;
                }
                continue;
            }

            $skipped += count($group['children']);
            $collapsed++;

            LogHelper::info('Collapsed Curion bundle line items into parent SKU', [
                'shopify_order_id'     => $orderData['id'] ?? null,
                'shopify_order_number' => $orderData['order_number'] ?? $orderData['name'] ?? null,
                'group_id'             => $groupId,
                'bundle_title'         => $group['title'] ?? null,
                'parent_sku'           => $parentLine['sku'] ?? null,
                'bundle_quantity'      => $parentLine['quantity'] ?? null,
                'component_lines'      => count($group['children']),
            ]);

            $filtered[] = $parentLine;
        }

        $orderData['line_items'] = $filtered;

        return [
            'order_data' => $orderData,
            'skipped'    => $skipped,
            'collapsed'  => $collapsed,
        ];
    }

    /**
     * Legacy: skip child SKUs when a parent bundle line exists in the same order.
     *
     * @return array{order_data: array, skipped: int, collapsed: int}
     */
    private function filterOrderLineItemsLegacy(array $orderData): array
    {
        $lineItems = $orderData['line_items'] ?? [];

        $componentSkusToSkip = [];
        foreach ($lineItems as $item) {
            if (!$this->isCurionBundleParentLineItem($item)) {
                continue;
            }
            $componentSkusToSkip = array_merge(
                $componentSkusToSkip,
                $this->getComponentSkusForBundleLineItem($item)
            );
        }

        $componentSkusToSkip = array_values(array_unique($componentSkusToSkip));
        if (empty($componentSkusToSkip)) {
            return ['order_data' => $orderData, 'skipped' => 0, 'collapsed' => 0];
        }

        $filtered = [];
        $skipped  = 0;

        foreach ($lineItems as $item) {
            $sku = trim($item['sku'] ?? '');

            if (
                $sku !== ''
                && in_array($sku, $componentSkusToSkip, true)
                && !$this->isCurionBundleParentLineItem($item)
            ) {
                LogHelper::info('Skipping Curion bundle child line item in order (legacy filter)', [
                    'shopify_order_id'     => $orderData['id'] ?? null,
                    'shopify_order_number' => $orderData['order_number'] ?? $orderData['name'] ?? null,
                    'line_item_id'         => $item['id'] ?? null,
                    'sku'                  => $sku,
                ]);
                $skipped++;
                continue;
            }

            $filtered[] = $item;
        }

        $orderData['line_items'] = $filtered;

        return [
            'order_data' => $orderData,
            'skipped'    => $skipped,
            'collapsed'  => 0,
        ];
    }

    /**
     * Build a synthetic REST-style line item for the bundle parent SKU.
     *
     * @param array $group keys: parent_product_id, parent_variant_id, bundle_quantity, title, children
     */
    private function buildCollapsedBundleLineItem(array $group, string $groupId): ?array
    {
        $parentProductId = (string) ($group['parent_product_id'] ?? '');
        $mapping = $this->mappingRepository->findByShopifyProductId($parentProductId);

        $parentSku = trim($mapping['wws_product_sku'] ?? '');
        if ($parentSku === '') {
            return null;
        }

        $bundleQty = max(1, (int) ($group['bundle_quantity'] ?? 1));
        $unitPrice = $this->calculateBundleUnitPrice($group['children'] ?? [], $bundleQty);

        $lineItem = [
            'id'                 => 'curion-bundle-' . $groupId,
            'product_id'         => $parentProductId,
            'variant_id'         => $group['parent_variant_id'] ?? null,
            'sku'                => $parentSku,
            'title'              => $group['title'] ?? $parentSku,
            'quantity'           => $bundleQty,
            'price'              => (string) $unitPrice,
            'price_set'          => [
                'shop_money' => [
                    'amount'        => (string) $unitPrice,
                    'currency_code' => 'CHF',
                ],
            ],
            'curion_bundle_group' => $groupId,
        ];

        $children = $this->summarizeBundleChildLineItems($group['children'] ?? []);
        if (!empty($children)) {
            $lineItem['curion_bundle_children'] = $children;
        }

        return $lineItem;
    }

    /**
     * @param array<int, array> $children
     * @return array<int, array{sku: string, quantity: int, title: string|null}>
     */
    private function summarizeBundleChildLineItems(array $children): array
    {
        $summary = [];
        foreach ($children as $child) {
            $sku = trim((string) ($child['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $summary[] = [
                'sku'      => $sku,
                'quantity' => (int) ($child['quantity'] ?? 1),
                'title'    => $child['title'] ?? $child['name'] ?? null,
            ];
        }

        return $summary;
    }

    /**
     * Bundle unit price = sum(component line totals) / bundle quantity.
     *
     * @param array<int, array> $children
     */
    private function calculateBundleUnitPrice(array $children, int $bundleQty): float
    {
        $total = 0.0;
        foreach ($children as $child) {
            $price = (float) ($child['price'] ?? 0);
            if (isset($child['price_set']['shop_money']['amount'])) {
                $price = (float) $child['price_set']['shop_money']['amount'];
            }
            $total += $price * (int) ($child['quantity'] ?? 1);
        }

        return round($total / max(1, $bundleQty), 2);
    }

    /**
     * Whether catalog sync should skip a WWS product that is a partsList child of a Curion bundle.
     */
    public function shouldSkipCurionBundleChildProduct($wwsProductId): bool
    {
        if ($this->config['bundles']['sync_child_products'] ?? false) {
            return false;
        }
        if (!($this->config['bundles']['skip_child_products_in_catalog_sync'] ?? true)) {
            return false;
        }

        if ($this->curionBundleChildWwsIds === null) {
            $this->curionBundleChildWwsIds = $this->loadCurionBundleChildWwsIds();
        }

        return in_array((int) $wwsProductId, $this->curionBundleChildWwsIds, true);
    }

    /**
     * @return int[]
     */
    public function loadCurionBundleChildWwsIds(): array
    {
        $childIds    = [];
        $bundleSmIds = $this->config['bundles']['stock_management_ids'] ?? [101, 102];

        try {
            $products = $this->erpProvider->searchProducts('*', 0, 0);

            if (!empty($products) && is_array($products)) {
                if (isset($products[0]) && is_array($products[0]) && isset($products[0][0])) {
                    $products = $products[0];
                }
            }

            foreach ($products as $wwsProduct) {
                if (empty($wwsProduct['id'])) {
                    continue;
                }

                $smId = isset($wwsProduct['stockManagement']['id'])
                    ? (int) $wwsProduct['stockManagement']['id']
                    : null;

                if ($smId === null || !in_array($smId, $bundleSmIds, true)) {
                    continue;
                }

                $partsList = $wwsProduct['partsList'] ?? null;
                if (!is_array($partsList) || empty($partsList)) {
                    $full = $this->erpProvider->getProduct($wwsProduct['id']);
                    $partsList = is_array($full) ? ($full['partsList'] ?? []) : [];
                }

                foreach ($partsList as $part) {
                    if (!empty($part['productId'])) {
                        $childIds[] = (int) $part['productId'];
                    }
                }
            }
        } catch (\Exception $e) {
            LogHelper::warning('Could not build Curion bundle child ID list', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        return array_values(array_unique($childIds));
    }

    private function resolveBundleWwsProductId(array $lineItem): ?int
    {
        $mapping = null;
        $sku = trim($lineItem['sku'] ?? '');

        if ($sku !== '') {
            $mapping = $this->mappingRepository->findBySku($sku);
        }

        if (!$mapping && !empty($lineItem['product_id'])) {
            $mapping = $this->mappingRepository->findByShopifyProductId((string) $lineItem['product_id']);
        }

        if (!$mapping || empty($mapping['wws_product_id'])) {
            return null;
        }

        $wwsId = (int) $mapping['wws_product_id'];
        if ($wwsId <= 0) {
            return null;
        }

        return $this->isCurionBundleWwsProductId($wwsId) ? $wwsId : null;
    }

    private function isCurionBundleWwsProductId($wwsProductId): bool
    {
        if (is_string($wwsProductId) && str_starts_with($wwsProductId, 'shopify:')) {
            return false;
        }

        $bundleSmIds = $this->config['bundles']['stock_management_ids'] ?? [101, 102];

        try {
            $wwsProduct = $this->erpProvider->getProduct($wwsProductId);
        } catch (\Exception $e) {
            return false;
        }

        if (!is_array($wwsProduct)) {
            return false;
        }

        $smId = isset($wwsProduct['stockManagement']['id'])
            ? (int) $wwsProduct['stockManagement']['id']
            : null;

        return $smId !== null && in_array($smId, $bundleSmIds, true);
    }
}
