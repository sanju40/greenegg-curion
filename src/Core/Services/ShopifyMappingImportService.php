<?php

namespace App\Core\Services;

use App\Api\Shopify\Client as ShopifyClient;
use App\Database\Repository\ProductMappingRepository;
use App\Utils\LogHelper;

/**
 * Shopify → product_mappings import.
 *
 * Iterates every product variant in the Shopify store using GraphQL cursor-based
 * pagination and ensures each variant with a SKU has a row in product_mappings.
 *
 * When no WWS product ID is known (products added manually to Shopify), a
 * placeholder wws_product_id = 'shopify:{variant_id}' is stored.  The first
 * real WWS product sync for the same SKU will overwrite the placeholder with
 * the real numeric WWS ID automatically.
 *
 * Also captures each product's tag string so ProductSyncService can union it
 * with WWS-derived tags on write and avoid clobbering tags maintained directly
 * in Shopify.
 *
 * This service is intentionally read-only on the Shopify side — it never
 * creates or modifies any Shopify data.
 */
class ShopifyMappingImportService
{
    private ShopifyClient $shopifyClient;
    private ProductMappingRepository $mappingRepo;

    /** How many Shopify products to fetch per GraphQL page. Max 250. */
    private const PAGE_SIZE = 100;

    public function __construct()
    {
        $this->shopifyClient = new ShopifyClient();
        $this->mappingRepo   = new ProductMappingRepository();
    }

