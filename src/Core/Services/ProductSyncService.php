<?php

namespace App\Core\Services;

use App\Core\Factory\ProviderFactory;
use App\Core\Contracts\ErpProviderInterface;
use App\Core\Contracts\EcommerceProviderInterface;
use App\Adapters\WwsRestService\ProductAdapter as WwsAdapter;
use App\Adapters\Shopify\ProductAdapter as ShopifyAdapter;
use App\Api\Shopify\BundleService;
use App\Core\Models\Product;
use App\Database\Repository\ProductMappingRepository;
use App\Utils\Logger;
use App\Utils\LogHelper;
use App\Exceptions\BundleNotReadyException;
use App\Exceptions\SyncException;

/**
 * Enhanced Product Sync Service
 * Uses provider abstraction and adapters
 */
class ProductSyncService
{
    private $erpProvider;
    private $ecommerceProvider;
    private $wwsAdapter;
    private $shopifyAdapter;
    private $productMappingRepository;
    private $logger;
    private $config;

    // Metafield configuration
    private $metafieldNamespace = 'sync';
    private $metafieldSkipUpdateKey = 'skip_api_update';
    private $metafieldForceUpdateKey = 'force_update_info';

    // Cache: Shopify location ID fetched once per sync run (requires read_locations scope)
    private $cachedLocationId = null;
    private $locationsFetched  = false;

    // Deduplication: track component WWS IDs already synced this run so shared
    // components (same product appears in multiple bundles) are only synced once.
    private $syncedComponentWwsIds = [];

    /** @var CurionBundleHelper|null Lazy helper for Curion bundle child detection */
    private $curionBundleHelper = null;

    public function __construct(
        ?ErpProviderInterface $erpProvider = null,
        ?EcommerceProviderInterface $ecommerceProvider = null
    ) {
        $this->config = \App\Core\Config::get();
        
        // Use provided providers or create defaults
        $this->erpProvider = $erpProvider ?? ProviderFactory::createErpProvider('wws');
        $this->ecommerceProvider = $ecommerceProvider ?? ProviderFactory::createEcommerceProvider('shopify');
        
        $this->wwsAdapter = new WwsAdapter();
        $this->shopifyAdapter = new ShopifyAdapter();
        $this->productMappingRepository = new ProductMappingRepository();
        $this->logger = new Logger();
    }

    private function getCurionBundleHelper(): CurionBundleHelper
    {
        if ($this->curionBundleHelper === null) {
            $this->curionBundleHelper = new CurionBundleHelper(
                $this->erpProvider,
                $this->productMappingRepository
            );
        }
        return $this->curionBundleHelper;
    }

