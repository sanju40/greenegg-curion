<?php

namespace App\Api\Shopify;

use App\Core\Models\Product;
use App\Database\Repository\ProductMappingRepository;
use App\Exceptions\BundleNotReadyException;
use App\Utils\LogHelper;

/**
 * Shopify Bundle Service
 *
 * Four possible states for a bundle product:
 *
 *  A. No Shopify product yet               → productBundleCreate + stamp BUNDLE_API_SYNC tag
 *  B1. Existing product, BUNDLE_API_SYNC tag → productBundleUpdate (full: components + price)
 *  B2. Existing product, no tag, has bundleComponents → price-only REST update
 *      (created through Shopify Bundles app UI; Shopify treats as app-owned / not editable)
 *  C. Existing regular product (no components) → archive, then productBundleCreate
 *
 * Requires the "Shopify Bundles" app installed on the store.
 */
class BundleService
{
    private Client $client;
    private ProductMappingRepository $mappingRepo;
    private ProductService $productService;

    public function __construct()
    {
        $this->client         = new Client();
        $this->mappingRepo    = new ProductMappingRepository();
        $this->productService = new ProductService($this->client);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Create or update a bundle product in Shopify.
     *
     * Routing is determined by detectBundleType():
     *  - No existing product     → createBundle (productBundleCreate + BUNDLE_API_SYNC tag)
     *  - BUNDLE_API_SYNC tag     → updateBundle (full: components + price)
     *  - No tag + has components → updateBundlePriceOnly (Shopify app bundle: price only)
     *  - No tag + no components  → convertRegularToBundle (archive + create)
     *
     * @param Product    $product               Core product (isBundle = true)
     * @param array|null $existingShopifyProduct Full Shopify product array from the store (null = brand new)
     * @return array  shopify_product_id, shopify_variant_id, bundle_price, components_resolved, components_missing
     * @throws BundleNotReadyException when no child products are mapped yet (dependency ordering issue)
     * @throws \RuntimeException for real bundle API failures
     */
    public function syncBundle(Product $product, ?array $existingShopifyProduct = null): array
    {
        // Resolve component GIDs + quantities.
        // Pass 1 looks up product_mappings; Pass 2 fetches option IDs/values from Shopify
        // so we can send them back verbatim in optionSelections.
        $totalComponents = count($product->bundleComponents);
        [$components, $missing, $variantGidsWithQty] = $this->resolveComponents($product->bundleComponents);

        if (empty($components)) {
            throw new BundleNotReadyException(
                "Bundle '{$product->sku}' has {$totalComponents} child product(s) but none could be found in Shopify. " .
                "Make sure all child products exist in Shopify (check warning.log for which SKUs are missing), " .
                "then re-run the bundle sync."
            );
        }

        if ($missing > 0) {
            LogHelper::warning('Bundle: some components not mapped yet', [
                'bundle_sku'          => $product->sku,
                'components_resolved' => count($components),
                'components_missing'  => $missing,
            ]);
        }

        $pricing      = $this->resolveBundlePrice($product, $variantGidsWithQty);
        $bundlePrice  = $pricing['price'];
        $compareAtPrice = $pricing['compare_at'];

        // ── Route to the correct flow ────────────────────────────────────────
        $existingShopifyProductId = $existingShopifyProduct['id'] ?? null;

        // Extract unique child product GIDs from resolved components — used to update the
        // custom.bundle_child_items metafield after every sync regardless of the route taken.
        $childProductGids = array_values(array_unique(array_column($components, 'productId')));

        if (!$existingShopifyProductId) {
            // State A: brand new — create via productBundleCreate + stamp BUNDLE_API_SYNC tag
            $result = $this->createBundle($product, $components, $missing, $bundlePrice, $compareAtPrice);
        } elseif ($bundleType = $this->detectBundleType($existingShopifyProduct)) {
            if ($bundleType === 'api_sync') {
                // State B1: our API created this bundle — full update (components + price)
                $result = $this->updateBundle($product, $existingShopifyProduct, $components, $missing, $bundlePrice, $compareAtPrice);
            } else {
                // State B2: Shopify Bundles app created this bundle — price only
                $result = $this->updateBundlePriceOnly($product, $existingShopifyProduct, $bundlePrice, $compareAtPrice);
            }
        } else {
            // State C: existing regular product — archive it and create a fresh bundle
            $result = $this->convertRegularToBundle($product, $existingShopifyProductId, $components, $missing, $bundlePrice, $compareAtPrice);
        }

        // Update the custom.bundle_child_items metafield on the bundle for all paths.
        // This runs regardless of create/update/price-only so the list is always current.
        $bundleProductId = $result['shopify_product_id'] ?? null;
        if ($bundleProductId && !empty($childProductGids)) {
            $this->updateChildItemsMetafield($bundleProductId, $childProductGids, $product->sku);
        }

        return $result;
    }

    /**
     * After bundle create/update via GraphQL, apply REST metadata and publish to all channels
     * so the product shows in the Shopify Bundles app admin (not only on the storefront).
     *
     * productBundleCreate defaults to published_scope=web; without published_scope=global and
     * channel publish, bundles can be missing from the Bundles app UI.
     *
     * syncBundle() already runs this after create/update — call this only when you perform
     * productBundleCreate/productBundleUpdate outside BundleService (e.g. custom CLI).
     *
     * @param string $shopifyProductId Legacy numeric product id (REST)
     */
    public function ensureBundleAppVisibility(Product $product, string $shopifyProductId): void
    {
        $this->applyBundleProductMetadata($shopifyProductId, $product);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Detection
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Determine how an existing Shopify product should be handled during a bundle sync.
     *
     * Returns one of three values:
     *
     *  'api_sync'   — Product has the BUNDLE_API_SYNC tag. It was created by our sync
     *                 process via productBundleCreate. Do a FULL update: components,
     *                 quantities, and price.
     *
     *  'app_bundle' — No BUNDLE_API_SYNC tag but the product already has bundleComponents
     *                 in Shopify. It was created through the Shopify Bundles app UI.
     *                 Shopify treats this as app-owned, so we cannot update components.
     *                 Do a PRICE ONLY update.
     *
     *  false        — No tag and no bundleComponents. It is a regular Shopify product.
     *                 Archive it and create a fresh bundle via productBundleCreate,
     *                 which will stamp the BUNDLE_API_SYNC tag for all future syncs.
     *
     * @return 'api_sync'|'app_bundle'|false
     */
    private function detectBundleType(array $shopifyProduct): string|false
    {
        $productId = $shopifyProduct['id'] ?? null;
        $tags      = array_map('trim', explode(',', $shopifyProduct['tags'] ?? ''));

        // Fast path — BUNDLE_API_SYNC tag means we created it; no extra API call needed.
        if (in_array('BUNDLE_API_SYNC', $tags, true)) {
            LogHelper::debug('Bundle detection: BUNDLE_API_SYNC tag found — full update', [
                'shopify_product_id' => $productId,
                'shopify_title'      => $shopifyProduct['title'] ?? null,
            ]);
            return 'api_sync';
        }

        // Safety guard — BUNDLE or CURIONBUNDLE tag (without BUNDLE_API_SYNC) means the
        // product is already a bundle, either created by the Shopify Bundles app UI or by
        // a previous sync run. Never archive+convert these; treat as price-only.
        if (in_array('BUNDLE', $tags, true) || in_array('CURIONBUNDLE', $tags, true)) {
            LogHelper::debug('Bundle detection: BUNDLE/CURIONBUNDLE tag found (no BUNDLE_API_SYNC) — Shopify app bundle, price-only', [
                'shopify_product_id' => $productId,
                'shopify_title'      => $shopifyProduct['title'] ?? null,
            ]);
            return 'app_bundle';
        }

        // Slow path — no bundle tags at all. Ask Shopify whether the product has
        // bundleComponents. A product created through the Bundles app UI may not have
        // tags yet if we haven't written them in a previous sync cycle.
        if (!$productId) {
            return false;
        }

        try {
            $query = <<<'GQL'
            query BundleCheck($id: ID!) {
                product(id: $id) {
                    bundleComponents(first: 1) {
                        edges { node { quantity } }
                    }
                }
            }
            GQL;

            $result = $this->client->graphql($query, [
                'id' => 'gid://shopify/Product/' . $productId,
            ]);
            $edges  = $result['data']['product']['bundleComponents']['edges'] ?? [];

            if (!empty($edges)) {
                LogHelper::debug('Bundle detection: no tag but has bundleComponents — Shopify app bundle, price-only', [
                    'shopify_product_id' => $productId,
                    'shopify_title'      => $shopifyProduct['title'] ?? null,
                ]);
                return 'app_bundle';
            }

            LogHelper::debug('Bundle detection: no tag, no bundleComponents — regular product, will archive+create', [
                'shopify_product_id' => $productId,
                'shopify_title'      => $shopifyProduct['title'] ?? null,
            ]);
            return false;
        } catch (\Exception $e) {
            // On GraphQL failure we cannot confirm the product is NOT a bundle.
            // Returning false would archive+recreate it, which is destructive.
            // Safer fallback: treat as app_bundle (price-only) and log a warning.
            LogHelper::warning('Bundle detection GraphQL check failed — defaulting to price-only to avoid destructive archive', [
                'shopify_product_id' => $productId,
                'error'              => $e->getMessage(),
            ]);
            return 'app_bundle';
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Conversion: regular product → bundle
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Convert an existing regular Shopify product to a bundle.
     *
     * Steps:
     *  1. Archive the old regular product (not delete — data is preserved)
     *  2. Create a new bundle product via productBundleCreate
     *  3. The caller (ProductSyncService) updates product_mappings to the new ID
     *
     * Why archive instead of update?
     *  Shopify's Bundles app does not allow converting a regular product to a bundle
     *  in-place via productBundleUpdate — it only operates on products that were
     *  already created as bundles. Archiving keeps the historical record and
     *  removes the old SKU from the active storefront.
     */
    private function convertRegularToBundle(
        Product $product,
        string  $oldProductId,
        array   $components,
        int     $missing,
        float   $bundlePrice,
        ?float  $compareAtPrice = null
    ): array {
        LogHelper::warning('Product exists as regular — archiving and recreating as bundle', [
            'bundle_sku'       => $product->sku,
            'old_product_id'   => $oldProductId,
        ]);

        // Archive the old product (status = archived)
        try {
            $this->client->put("products/{$oldProductId}.json", [
                'product' => ['id' => $oldProductId, 'status' => 'archived'],
            ]);
            LogHelper::info('Old regular product archived', ['shopify_product_id' => $oldProductId]);
        } catch (\Exception $e) {
            // Non-fatal — log and continue. The bundle will still be created.
            LogHelper::warning('Failed to archive old regular product (continuing)', [
                'shopify_product_id' => $oldProductId,
                'error'              => $e->getMessage(),
            ]);
        }

        // Create the new bundle from scratch
        return $this->createBundle($product, $components, $missing, $bundlePrice, $compareAtPrice);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Component resolution
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Map partsList entries → Shopify GIDs via product_mappings.
     *
     * Two-pass approach:
     *  Pass 1 — look up each child in product_mappings, collect product/variant GIDs.
     *  Pass 2 — batch-fetch every child product's option IDs in ONE GraphQL query, then
     *           build the correct optionSelections (required by productBundleCreate/Update).
     *
     * Returns [$components, $missingCount, $variantGidsWithQty]
     */
    private function resolveComponents(array $bundleComponents): array
    {
        $resolved           = [];   // ['qty', 'productGid', 'variantGid']
        $variantGidsWithQty = [];
        $missing            = 0;

        // ── Pass 1: resolve mappings ──────────────────────────────────────────
        foreach ($bundleComponents as $part) {
            $wwsId   = $part['wws_product_id'];
            $qty     = max(1, (int)($part['quantity'] ?? 1));
            $mapping = $this->mappingRepo->findByWwsProductId($wwsId);

            if (!$mapping || empty($mapping['shopify_product_id']) || empty($mapping['shopify_variant_id'])) {
                LogHelper::warning('Bundle component not in product_mappings yet — skipping', [
                    'wws_product_id' => $wwsId,
                ]);
                $missing++;
                continue;
            }

            $productGid = 'gid://shopify/Product/' . $mapping['shopify_product_id'];
            $variantGid = 'gid://shopify/ProductVariant/' . $mapping['shopify_variant_id'];

            $resolved[]           = ['qty' => $qty, 'productGid' => $productGid, 'variantGid' => $variantGid];
            $variantGidsWithQty[] = ['gid' => $variantGid, 'quantity' => $qty];
        }

        if (empty($resolved)) {
            return [[], $missing, []];
        }

        // ── Pass 2: batch-fetch option selections for all child products ──────
        $productGids      = array_unique(array_column($resolved, 'productGid'));
        $optionSelectionsMap = $this->fetchComponentOptionSelections($productGids);

        // ── Build final components array ──────────────────────────────────────
        $components = [];
        foreach ($resolved as $item) {
            $optionSelections = $optionSelectionsMap[$item['productGid']] ?? [];
            $components[] = [
                'quantity'         => $item['qty'],
                'productId'        => $item['productGid'],
                'optionSelections' => $optionSelections,
            ];
        }

        return [$components, $missing, $variantGidsWithQty];
    }

    /**
     * Batch-fetch all option IDs and their values for a list of component product GIDs.
     *
     * The Shopify Bundles API requires each component to declare optionSelections in the form:
     *   { "componentOptionId": "gid://shopify/ProductOption/...", "name": "Title", "values": ["Default Title"] }
     *
     * We query product.options[].optionValues to get ALL available values for every option.
     * For simple products (single variant) this will be [{ name: "Title", values: ["Default Title"] }].
     * For products with real variants (Size S/M/L) this would be [{ name: "Size", values: ["S","M","L"] }].
     *
     * Returns map: productGid → [['componentOptionId'=>..., 'name'=>..., 'values'=>[...]], ...]
     */
    private function fetchComponentOptionSelections(array $productGids): array
    {
        if (empty($productGids)) {
            return [];
        }

        // Fetch options and all their available values — no need to look at individual variants.
        $query = <<<'GQL'
        query GetComponentOptions($ids: [ID!]!) {
            nodes(ids: $ids) {
                ... on Product {
                    id
                    options {
                        id
                        name
                        optionValues {
                            name
                        }
                    }
                }
            }
        }
        GQL;

        try {
            $result = $this->client->graphql($query, ['ids' => array_values($productGids)]);
        } catch (\Exception $e) {
            LogHelper::warning('Could not fetch component option selections', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $map = [];

        foreach ($result['data']['nodes'] ?? [] as $node) {
            $productGid = $node['id'] ?? null;
            if (!$productGid) {
                continue;
            }

            $selections = [];
            foreach ($node['options'] ?? [] as $opt) {
                $values = array_column($opt['optionValues'] ?? [], 'name');
                if (empty($values)) {
                    continue;
                }
                $selections[] = [
                    'componentOptionId' => $opt['id'],
                    'name'              => $opt['name'],
                    'values'            => $values,
                ];
            }

            $map[$productGid] = $selections;
        }

        return $map;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Price calculation
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Prefer WWS basePrice as the selling price when present; otherwise use the sum of
     * child variant prices × quantity. When ERP basePrice is used, the calculated sum is
     * returned as compare_at (value if bought separately).
     *
     * @return array{price: float, compare_at: ?float} compare_at is null when not using ERP base or when calculated is 0.
     */
    private function resolveBundlePrice(Product $product, array $variantGidsWithQty): array
    {
        $calculated = $this->calculateBundlePrice($variantGidsWithQty);
        $fromErp    = $product->bundleBasePrice ?? null;

        if ($fromErp !== null && (float) $fromErp > 0) {
            $price = round((float) $fromErp, 2);
            $compareAt = ($calculated > 0) ? round($calculated, 2) : null;
            LogHelper::info('Bundle price from ERP basePrice', [
                'bundle_sku'       => $product->sku,
                'bundle_price'     => $price,
                'compare_at_price' => $compareAt,
                'calculated_sum'   => $calculated,
            ]);
            return ['price' => $price, 'compare_at' => $compareAt];
        }

        LogHelper::info('Bundle price from child items', [
            'bundle_sku'   => $product->sku,
            'bundle_price' => $calculated,
        ]);
        return ['price' => $calculated, 'compare_at' => null];
    }

    /**
     * Fetch all child variant prices in ONE GraphQL query and return
     * the weighted sum (price × quantity).
     */
    private function calculateBundlePrice(array $variantGidsWithQty): float
    {
        if (empty($variantGidsWithQty)) {
            return 0.0;
        }

        $gids  = array_column($variantGidsWithQty, 'gid');
        $query = <<<'GQL'
        query GetVariantPrices($ids: [ID!]!) {
            nodes(ids: $ids) {
                ... on ProductVariant {
                    id
                    price
                }
            }
        }
        GQL;

        try {
            $result   = $this->client->graphql($query, ['ids' => $gids]);
            $priceMap = [];
            foreach ($result['data']['nodes'] ?? [] as $node) {
                if (!empty($node['id']) && isset($node['price'])) {
                    $priceMap[$node['id']] = (float)$node['price'];
                }
            }

            $total = 0.0;
            foreach ($variantGidsWithQty as $item) {
                $total += ($priceMap[$item['gid']] ?? 0.0) * $item['quantity'];
            }
            return round($total, 2);

        } catch (\Exception $e) {
            LogHelper::warning('Bundle price calc failed — defaulting to 0', ['error' => $e->getMessage()]);
            return 0.0;
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Mutations
    // ──────────────────────────────────────────────────────────────────────────

    private function createBundle(Product $product, array $components, int $missing, float $bundlePrice, ?float $compareAtPrice = null): array
    {
        $mutation = <<<'GQL'
        mutation productBundleCreate($input: ProductBundleCreateInput!) {
            productBundleCreate(input: $input) {
                productBundleOperation {
                    id
                    status
                    product {
                        legacyResourceId
                        title
                        variants(first: 1) {
                            edges { node { legacyResourceId } }
                        }
                    }
                }
                userErrors { field message }
            }
        }
        GQL;

        // ProductBundleCreateInput only accepts title + components.
        // Fields like status, vendor, productType, tags are NOT part of this input type
        // and will cause a GraphQL validation error. They are applied via a REST
        // productUpdate call after the bundle operation completes.
        $input = [
            'title'      => $this->sanitizeTitle($product->title),
            'components' => $components,
        ];

        LogHelper::info('Bundle CREATE mutation', [
            'bundle_sku'   => $product->sku,
            'components'   => count($components),
            'bundle_price' => $bundlePrice,
        ]);

        $result = $this->client->graphql($mutation, ['input' => $input]);
        $data   = $this->extractResult($result, 'productBundleCreate', $product->sku, $missing, $bundlePrice, count($components));

        // After the bundle operation completes, apply product metadata and price
        // that the bundle mutation doesn't accept inline.
        if (!empty($data['shopify_product_id'])) {
            $this->applyBundleProductMetadata($data['shopify_product_id'], $product);
        }

        // Shopify's REST product update (activating status) re-enables inventory_management
        // on the variant as a side effect, breaking the Bundles app's managed-inventory state.
        // Explicitly reset it to null so the bundle remains in the correct state for future
        // productBundleUpdate calls and for isAlreadyBundleProduct detection.
        if (!empty($data['shopify_variant_id'])) {
            $this->resetBundleVariantTracking($data['shopify_variant_id'], $product->sku);
        }

        if (!empty($data['shopify_variant_id'])) {
            $this->updateBundleVariantPrice($data['shopify_variant_id'], $bundlePrice, $product->sku, $compareAtPrice);
        }

        return $data;
    }

    private function updateBundle(
        Product $product,
        array   $existingShopifyProduct,
        array   $components,
        int     $missing,
        float   $bundlePrice,
        ?float  $compareAtPrice = null
    ): array {
        $mutation = <<<'GQL'
        mutation productBundleUpdate($input: ProductBundleUpdateInput!) {
            productBundleUpdate(input: $input) {
                productBundleOperation {
                    id
                    status
                    product {
                        legacyResourceId
                        title
                        variants(first: 1) {
                            edges { node { legacyResourceId } }
                        }
                    }
                }
                userErrors { field message }
            }
        }
        GQL;

        // Use the title fetched from Shopify — not from WWS, not hardcoded.
        // This preserves any title changes the merchant made directly in Shopify.
        // Components' optionSelections (IDs + values) were also fetched from Shopify
        // in resolveComponents() and are sent back verbatim.
        $shopifyProductId = $existingShopifyProduct['id'];
        $shopifyTitle     = $this->sanitizeTitle($existingShopifyProduct['title'] ?? '');

        $input = [
            'productId'  => 'gid://shopify/Product/' . $shopifyProductId,
            'title'      => $shopifyTitle,
            'components' => $components,
        ];

        LogHelper::info('Bundle UPDATE mutation', [
            'bundle_sku'         => $product->sku,
            'shopify_product_id' => $shopifyProductId,
            'shopify_title'      => $shopifyTitle,
            'components'         => count($components),
            'bundle_price'       => $bundlePrice,
        ]);

        $result = $this->client->graphql($mutation, ['input' => $input]);
        $data   = $this->extractResult($result, 'productBundleUpdate', $product->sku, $missing, $bundlePrice, count($components));

        // Update variant price after the bundle operation completes
        if (!empty($data['shopify_variant_id'])) {
            $this->updateBundleVariantPrice($data['shopify_variant_id'], $bundlePrice, $product->sku, $compareAtPrice);
        }

        return $data;
    }

    /**
     * Update the variant price on a Shopify Bundles app-managed bundle.
     *
     * These bundles were created through the Shopify Bundles app UI and are considered
     * "app-owned". Shopify prevents external apps from modifying their components via
     * productBundleUpdate. We can only update the variant price via REST.
     *
     * Price = sum of (child item price × quantity), same formula as API-created bundles.
     *
     * Returns the same array shape as updateBundle / createBundle so the caller
     * (handleBundleSync in ProductSyncService) can log and map identically.
     */
    private function updateBundlePriceOnly(
        Product $product,
        array   $existingShopifyProduct,
        float   $bundlePrice,
        ?float  $compareAtPrice = null
    ): array {
        $shopifyProductId = $existingShopifyProduct['id'];
        $variantId        = $existingShopifyProduct['variants'][0]['id'] ?? null;

        LogHelper::info('Bundle price-only update (Shopify app bundle)', [
            'bundle_sku'         => $product->sku,
            'shopify_product_id' => $shopifyProductId,
            'bundle_price'       => $bundlePrice,
        ]);

        if ($variantId) {
            $this->updateBundleVariantPrice((string) $variantId, $bundlePrice, $product->sku, $compareAtPrice);
        } else {
            LogHelper::warning('Bundle price-only update: no variant ID found', [
                'bundle_sku'         => $product->sku,
                'shopify_product_id' => $shopifyProductId,
            ]);
        }

        // Ensure all bundle products carry BUNDLE + CURIONBUNDLE tags.
        // BUNDLE_API_SYNC is intentionally omitted — this is a Shopify app-owned bundle.
        // Merge with existing tags so merchant-added tags survive the sync.
        $mergedTags = $this->mergeTags(
            $existingShopifyProduct['tags'] ?? '',
            ['BUNDLE', 'CURIONBUNDLE']
        );
        try {
            $this->client->put("products/{$shopifyProductId}.json", [
                'product' => [
                    'id'   => $shopifyProductId,
                    'tags' => $mergedTags,
                ],
            ]);
        } catch (\Exception $e) {
            LogHelper::warning('Bundle price-only update: failed to set tags (non-fatal)', [
                'bundle_sku'         => $product->sku,
                'shopify_product_id' => $shopifyProductId,
                'error'              => $e->getMessage(),
            ]);
        }

        return [
            'shopify_product_id'   => (string) $shopifyProductId,
            'shopify_variant_id'   => (string) ($variantId ?? ''),
            'bundle_price'         => $bundlePrice,
            'components_resolved'  => 0,
            'components_missing'   => 0,
            'operation_status'     => 'PRICE_ONLY',
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Apply product metadata (status, vendor, type, tags) to a newly created bundle
     * via REST, since ProductBundleCreateInput does not accept these fields.
     *
     * Tags are fixed to: BUNDLE, CURIONBUNDLE, BUNDLE_API_SYNC
     *  - BUNDLE         : marks the product as a bundle (visible to all)
     *  - CURIONBUNDLE   : identifies bundles managed by this sync system
     *  - BUNDLE_API_SYNC: signals to detectBundleType() that this bundle was
     *                     created by our API and should receive full updates
     *
     * ERP product tags (SKU, product-type tags, etc.) are intentionally NOT
     * carried over — they would pollute the Shopify admin tag list.
     */
    private function applyBundleProductMetadata(string $productId, Product $product): void
    {
        // Fixed tag set — no ERP-sourced tags to avoid polluting the admin tag list
        $tags = ['BUNDLE', 'CURIONBUNDLE', 'BUNDLE_API_SYNC'];

        try {
            // New bundles are always created as 'draft' so they can be reviewed before
            // going live. The merchant publishes them manually when ready.
            // published_scope=global ensures the product is visible in ALL sales channels
            // (POS, Shop, Headless, etc.) once published — matching manually-created bundles.
            $this->client->put("products/{$productId}.json", [
                'product' => [
                    'id'              => $productId,
                    'title'           => $this->sanitizeTitle($product->title),
                    'status'          => 'draft',
                    'vendor'          => $product->vendor ?? '',
                    'product_type'    => $product->productType ?? '',
                    'tags'            => implode(', ', $tags),
                    'published_scope' => 'global',
                ],
            ]);
            LogHelper::debug('Bundle product metadata applied', [
                'shopify_product_id' => $productId,
                'status'             => $product->status ?? 'active',
                'tags'               => $tags,
                'published_scope'    => 'global',
            ]);
        } catch (\Exception $e) {
            LogHelper::warning('Bundle product metadata update failed (non-fatal)', [
                'shopify_product_id' => $productId,
                'error'              => $e->getMessage(),
            ]);
        }

        // Publish to all configured sales channels individually so they appear in every
        // channel the merchant expects (Shop, POS, Headless, etc.).
        $this->publishBundleToAllChannels($productId, $product->sku ?? '');
    }

    /**
     * Publish a bundle product to all available sales channels.
     *
     * productBundleCreate only publishes to "web" (Online Store) by default.
     * This call ensures the bundle is published globally — matching what the
     * Shopify Bundles app does when a merchant creates a bundle through the UI.
     */
    private function publishBundleToAllChannels(string $productId, string $sku): void
    {
        $mutation = <<<'GQL'
        mutation PublishBundle($productId: ID!, $publicationId: ID!) {
            publishablePublish(id: $productId, input: { publicationId: $publicationId }) {
                userErrors { field message }
            }
        }
        GQL;

        // Fetch all available publications and publish to each
        $pubQuery = <<<'GQL'
        { publications(first: 20) { edges { node { id name } } } }
        GQL;

        try {
            $pubResult  = $this->client->graphql($pubQuery);
            $pubEdges   = $pubResult['data']['publications']['edges'] ?? [];

            foreach ($pubEdges as $edge) {
                $pubGid  = $edge['node']['id'];
                $pubName = $edge['node']['name'];
                $result  = $this->client->graphql($mutation, [
                    'productId'     => 'gid://shopify/Product/' . $productId,
                    'publicationId' => $pubGid,
                ]);
                $errors = $result['data']['publishablePublish']['userErrors'] ?? [];
                if (!empty($errors)) {
                    LogHelper::debug('Bundle publication skipped', [
                        'bundle_sku'   => $sku,
                        'publication'  => $pubName,
                        'error'        => $errors[0]['message'] ?? '',
                    ]);
                }
            }

            LogHelper::debug('Bundle published to all channels', [
                'shopify_product_id' => $productId,
                'bundle_sku'         => $sku,
                'channels'           => count($pubEdges),
            ]);
        } catch (\Exception $e) {
            LogHelper::warning('Bundle publish-to-all-channels failed (non-fatal)', [
                'shopify_product_id' => $productId,
                'bundle_sku'         => $sku,
                'error'              => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reset inventory_management to null on a bundle variant.
     *
     * The Bundles app requires inv_mgmt = null (it manages stock through the component
     * products). Calling PUT /products/{id}.json with status=active can re-enable Shopify
     * tracking as a side effect. This call explicitly restores the correct bundle state
     * after applyBundleProductMetadata runs.
     */
    private function resetBundleVariantTracking(string $variantId, string $sku): void
    {
        try {
            $this->client->put("variants/{$variantId}.json", [
                'variant' => [
                    'id'                   => $variantId,
                    'sku'                  => $sku,
                    'inventory_management' => null,
                ],
            ]);
            LogHelper::debug('Bundle variant tracking reset to null and SKU stamped', [
                'bundle_sku'  => $sku,
                'variant_id'  => $variantId,
            ]);
        } catch (\Exception $e) {
            LogHelper::warning('Bundle variant tracking reset failed (non-fatal)', [
                'bundle_sku'  => $sku,
                'variant_id'  => $variantId,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update the bundle's variant price via REST after productBundleCreate/Update.
     *
     * Bundle mutations don't accept a price in the input — the variant is created
     * automatically and priced at 0.00 unless explicitly updated here.
     *
     * When $compareAtPrice is null (price derived from child sum only), compare-at is
     * cleared on the variant. When set (ERP basePrice path), it is the calculated sum
     * of component prices × quantity.
     */
    private function updateBundleVariantPrice(string $variantId, float $price, string $sku, ?float $compareAtPrice = null): void
    {
        if (empty($variantId) || $price <= 0) {
            return;
        }
        try {
            $variant = [
                'id'    => $variantId,
                'price' => number_format($price, 2, '.', ''),
            ];
            // When price comes from ERP basePrice, compare_at is the sum of child prices (calculated).
            // When price is derived from that sum only, clear compare-at so a stale value is not left.
            if ($compareAtPrice !== null && $compareAtPrice > 0) {
                $variant['compare_at_price'] = number_format($compareAtPrice, 2, '.', '');
            } else {
                $variant['compare_at_price'] = null;
            }

            $this->client->put("variants/{$variantId}.json", ['variant' => $variant]);
            LogHelper::info('Bundle variant price updated', [
                'bundle_sku'       => $sku,
                'variant_id'       => $variantId,
                'price'            => $price,
                'compare_at_price' => $variant['compare_at_price'],
            ]);
        } catch (\Exception $e) {
            LogHelper::warning('Bundle variant price update failed (non-fatal)', [
                'bundle_sku'  => $sku,
                'variant_id'  => $variantId,
                'price'       => $price,
                'compare_at'  => $compareAtPrice,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    private function extractResult(
        $result,
        string $mutationKey,
        string $bundleSku,
        int    $missing,
        float  $bundlePrice,
        int    $resolved = 0
    ): array {
        LogHelper::debug('Bundle mutation raw response', [
            'mutation'   => $mutationKey,
            'bundle_sku' => $bundleSku,
            'response'   => $result,
        ]);

        if (!empty($result['errors'])) {
            $msg = implode('; ', array_column($result['errors'], 'message'));
            throw new \RuntimeException(
                "Bundle GraphQL error ({$mutationKey}) for '{$bundleSku}': {$msg}\n" .
                "Hint: confirm the Shopify Bundles app is installed on this store."
            );
        }

        $payload = $result['data'][$mutationKey] ?? null;
        if ($payload === null) {
            throw new \RuntimeException(
                "Mutation '{$mutationKey}' returned no data for '{$bundleSku}'. " .
                "Full response: " . json_encode($result)
            );
        }

        if (!empty($payload['userErrors'])) {
            $errors = array_map(static function (array $e): string {
                $field = is_array($e['field'] ?? null) ? implode('.', $e['field']) : ($e['field'] ?? '');
                return ($field ? "{$field}: " : '') . ($e['message'] ?? '');
            }, $payload['userErrors']);
            throw new \RuntimeException(
                "Bundle userErrors ({$mutationKey}) for '{$bundleSku}': " . implode('; ', $errors)
            );
        }

        $operation = $payload['productBundleOperation'] ?? null;
        if (!$operation) {
            throw new \RuntimeException(
                "Mutation '{$mutationKey}' returned no productBundleOperation for '{$bundleSku}'."
            );
        }

        // Bundle operations are asynchronous — Shopify returns status "CREATED" immediately
        // but the actual processing happens in the background. Poll until COMPLETE/FAILED.
        $operationId = $operation['id'] ?? null;
        if ($operationId && in_array($operation['status'] ?? '', ['CREATED', 'RUNNING'], true)) {
            $operation = $this->pollBundleOperation($operationId, $bundleSku);
        }

        $opStatus = $operation['status'] ?? '';
        $opErrors = $operation['userErrors'] ?? [];

        if ($opStatus === 'FAILED' || ($opStatus === 'COMPLETE' && !empty($opErrors))) {
            $errMsgs = array_column($opErrors, 'message');
            $detail  = $errMsgs ? implode('; ', $errMsgs) : 'no detail provided';
            throw new \RuntimeException(
                "Bundle operation {$opStatus} for '{$bundleSku}': {$detail}"
            );
        }

        $shopifyProductId = $operation['product']['legacyResourceId'] ?? null;
        $shopifyVariantId = $operation['product']['variants']['edges'][0]['node']['legacyResourceId'] ?? null;

        LogHelper::info('Bundle sync completed', [
            'bundle_sku'         => $bundleSku,
            'shopify_product_id' => $shopifyProductId,
            'shopify_variant_id' => $shopifyVariantId,
            'operation_status'   => $opStatus,
            'bundle_price'       => $bundlePrice,
            'components_missing' => $missing,
        ]);

        return [
            'shopify_product_id'  => $shopifyProductId,
            'shopify_variant_id'  => $shopifyVariantId,
            'components_resolved' => $resolved,
            'components_missing'  => $missing,
            'bundle_price'        => $bundlePrice,
        ];
    }

    /**
     * Poll a ProductBundleOperation until it reaches a terminal state (COMPLETE / FAILED).
     *
     * Shopify's Bundles API is asynchronous — the mutation returns "CREATED" and the
     * actual component changes happen in the background. Without polling we can never
     * detect failures like "Something went wrong, please try again."
     *
     * Retry schedule: 3 s, 6 s, 12 s, 12 s = up to ~33 s total wait.
     */
    private function pollBundleOperation(string $operationId, string $bundleSku): array
    {
        $query = <<<'GQL'
        query PollBundleOperation($id: ID!) {
            node(id: $id) {
                ... on ProductBundleOperation {
                    id
                    status
                    userErrors { field message }
                    product {
                        legacyResourceId
                        title
                        variants(first: 1) {
                            edges { node { legacyResourceId } }
                        }
                    }
                }
            }
        }
        GQL;

        $delays = [3, 6, 12, 12]; // seconds between polls

        foreach ($delays as $i => $delay) {
            sleep($delay);

            try {
                $result    = $this->client->graphql($query, ['id' => $operationId]);
                $operation = $result['data']['node'] ?? null;
            } catch (\Exception $e) {
                LogHelper::warning('Bundle operation poll failed', [
                    'operation_id' => $operationId,
                    'bundle_sku'   => $bundleSku,
                    'attempt'      => $i + 1,
                    'error'        => $e->getMessage(),
                ]);
                continue;
            }

            $status = $operation['status'] ?? 'UNKNOWN';

            LogHelper::debug('Bundle operation poll', [
                'operation_id' => $operationId,
                'bundle_sku'   => $bundleSku,
                'attempt'      => $i + 1,
                'status'       => $status,
            ]);

            if (!in_array($status, ['CREATED', 'RUNNING'], true)) {
                return $operation ?? [];
            }
        }

        // Timed out — return last known state; caller will treat non-COMPLETE as a failure
        LogHelper::warning('Bundle operation polling timed out', [
            'operation_id' => $operationId,
            'bundle_sku'   => $bundleSku,
        ]);
        return ['status' => 'TIMEOUT', 'userErrors' => [['field' => null, 'message' => 'Operation polling timed out after 33s']], 'product' => null];
    }

    /**
     * Strip carriage returns, newlines, and excess whitespace from a product title.
     *
     * ERP titles sometimes contain \r\n (Windows line endings) in the middle of the string.
     * Shopify's Bundles app UI can fail to render products whose titles contain literal
     * newline characters, making the bundle invisible in the app interface.
     */
    private function sanitizeTitle(string $title): string
    {
        // Replace \r\n, \r, \n with a single space, then collapse extra spaces
        $clean = preg_replace('/[\r\n]+/', ' ', $title);
        return trim(preg_replace('/\s{2,}/', ' ', $clean));
    }

    /**
     * Merge sync-owned tags with the product's existing Shopify tags.
     *
     * Shopify's REST product.tags field is a full replacement — sending a string
     * deletes every tag not in it. Always merge with the current value so
     * merchant-added tags (collections, marketing, SEO, etc.) survive sync runs.
     *
     * @param string|array|null $existing  Existing tags as returned by Shopify (comma-separated string) or already-parsed array
     * @param string[]          $toAdd     Tags this sync owns and wants to ensure are present
     * @return string                      Comma-separated string ready for REST PUT
     */
    private function mergeTags($existing, array $toAdd): string
    {
        $current = is_array($existing)
            ? $existing
            : array_map('trim', explode(',', (string) ($existing ?? '')));

        $seen   = [];
        $merged = [];
        foreach (array_merge($current, $toAdd) as $tag) {
            $tag = trim($tag);
            if ($tag === '') {
                continue;
            }
            $key = strtolower($tag);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[]   = $tag;
        }

        return implode(', ', $merged);
    }

    /**
     * Update the custom.bundle_child_items metafield on a bundle product.
     *
     * Runs after every sync path (create, full update, price-only) so the list of
     * child product references is always current. Both API-created and Shopify Bundles
     * app-created bundles receive this update.
     *
     * Metafield spec:
     *   namespace : custom
     *   key       : bundle_child_items
     *   type      : list.product_reference
     *   value     : JSON array of product GIDs, e.g. ["gid://shopify/Product/1", ...]
     *
     * @param string   $bundleProductId   Legacy numeric Shopify product ID (REST)
     * @param string[] $childProductGids  Full GIDs of child products, e.g. gid://shopify/Product/123
     * @param string   $sku               Bundle SKU (for logging only)
     */
    private function updateChildItemsMetafield(string $bundleProductId, array $childProductGids, string $sku): void
    {
        if (empty($childProductGids)) {
            return;
        }

        $mutation = <<<'GQL'
        mutation UpdateBundleChildItems($metafields: [MetafieldsSetInput!]!) {
            metafieldsSet(metafields: $metafields) {
                metafields {
                    namespace
                    key
                    value
                }
                userErrors { field message code }
            }
        }
        GQL;

        try {
            $result = $this->client->graphql($mutation, [
                'metafields' => [[
                    'ownerId'   => 'gid://shopify/Product/' . $bundleProductId,
                    'namespace' => 'custom',
                    'key'       => 'bundle_child_items',
                    'type'      => 'list.product_reference',
                    'value'     => json_encode(array_values($childProductGids)),
                ]],
            ]);

            $errors = $result['data']['metafieldsSet']['userErrors'] ?? [];
            if (!empty($errors)) {
                LogHelper::warning('Bundle child items metafield update had errors', [
                    'bundle_sku'         => $sku,
                    'shopify_product_id' => $bundleProductId,
                    'errors'             => $errors,
                ]);
                return;
            }

            LogHelper::debug('Bundle child items metafield updated', [
                'bundle_sku'         => $sku,
                'shopify_product_id' => $bundleProductId,
                'child_count'        => count($childProductGids),
            ]);
        } catch (\Exception $e) {
            LogHelper::warning('Bundle child items metafield update failed (non-fatal)', [
                'bundle_sku'         => $sku,
                'shopify_product_id' => $bundleProductId,
                'error'              => $e->getMessage(),
            ]);
        }
    }
}