    /**
     * Import all Shopify product variants into product_mappings.
     *
     * @param callable|null $onPage  Optional progress callback: fn(int $page, array $stats)
     * @return array  [created, updated, skipped, total_variants, total_products, pages]
     */
    public function importAll(?callable $onPage = null): array
    {
        $stats = [
            'created'        => 0,
            'updated'        => 0,
            'skipped'        => 0,   // variants without a SKU
            'tags_updated'   => 0,   // products whose shopify_tags column was refreshed
            'total_variants' => 0,
            'total_products' => 0,
            'pages'          => 0,
        ];

        $cursor    = null;
        $hasMore   = true;
        $pageNum   = 0;

        while ($hasMore) {
            $pageNum++;
            $variables = ['first' => self::PAGE_SIZE];
            if ($cursor !== null) {
                $variables['after'] = $cursor;
            }

            $query = <<<'GQL'
            query ImportMappings($first: Int!, $after: String) {
                products(first: $first, after: $after) {
                    pageInfo {
                        hasNextPage
                        endCursor
                    }
                    edges {
                        node {
                            legacyResourceId
                            status
                            tags
                            variants(first: 100) {
                                edges {
                                    node {
                                        legacyResourceId
                                        sku
                                    }
                                }
                            }
                        }
                    }
                }
            }
            GQL;

            try {
                $result = $this->shopifyClient->graphql($query, $variables);
            } catch (\Exception $e) {
                LogHelper::error('ShopifyMappingImport: GraphQL request failed', [
                    'page'  => $pageNum,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            $productsData = $result['data']['products'] ?? [];
            $edges        = $productsData['edges'] ?? [];
            $pageInfo     = $productsData['pageInfo'] ?? [];
            $hasMore      = (bool)($pageInfo['hasNextPage'] ?? false);
            $cursor       = $pageInfo['endCursor'] ?? null;

            $pageCreated = 0;
            $pageUpdated = 0;
            $pageSkipped = 0;

            foreach ($edges as $productEdge) {
                $product          = $productEdge['node'] ?? [];
                $shopifyProductId = (string)($product['legacyResourceId'] ?? '');

                if (empty($shopifyProductId)) {
                    continue;
                }

                // Normalise GraphQL shape into the same payload shape the
                // products/* webhook delivers, then delegate to the shared
                // upsert path so bulk import and webhook stay in lockstep.
                $variants = [];
                foreach ($product['variants']['edges'] ?? [] as $variantEdge) {
                    $variants[] = [
                        'id'  => (string)($variantEdge['node']['legacyResourceId'] ?? ''),
                        'sku' => $variantEdge['node']['sku'] ?? '',
                    ];
                }

                $stats['total_products']++;
                $stats['total_variants'] += count($variants);

                $result = $this->upsertFromShopifyPayload([
                    'id'       => $shopifyProductId,
                    'tags'     => $product['tags'] ?? [],
                    'variants' => $variants,
                ]);

                $pageCreated += $result['variants_created'];
                $pageUpdated += $result['variants_updated'];
                $pageSkipped += $result['variants_skipped'];
                if ($result['tags_updated']) {
                    $stats['tags_updated']++;
                }
            }

            $stats['created'] += $pageCreated;
            $stats['updated'] += $pageUpdated;
            $stats['skipped'] += $pageSkipped;
            $stats['pages']    = $pageNum;

            LogHelper::info('ShopifyMappingImport: page complete', [
                'page'    => $pageNum,
                'created' => $pageCreated,
                'updated' => $pageUpdated,
                'skipped' => $pageSkipped,
                'has_more' => $hasMore,
            ]);

            if ($onPage !== null) {
                $onPage($pageNum, $stats);
            }
        }

        LogHelper::info('ShopifyMappingImport: finished', $stats);

        return $stats;
    }

    /**
     * Upsert a single Shopify product into product_mappings + persist its tags.
     *
     * Shared entry point used by importAll() and the products/* webhook handler.
     * Expects a normalised payload shape:
     *   [
     *       'id'       => '123456',                            // numeric Shopify product ID
     *       'tags'     => 'tag1, tag2'  OR  ['tag1','tag2'],   // string or array
     *       'variants' => [['id' => '...', 'sku' => '...'], ...],
     *   ]
     *
     * @return array{
     *     action: string,
     *     variants_created: int,
     *     variants_updated: int,
     *     variants_skipped: int,
     *     tags_updated: bool
     * }
     */
    public function upsertFromShopifyPayload(array $product): array
    {
        $stats = [
            'action'           => 'noop',
            'variants_created' => 0,
            'variants_updated' => 0,
            'variants_skipped' => 0,
            'tags_updated'     => false,
        ];

        $shopifyProductId = (string)($product['id'] ?? '');
        if ($shopifyProductId === '') {
            return $stats;
        }

        $mappingTouched = false;

        foreach ($product['variants'] ?? [] as $variant) {
            $shopifyVariantId = (string)($variant['id'] ?? '');
            $sku              = trim((string)($variant['sku'] ?? ''));

            if ($sku === '') {
                $stats['variants_skipped']++;
                continue;
            }

            try {
                $action = $this->mappingRepo->saveFromShopify($sku, $shopifyProductId, $shopifyVariantId);
                $mappingTouched = true;

                if ($action['action'] === 'created') {
                    $stats['variants_created']++;
                } else {
                    $stats['variants_updated']++;
                }
            } catch (\Exception $e) {
                LogHelper::warning('ShopifyMappingImport: upsert failed for variant', [
                    'sku'                => $sku,
                    'shopify_product_id' => $shopifyProductId,
                    'shopify_variant_id' => $shopifyVariantId,
                    'error'              => $e->getMessage(),
                ]);
                $stats['variants_skipped']++;
            }
        }

        // Persist tags only when at least one mapping row was written —
        // otherwise the UPDATE would no-op and we'd waste a query.
        if ($mappingTouched) {
            $rawTags = $product['tags'] ?? '';
            $tagString = is_array($rawTags)
                ? implode(', ', array_map('trim', $rawTags))
                : (string)$rawTags;

            try {
                $this->mappingRepo->updateShopifyTags($shopifyProductId, $tagString);
                $stats['tags_updated'] = true;
            } catch (\Exception $e) {
                LogHelper::warning('ShopifyMappingImport: failed to persist tags for product', [
                    'shopify_product_id' => $shopifyProductId,
                    'error'              => $e->getMessage(),
                ]);
            }
        }

        $stats['action'] = ($stats['variants_created'] + $stats['variants_updated']) > 0
            ? 'upserted'
            : 'noop';

        return $stats;
    }
}