    /**
     * Sync all products from ERP to e-commerce
     *
     * @param int|null $limit      Maximum products to fetch from WWS (0 = all)
     * @param int      $offset     Skip this many products before fetching (for pagination)
     * @param bool     $bundlesOnly When true, only sync products detected as bundles
     * @return array Statistics including pagination info
     */
    public function syncAllProducts(?int $limit = null, int $offset = 0, bool $bundlesOnly = false): array
    {
        $synced  = 0;
        $errors  = 0;
        $skipped = 0;

        try {
            // Search products from ERP — WWS URL pattern: productSearch/{db}/*/{offset}/{limit}
            $products = $this->erpProvider->searchProducts('*', $offset, $limit ?? 0);
            
            // Flatten nested array if needed (WWS sometimes returns nested structure)
            if (!empty($products) && is_array($products)) {
                // Check if first element is an array of products
                if (isset($products[0]) && is_array($products[0]) && isset($products[0][0])) {
                    $products = $products[0];
                }
            }

            $bundleIds = $this->config['bundles']['stock_management_ids'] ?? [101, 102];

            foreach ($products as $wwsProduct) {
                try {
                    // ── Data quality guards — skip, not error ────────────────────
                    // These are WWS data issues (no ID / no SKU), not sync failures.
                    if (empty($wwsProduct['id'])) {
                        LogHelper::warning('Product has no ID in WWS listing — skipping', [
                            'product_data' => array_intersect_key($wwsProduct, array_flip(['sku', 'barcode', 'name'])),
                        ]);
                        $skipped++;
                        continue;
                    }

                    if (empty(trim($wwsProduct['sku'] ?? ''))) {
                        LogHelper::warning('Product has no SKU in WWS — skipping', [
                            'erp_product_id' => $wwsProduct['id'],
                            'name'           => $wwsProduct['name']['value']['D'] ?? '(no name)',
                        ]);
                        $skipped++;
                        continue;
                    }

                    // bundles_only filter: check stockManagement.id before fetching full product
                    if ($bundlesOnly) {
                        $smId = isset($wwsProduct['stockManagement']['id'])
                            ? (int) $wwsProduct['stockManagement']['id']
                            : null;
                        if (!in_array($smId, $bundleIds, true)) {
                            $skipped++;
                            continue;
                        }
                    }

                    // Pass the already-fetched product data so syncProduct
                    // does not issue a redundant getProduct() call.
                    $result = $this->syncProduct($wwsProduct['id'], $wwsProduct);
                    if ($result !== null) {
                        $synced++;
                    } else {
                        $skipped++;
                    }
                } catch (BundleNotReadyException $e) {
                    // Not a real error — child products just haven't been synced yet.
                    $skipped++;
                } catch (\Exception $e) {
                    $errors++;
                    // Error already logged to file by the originating method.
                    // Only write to DB log here for aggregate tracking.
                    $this->logger->logSync(
                        'product_sync',
                        'product',
                        $wwsProduct['id'] ?? 'unknown',
                        null,
                        'failed',
                        null,
                        null,
                        $e->getMessage()
                    );
                }
            }
        } catch (\Exception $e) {
            LogHelper::error('Failed to fetch products batch', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new SyncException("Failed to fetch products batch: " . $e->getMessage(), 0, $e);
        }

        return [
            'synced'      => $synced,
            'errors'      => $errors,
            'skipped'     => $skipped,
            'fetched'     => count($products),
            'offset'      => $offset,
            'limit'       => $limit,
            'bundles_only' => $bundlesOnly,
        ];
    }

    /**
     * Sync single product
     *
     * @param mixed      $erpProductId  ERP product ID
     * @param array|null $wwsProductData Pre-fetched WWS product data (skips getProduct API call).
     *                                   Pass this when calling from syncAllProducts to avoid a
     *                                   redundant round-trip — the search endpoint already returns
     *                                   the same full payload that getProduct would return.
     * @return array|null Synced product data
     */
    public function syncProduct($erpProductId, ?array $wwsProductData = null): ?array
    {
        try {
            if ($this->getCurionBundleHelper()->shouldSkipCurionBundleChildProduct($erpProductId)) {
                LogHelper::info('Skipping Curion bundle child — parent-only bundle sync is enabled', [
                    'wws_product_id' => $erpProductId,
                ]);
                return null;
            }

            // Use pre-fetched data when available; only call getProduct for standalone syncs.
            if ($wwsProductData !== null) {
                $wwsProduct = $wwsProductData;
            } else {
                $wwsProduct = $this->erpProvider->getProduct($erpProductId);
            }

            if (!$wwsProduct) {
                LogHelper::error('Product not found in ERP', [
                    'erp_product_id' => $erpProductId,
                ]);
                throw new SyncException("Product not found in ERP: {$erpProductId}");
            }

            // Convert to core model
            $coreProduct = $this->wwsAdapter->toCoreModel($wwsProduct);
            
            if (empty($coreProduct->sku)) {
                // Not a sync failure — this is a data quality issue in WWS.
                // Log as warning and return null so batch syncs count it as skipped.
                LogHelper::warning('Product has no SKU — skipping', [
                    'erp_product_id' => $erpProductId,
                    'name'           => $wwsProduct['name']['value']['D'] ?? '(no name)',
                ]);
                return null;
            }

            // ── Step 1: Check product_mappings ───────────────────────────────────
            // Look up by WWS product ID first (most stable key — won't change if SKU changes),
            // then fall back to SKU. Using the mapping avoids Shopify's GraphQL search-index
            // lag that causes false-nulls and is the #1 source of duplicate products.
            $existingShopifyProduct = null;

            $mapping = $this->productMappingRepository->findByWwsProductId($erpProductId)
                    ?? $this->productMappingRepository->findBySku($coreProduct->sku);

            if ($mapping && !empty($mapping['shopify_product_id'])) {
                LogHelper::debug('Mapping found — fetching Shopify product by ID', [
                    'sku'               => $coreProduct->sku,
                    'wws_product_id'    => $erpProductId,
                    'shopify_product_id' => $mapping['shopify_product_id'],
                ]);
                $existingShopifyProduct = $this->ecommerceProvider->getProduct($mapping['shopify_product_id']);

                if (!$existingShopifyProduct) {
                    // Mapping is stale — Shopify product was deleted or its ID changed.
                    LogHelper::warning('Mapping found but Shopify product no longer exists — purging stale mapping', [
                        'sku'               => $coreProduct->sku,
                        'shopify_product_id' => $mapping['shopify_product_id'],
                    ]);
                    $this->productMappingRepository->deleteByShopifyProductId($mapping['shopify_product_id']);
                }
            }

            // ── Step 2: No mapping (or stale) — search Shopify by SKU ───────────
            // GraphQL exact-quote search: sku:"VALUE" avoids prefix matches and
            // keeps first:25 results so edge cases with many similar SKUs are covered.
            // NOTE: even with exact search, Shopify's index can lag by a few seconds
            // for very recently created products. The mapping (Step 1) is the safety net.
            if (!$existingShopifyProduct) {
                LogHelper::debug('No valid mapping — searching Shopify by SKU', [
                    'sku' => $coreProduct->sku,
                ]);
                $existingShopifyProduct = $this->ecommerceProvider->getProductBySku($coreProduct->sku);

                if ($existingShopifyProduct) {
                    // Found in Shopify without a mapping — save it now so all future
                    // syncs skip this GraphQL search entirely.
                    $matchingVariantId = null;
                    foreach (($existingShopifyProduct['variants'] ?? []) as $v) {
                        if (($v['sku'] ?? '') === $coreProduct->sku) {
                            $matchingVariantId = $v['id'];
                            break;
                        }
                    }
                    $this->productMappingRepository->save(
                        $erpProductId,
                        $coreProduct->sku,
                        $existingShopifyProduct['id'],
                        $matchingVariantId
                    );
                    LogHelper::info('Product found by SKU search — mapping saved', [
                        'sku'               => $coreProduct->sku,
                        'shopify_product_id' => $existingShopifyProduct['id'],
                    ]);
                }
            }

            // ── Tag preservation ─────────────────────────────────────────────────
            // Union $coreProduct->tags with the Shopify tags previously captured by
            // ShopifyMappingImportService. Any downstream write path (regular update,
            // forceUpdateInfo, bundle) that ends up sending tags to Shopify will then
            // include the merchant-managed tags, not just WWS-derived ones. Skips when
            // there is no resolved Shopify ID (true new product — nothing to preserve).
            $resolvedShopifyId = $existingShopifyProduct['id']
                ?? ($mapping['shopify_product_id'] ?? null);
            if ($resolvedShopifyId) {
                $storedTags = $this->productMappingRepository->getShopifyTags((string)$resolvedShopifyId);
                if (!empty($storedTags)) {
                    $wwsTags = is_array($coreProduct->tags) ? $coreProduct->tags : [];
                    $coreProduct->tags = array_values(array_unique(array_merge($wwsTags, $storedTags)));
                }
            }

            // ── Step 3a: Bundle products ─────────────────────────────────────────
            // Route to BundleService when stockManagement.id is 101 or 102.
            // This happens *after* the normal SKU lookup so we can pass the
            // existing Shopify product ID (if any) into the bundle create/update.
            if ($coreProduct->isBundle && ($this->config['bundles']['enabled'] ?? false)) {
                return $this->handleBundleSync($erpProductId, $coreProduct, $existingShopifyProduct);
            }

            // ── Step 3b: Regular product — update if found ───────────────────────
            if ($existingShopifyProduct) {
                // Product exists - find the specific variant with matching SKU
                $shopifyProductId = $existingShopifyProduct['id'];
                $matchingVariant = null;
                
                // Find the variant that matches our SKU (product can have multiple variants)
                if (!empty($existingShopifyProduct['variants']) && is_array($existingShopifyProduct['variants'])) {
                    foreach ($existingShopifyProduct['variants'] as $variant) {
                        if (isset($variant['sku']) && $variant['sku'] === $coreProduct->sku) {
                            $matchingVariant = $variant;
                            break;
                        }
                    }
                }
                
                if (!$matchingVariant) {
                    LogHelper::error('Variant not found in Shopify product', [
                        'sku' => $coreProduct->sku,
                        'shopify_product_id' => $shopifyProductId,
                    ]);
                    throw new SyncException("Variant with SKU {$coreProduct->sku} not found in Shopify product {$shopifyProductId}");
                }
                
                // Always fetch full product to get ALL variants (including manually created ones)
                // getProductBySku might not return all variants or all variant fields
                // We need all variants to preserve them when updating
                if (empty($matchingVariant['inventory_item_id']) || count($existingShopifyProduct['variants'] ?? []) <= 1) {
                    LogHelper::debug('Fetching full product to get all variants and complete variant data');
                    $fullShopifyProduct = $this->ecommerceProvider->getProduct($shopifyProductId);
                    if ($fullShopifyProduct && !empty($fullShopifyProduct['variants'])) {
                        // Update matching variant with full data
                        foreach ($fullShopifyProduct['variants'] as $variant) {
                            if (isset($variant['id']) && $variant['id'] == $matchingVariant['id']) {
                                $matchingVariant = $variant; // Update with full variant data
                                break;
                            }
                        }
                    }
                } else {
                    // Use the product we already have, but ensure we have all variants
                    $fullShopifyProduct = $existingShopifyProduct;
                }
                
                // Final safety check: ensure we have the full product with all variants
                if (empty($fullShopifyProduct['variants']) || count($fullShopifyProduct['variants']) < count($existingShopifyProduct['variants'] ?? [])) {
                    LogHelper::debug('Fetching full product to ensure we have all variants');
                    $fullShopifyProduct = $this->ecommerceProvider->getProduct($shopifyProductId);
                }

                // ── Refresh mapping immediately once we have confirmed IDs ────────
                // Do this before the update so the mapping stays current even if the
                // update fails or is skipped (skip_update metafield, exception, etc.).
                $this->productMappingRepository->save(
                    $erpProductId,
                    $coreProduct->sku,
                    $shopifyProductId,
                    $matchingVariant['id'] ?? null
                );

                // Get metafields
                $skipUpdate = $this->getBoolProductMetafield($shopifyProductId, $this->metafieldSkipUpdateKey);
                $forceUpdateInfo = $this->getBoolProductMetafield($shopifyProductId, $this->metafieldForceUpdateKey);

                // Skip if metafield says so
                if ($skipUpdate) {
                    $this->logger->logSync(
                        'product_sync',
                        'product',
                        $erpProductId,
                        $shopifyProductId,
                        'skipped',
                        ['reason' => 'skip_update metafield is true'],
                        null,
                        null
                    );
                    return $fullShopifyProduct;
                }

                // IMPORTANT: Preserve all existing variants when updating
                // Shopify will replace ALL variants if we only send one variant
                // So we need to include all existing variants and only update the matching one
                $allVariants = $fullShopifyProduct['variants'] ?? [];
                $updatedVariants = [];
                
                // forceUpdateInfo pushes title, description, weight etc.
                // Those fields (longDescription, shortDescription, weight) are NOT present
                // in productSearch results — fetch the full detail now, lazily, only when needed.
                if ($forceUpdateInfo && $wwsProductData !== null) {
                    $fullWwsDetail = $this->erpProvider->getProduct($erpProductId);
                    if ($fullWwsDetail) {
                        $coreProduct = $this->wwsAdapter->toCoreModel($fullWwsDetail);
                        LogHelper::debug('forceUpdateInfo: fetched full WWS product details', [
                            'erp_product_id' => $erpProductId,
                        ]);
                    }
                }

                foreach ($allVariants as $variant) {
                    if (isset($variant['id']) && $variant['id'] == $matchingVariant['id']) {
                        // This is the variant we want to update
                        if ($forceUpdateInfo) {
                            // Full update - merge with existing variant data
                            $updatedVariant = $this->shopifyAdapter->fromCoreModel($coreProduct, $variant);
                            $updatedVariants[] = $updatedVariant['variants'][0] ?? $variant;
                        } else {
                            // Limited update (price + inventory only)
                            $updatedVariant = $this->shopifyAdapter->fromCoreModelLimited($coreProduct, $variant);
                            // Merge with existing variant to preserve all other fields
                            $mergedVariant = array_merge($variant, $updatedVariant['variants'][0] ?? []);
                            $updatedVariants[] = $mergedVariant;
                        }
                    } else {
                        // Preserve other variants as-is (don't modify manually created variants)
                        $updatedVariants[] = $variant;
                    }
                }
                
                // Build update payload with all variants
                $updateData = [
                    'variants' => $updatedVariants,
                ];

                // Debug: Log the update payload
                LogHelper::debug('Updating product variant', [
                    'shopify_product_id' => $shopifyProductId,
                    'variant_id' => $matchingVariant['id'],
                    'update_data' => $updateData,
                    'preserved_variants' => count($allVariants) - 1,
                ]);
                
                // Update product price (and all variant fields when forceUpdateInfo is set)
                $updatedProduct = $this->ecommerceProvider->updateProduct($shopifyProductId, $updateData);

                // ── Inventory update ─────────────────────────────────────────────
                // inventory_management is set to 'shopify' by fromCoreModelLimited
                // in the product update above, so tracking is always enabled before
                // this call. The 422 fallback in updateInventoryViaInventoryApi
                // handles any edge cases where Shopify still rejects the request.
                if (!$forceUpdateInfo) {
                    $inventoryItemId = $matchingVariant['inventory_item_id'] ?? null;

                    if ($inventoryItemId) {
                        $this->updateInventoryViaInventoryApi($inventoryItemId, $coreProduct->inventoryQty);
                    } else {
                        LogHelper::warning('Cannot update inventory — inventory_item_id not found', [
                            'sku'        => $coreProduct->sku,
                            'variant_id' => $matchingVariant['id'] ?? null,
                        ]);
                    }
                }

                LogHelper::info('Product price updated', [
                    'sku'                => $coreProduct->sku,
                    'shopify_product_id' => $shopifyProductId,
                    'new_price'          => $coreProduct->price,
                    'inventory_qty'      => $coreProduct->inventoryQty,
                ]);

                $this->logger->logSync(
                    'product_sync',
                    'product',
                    $erpProductId,
                    $shopifyProductId,
                    'success',
                    $updateData,
                    $updatedProduct,
                    null
                );

                return $updatedProduct;
            } else {
                // ── Duplicate guard ──────────────────────────────────────────────
                // Both the mapping lookup and the GraphQL search returned null, but
                // Shopify's index can lag for recently-created products. Do one final
                // direct check before creating to prevent accidental duplicates.
                $doubleCheck = $this->ecommerceProvider->getProductBySku($coreProduct->sku);
                if ($doubleCheck) {
                    LogHelper::warning('Duplicate guard triggered: product exists in Shopify despite earlier search miss — updating instead of creating', [
                        'sku'               => $coreProduct->sku,
                        'shopify_product_id' => $doubleCheck['id'],
                    ]);
                    // Save the mapping so this doesn't happen again, then update.
                    $guardVariantId = null;
                    foreach (($doubleCheck['variants'] ?? []) as $v) {
                        if (($v['sku'] ?? '') === $coreProduct->sku) {
                            $guardVariantId = $v['id'];
                            break;
                        }
                    }
                    $this->productMappingRepository->save(
                        $erpProductId,
                        $coreProduct->sku,
                        $doubleCheck['id'],
                        $guardVariantId
                    );
                    // Re-run this product now that the mapping is saved — will go through update path.
                    return $this->syncProduct($erpProductId, $wwsProductData);
                }

                // Product doesn't exist in Shopify - create new
                // $wwsProduct is already in scope (fetched or passed in above) — reuse it.
                $fullCoreProduct = $this->wwsAdapter->toCoreModel($wwsProduct);
                
                // Set status to draft for new products
                $fullCoreProduct->status = 'draft';
                
                // Add tags for new products: ERP-{SOURCE} and API_PRODUCTS
                $erpProviderName = strtoupper($this->erpProvider->getName());
                $syncTags = [
                    "ERP-{$erpProviderName}",
                    "API_PRODUCTS"
                ];
                
                // Merge with existing tags if any
                if (is_array($fullCoreProduct->tags) && !empty($fullCoreProduct->tags)) {
                    $fullCoreProduct->tags = array_merge($fullCoreProduct->tags, $syncTags);
                } else {
                    $fullCoreProduct->tags = $syncTags;
                }
                
                // Convert to Shopify format
                $shopifyData = $this->shopifyAdapter->fromCoreModel($fullCoreProduct);
                
                // Create in Shopify
                $newProduct = $this->ecommerceProvider->createProduct($shopifyData);
                
                if (!$newProduct || empty($newProduct['id'])) {
                    LogHelper::error('Failed to create product in Shopify', [
                        'erp_product_id' => $erpProductId,
                        'sku' => $coreProduct->sku ?? null,
                    ]);
                    throw new SyncException("Failed to create product in Shopify");
                }

                $shopifyProductId = $newProduct['id'];
                $shopifyVariantId = $newProduct['variants'][0]['id'] ?? null;

                // Set default metafields
                $this->ensureDefaultMetafields($shopifyProductId);

                // Save mapping for tracking/logging purposes only
                $this->productMappingRepository->save(
                    $erpProductId,
                    $coreProduct->sku,
                    $shopifyProductId,
                    $shopifyVariantId
                );

                $this->logger->logSync(
                    'product_sync',
                    'product',
                    $erpProductId,
                    $shopifyProductId,
                    'success',
                    $shopifyData,
                    $newProduct,
                    null
                );

                return $newProduct;
            }
        } catch (\Exception $e) {
            // Log to DB for tracking — do NOT re-log to file here since the
            // originating method (handleBundleSync or lower) already wrote to error.log.
            $this->logger->logSync(
                'product_sync',
                'product',
                $erpProductId,
                null,
                'failed',
                null,
                null,
                $e->getMessage()
            );
            throw new SyncException("Failed to sync product {$erpProductId}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Ensure every bundle child product has a row in product_mappings.
     *
     * Called before BundleService so that products added manually to Shopify
     * (never synced through this system) are discovered automatically:
     *   1. Fetch child SKU from WWS using the wws_product_id from partsList.
     *   2. Search Shopify by that SKU.
     *   3. Save the mapping so resolveComponents can find the variant GID.
     *
     * Failures are logged as warnings — BundleNotReadyException will fire
     * afterwards for any children that genuinely don't exist in Shopify.
     *
     * @param array $bundleComponents  [['wws_product_id' => int, 'quantity' => int], ...]
     */
    private function prewarmBundleComponentMappings(array $bundleComponents): void
    {
        foreach ($bundleComponents as $part) {
            $wwsId = $part['wws_product_id'] ?? null;
            if (!$wwsId) {
                continue;
            }

            // Fast path: already in product_mappings with a variant ID
            $existing = $this->productMappingRepository->findByWwsProductId($wwsId);
            if ($existing && !empty($existing['shopify_variant_id'])) {
                continue;
            }

            // Slow path: ask WWS for the child's SKU, then look it up in Shopify
            try {
                $wwsChild = $this->erpProvider->getProduct($wwsId);
                $childSku = trim($wwsChild['sku'] ?? '');

                if (empty($childSku)) {
                    LogHelper::warning('Bundle child has no SKU in WWS — cannot auto-map', [
                        'wws_product_id' => $wwsId,
                    ]);
                    continue;
                }

                $shopifyProduct = $this->ecommerceProvider->getProductBySku($childSku);

                if (!$shopifyProduct) {
                    LogHelper::warning('Bundle child not found in Shopify by SKU — add it to Shopify first', [
                        'wws_product_id' => $wwsId,
                        'sku'            => $childSku,
                    ]);
                    continue;
                }

                // Find the specific variant whose SKU matches
                $variantId = null;
                foreach (($shopifyProduct['variants'] ?? []) as $v) {
                    if (($v['sku'] ?? '') === $childSku) {
                        $variantId = $v['id'];
                        break;
                    }
                }

                $this->productMappingRepository->save(
                    $wwsId,
                    $childSku,
                    $shopifyProduct['id'],
                    $variantId
                );

                LogHelper::info('Bundle child auto-mapped from Shopify', [
                    'wws_product_id'     => $wwsId,
                    'sku'                => $childSku,
                    'shopify_product_id' => $shopifyProduct['id'],
                    'shopify_variant_id' => $variantId,
                ]);

            } catch (\Exception $e) {
                LogHelper::warning('Could not auto-map bundle child — skipping', [
                    'wws_product_id' => $wwsId,
                    'error'          => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Sync the price (and inventory where applicable) for each bundle component product.
     *
     * Called after a successful bundle sync so that child item prices are always
     * kept in sync with WWS — even when running --bundles-only mode.
     *
     * Components that are used in multiple bundles are skipped on subsequent calls
     * (tracked via $syncedComponentWwsIds) to avoid redundant API round-trips.
     *
     * @param array  $bundleComponents  Each entry: ['wws_product_id' => ..., 'quantity' => ...]
     * @param string $bundleSku         Parent bundle SKU (for logging context only)
     */
    private function syncComponentProducts(array $bundleComponents, string $bundleSku): void
    {
        foreach ($bundleComponents as $component) {
            $wwsId = $component['wws_product_id'] ?? null;
            if (!$wwsId) {
                continue;
            }

            // Skip if already synced this run (shared components appear in multiple bundles)
            if (in_array($wwsId, $this->syncedComponentWwsIds, true)) {
                LogHelper::debug('Bundle component already synced this run — skipping', [
                    'wws_product_id' => $wwsId,
                    'bundle_sku'     => $bundleSku,
                ]);
                continue;
            }

            $this->syncedComponentWwsIds[] = $wwsId;

            try {
                LogHelper::debug('Syncing bundle component price', [
                    'wws_product_id' => $wwsId,
                    'bundle_sku'     => $bundleSku,
                ]);
                $this->syncProduct($wwsId);
            } catch (BundleNotReadyException $e) {
                // Component is itself a bundle — skip rather than error
                LogHelper::debug('Bundle component is itself a bundle — skipping nested sync', [
                    'wws_product_id' => $wwsId,
                ]);
            } catch (\Exception $e) {
                // Log but never fail the parent bundle sync because a component sync failed
                LogHelper::warning('Bundle component price sync failed — skipping', [
                    'wws_product_id' => $wwsId,
                    'bundle_sku'     => $bundleSku,
                    'error'          => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Handle sync for a WWS bundle product (stockManagement.id 101 or 102).
     *
     * Delegates to BundleService which calls the Shopify Bundles app GraphQL API.
     * Inventory is NOT synced — Shopify manages bundle stock automatically via its components.
     *
     * @param mixed         $erpProductId           WWS product ID
     * @param Product       $coreProduct            Already-converted core model (isBundle=true)
     * @param array|null    $existingShopifyProduct Existing Shopify product (null = new)
     * @return array|null
     */
    private function handleBundleSync($erpProductId, Product $coreProduct, ?array $existingShopifyProduct): ?array
    {
        $existingShopifyProductId = $existingShopifyProduct['id'] ?? null;

        LogHelper::info('Bundle product detected — routing to BundleService', [
            'erp_product_id'      => $erpProductId,
            'sku'                 => $coreProduct->sku,
            'components'          => count($coreProduct->bundleComponents),
            'existing_shopify_id' => $existingShopifyProductId,
            'action'              => $existingShopifyProductId ? 'update' : 'create',
        ]);

        // Before calling BundleService, ensure all child products have entries in
        // product_mappings. Products added manually to Shopify (not synced through
        // this system) will be discovered here by fetching their SKU from WWS and
        // searching Shopify — the mapping is then saved for all future syncs.
        $this->prewarmBundleComponentMappings($coreProduct->bundleComponents);

        try {
            $bundleService = new BundleService();
            // Pass the full Shopify product so syncBundle can read the title and
            // detect bundle status without an extra API round-trip.
            $result = $bundleService->syncBundle($coreProduct, $existingShopifyProduct);


            $shopifyProductId = $result['shopify_product_id'] ?? $existingShopifyProductId;
            $shopifyVariantId = $result['shopify_variant_id'] ?? null;

            // Save/refresh mapping so the next sync uses the fast direct-ID path
            if ($shopifyProductId) {
                $this->productMappingRepository->save(
                    $erpProductId,
                    $coreProduct->sku,
                    $shopifyProductId,
                    $shopifyVariantId
                );
            }

            $this->logger->logSync(
                'product_sync',
                'bundle',
                $erpProductId,
                $shopifyProductId,
                'success',
                ['components' => $coreProduct->bundleComponents],
                $result,
                null
            );

            if ($result['components_missing'] > 0) {
                LogHelper::warning('Bundle synced with unresolved components — re-run after child products are synced', [
                    'bundle_sku'          => $coreProduct->sku,
                    'shopify_product_id'  => $shopifyProductId,
                    'components_missing'  => $result['components_missing'],
                ]);
            }

            // Optionally sync each partsList child from WWS (disabled by default for Curion bundles).
            // Shopify-native bundles never hit this path — they use updateBundlePriceOnly only.
            if ($this->config['bundles']['sync_child_products'] ?? false) {
                // Bundle inventory is managed by Shopify automatically, but the individual
                // child product prices can be kept in sync with WWS when explicitly enabled.
                $this->syncComponentProducts($coreProduct->bundleComponents, $coreProduct->sku);
            } else {
                LogHelper::debug('Curion bundle child sync skipped — sync_child_products is disabled', [
                    'bundle_sku'     => $coreProduct->sku,
                    'component_count' => count($coreProduct->bundleComponents),
                ]);
            }

            return $result;

        } catch (BundleNotReadyException $e) {
            // All child products were checked in Shopify but none found.
            // This means the children genuinely don't exist in Shopify yet.
            $missingWwsIds = array_column($coreProduct->bundleComponents, 'wws_product_id');
            LogHelper::warning('Bundle skipped — child products not found in Shopify', [
                'erp_product_id'          => $erpProductId,
                'sku'                     => $coreProduct->sku,
                'missing_child_wws_ids'   => $missingWwsIds,
                'hint'                    => 'Ensure child products already exist in Shopify (manual or prior sync). Mapping-only lookup runs via prewarmBundleComponentMappings.',
            ]);
            throw $e; // re-throw so syncAllProducts counts it as skipped, not errored
        } catch (\Exception $e) {
            // logSync with status=failed already writes to error.log via the internal appLogger call
            $this->logger->logSync(
                'product_sync',
                'bundle',
                $erpProductId,
                $existingShopifyProductId,
                'failed',
                ['components' => $coreProduct->bundleComponents],
                null,
                $e->getMessage()
            );
            throw new SyncException("Bundle sync failed for {$erpProductId}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get boolean product metafield
     * @param mixed $shopifyProductId
     * @param string $key
     * @return bool
     */
    private function getBoolProductMetafield($shopifyProductId, string $key): bool
    {
        try {
            if ($this->ecommerceProvider->getName() === 'shopify') {
                $metafieldService = $this->ecommerceProvider->getMetafieldService();
                $metafield = $metafieldService->findProductMetafield(
                    $shopifyProductId,
                    $this->metafieldNamespace,
                    $key
                );
                
                if ($metafield && isset($metafield['value'])) {
                    $val = $metafield['value'];
                    if (is_bool($val)) {
                        return $val;
                    }
                    if (is_string($val)) {
                        $v = strtolower(trim($val));
                        if ($v === 'true' || $v === '1' || $v === 'yes') return true;
                        if ($v === 'false' || $v === '0' || $v === 'no') return false;
                    }
                    return filter_var($val, FILTER_VALIDATE_BOOLEAN);
                }
            }
        } catch (\Exception $e) {
            // If metafield doesn't exist or error, return false (default)
            LogHelper::warning('Error getting metafield', [
                'product_id' => $shopifyProductId,
                'metafield_key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
        
        return false;
    }

    /**
     * Ensure default metafields exist
     * @param mixed $shopifyProductId
     */
    private function ensureDefaultMetafields($shopifyProductId): void
    {
        try {
            if ($this->ecommerceProvider->getName() === 'shopify') {
                $metafieldService = $this->ecommerceProvider->getMetafieldService();
                
                // Set skip_update = false
                $metafieldService->upsertProductMetafield(
                    $shopifyProductId,
                    $this->metafieldNamespace,
                    $this->metafieldSkipUpdateKey,
                    'boolean',
                    'false'
                );
                
                // Set force_update_info = false
                $metafieldService->upsertProductMetafield(
                    $shopifyProductId,
                    $this->metafieldNamespace,
                    $this->metafieldForceUpdateKey,
                    'boolean',
                    'false'
                );
            }
        } catch (\Exception $e) {
            LogHelper::warning('Error setting default metafields', [
                'product_id' => $shopifyProductId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update inventory using Shopify Inventory API
     * Required for stores with inventory locations
     * The Product API doesn't update inventory when locations are enabled
     * @param string|int $inventoryItemId
     * @param int $quantity
     */
    private function updateInventoryViaInventoryApi($inventoryItemId, $quantity): void
    {
        if ($this->ecommerceProvider->getName() !== 'shopify') {
            return;
        }

        try {
            // Resolve location ID once per sync run and cache it.
            // Fetching locations requires the read_locations scope on the access token.
            if (!$this->locationsFetched) {
                $this->locationsFetched = true;
                try {
                    $inventoryService = new \App\Api\Shopify\InventoryService();
                    $locations = $inventoryService->getLocations();
                    $this->cachedLocationId = $locations[0]['id'] ?? null;

                    if (!$this->cachedLocationId) {
                        LogHelper::warning('No inventory locations found — inventory sync disabled for this run');
                    } else {
                        LogHelper::info('Inventory location resolved', ['location_id' => $this->cachedLocationId]);
                    }
                } catch (\App\Exceptions\ShopifyException $e) {
                    if ($e->getStatusCode() === 403) {
                        LogHelper::error(
                            'Inventory sync disabled — Shopify access token is missing the ' .
                            '"read_locations" scope. Grant this scope in the Shopify app settings ' .
                            'and re-install the app.',
                            ['hint' => 'Admin → Apps → your app → API credentials → scopes']
                        );
                    } else {
                        LogHelper::error('Failed to fetch Shopify locations', ['error' => $e->getMessage()]);
                    }
                    // cachedLocationId stays null — all inventory updates silently skipped this run
                }
            }

            if (!$this->cachedLocationId) {
                return; // location unknown — skip silently (error already logged above)
            }

            $inventoryService = new \App\Api\Shopify\InventoryService();
            $result = $inventoryService->setInventoryLevel($inventoryItemId, $this->cachedLocationId, $quantity);

            if ($result) {
                LogHelper::debug('Inventory updated', [
                    'inventory_item_id' => $inventoryItemId,
                    'available'         => $result['available'] ?? $quantity,
                ]);
            } else {
                LogHelper::warning('Inventory API returned no result', [
                    'inventory_item_id' => $inventoryItemId,
                    'location_id'       => $this->cachedLocationId,
                ]);
            }
        } catch (\App\Exceptions\ShopifyException $e) {
            // 422 "Inventory item does not have inventory tracking enabled" is not a
            // sync failure — the product simply has tracking turned off in Shopify.
            // Downgrade to a debug log so it doesn't pollute error.log.
            if ($e->getStatusCode() === 422) {
                LogHelper::debug('Inventory update skipped — tracking not enabled for this item', [
                    'inventory_item_id' => $inventoryItemId,
                ]);
                return;
            }
            LogHelper::error('Failed to update inventory via Inventory API', [
                'inventory_item_id' => $inventoryItemId,
                'quantity'          => $quantity,
                'error'             => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            LogHelper::error('Failed to update inventory via Inventory API', [
                'inventory_item_id' => $inventoryItemId,
                'quantity'          => $quantity,
                'error'             => $e->getMessage(),
            ]);
            // Don't throw — inventory failure must not abort the product sync
        }
    }
}

