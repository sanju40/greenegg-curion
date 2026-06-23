<?php

/**
 * CLI: Update Shopify Bundle Prices
 *
 * Fetch bundle products via GraphQL (with pagination), calculate child prices,
 * and update bundle price when it differs from the sum of child items.
 *
 * Usage:
 *   php cli/update-bundle-prices.php \
 *     --metafield-namespace=bundles \
 *     --metafield-key=components \
 *     --query="metafield:bundles.components" \
 *     --page-size=50 \
 *     --limit=0 \
 *     --dry-run
 *
 * Notes:
 * - The bundle components metafield is expected to be JSON.
 * - Supports common keys: variant_id, variantId, id, quantity, qty.
 */

require __DIR__ . '/../src/bootstrap.php';

$config = \App\Core\Config::get();

if (isset($config['cli_enabled']) && !$config['cli_enabled']) {
    echo "CLI commands are disabled\n";
    exit(1);
}

$options = getopt('', [
    'metafield-namespace:',
    'metafield-key:',
    'query:',
    'page-size:',
    'limit:',
    'dry-run',
]);

$metafieldNamespace = $options['metafield-namespace'] ?? 'bundles';
$metafieldKey = $options['metafield-key'] ?? 'components';
$searchQuery = $options['query'] ?? "metafield:{$metafieldNamespace}.{$metafieldKey}:*";
$pageSize = isset($options['page-size']) ? (int)$options['page-size'] : 50;
$limit = isset($options['limit']) ? (int)$options['limit'] : 0; // 0 = no limit
$dryRun = array_key_exists('dry-run', $options);

if ($pageSize < 1 || $pageSize > 250) {
    echo "Invalid --page-size. Must be between 1 and 250.\n";
    exit(1);
}

echo "Starting bundle price update...\n";
echo "Metafield: {$metafieldNamespace}.{$metafieldKey}\n";
echo "Query: {$searchQuery}\n";
echo "Page size: {$pageSize}\n";
echo "Limit: " . ($limit > 0 ? $limit : 'unlimited') . "\n";
echo "Dry run: " . ($dryRun ? 'yes' : 'no') . "\n\n";

$client = new \App\Api\Shopify\Client();

$bundleQuery = <<<'GRAPHQL'
query BundleProducts($first: Int!, $after: String, $query: String!, $namespace: String!, $key: String!) {
  products(first: $first, after: $after, query: $query) {
    pageInfo {
      hasNextPage
      endCursor
    }
    edges {
      node {
        id
        title
        handle
        productType
        variants(first: 10) {
          edges {
            node {
              id
              price
              sku
            }
          }
        }
        metafield(namespace: $namespace, key: $key) {
          value
          type
        }
      }
    }
  }
}
GRAPHQL;

$variantPricesQuery = <<<'GRAPHQL'
query VariantPrices($ids: [ID!]!) {
  nodes(ids: $ids) {
    ... on ProductVariant {
      id
      price
      sku
    }
  }
}
GRAPHQL;

$updateVariantMutation = <<<'GRAPHQL'
mutation UpdateVariantPrice($input: ProductVariantInput!) {
  productVariantUpdate(input: $input) {
    productVariant {
      id
      price
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

$processed = 0;
$updated = 0;
$skipped = 0;
$errors = 0;
$after = null;

while (true) {
    $response = $client->graphql($bundleQuery, [
        'first' => $pageSize,
        'after' => $after,
        'query' => $searchQuery,
        'namespace' => $metafieldNamespace,
        'key' => $metafieldKey,
    ]);

    if (!isset($response['data']['products']['edges'])) {
        echo "Unexpected response from Shopify GraphQL.\n";
        $errors++;
        break;
    }

    $edges = $response['data']['products']['edges'];
    if (empty($edges)) {
        break;
    }

    foreach ($edges as $edge) {
        if ($limit > 0 && $processed >= $limit) {
            break 2;
        }

        $product = $edge['node'];
        $processed++;

        echo "Found bundle: {$product['title']} (handle: {$product['handle']})\n";

        $bundleVariant = $product['variants']['edges'][0]['node'] ?? null;
        $metafield = $product['metafield'] ?? null;

        if (!$bundleVariant) {
            echo "  - Skipped: No bundle variant found\n";
            $skipped++;
            continue;
        }

        echo "  - Metafield data: " . json_encode($metafield) . "\n";

        if (!$metafield || empty($metafield['value'])) {
            echo "  - Skipped: No metafield or empty value\n";
            $skipped++;
            continue;
        }

        echo "  - Current price: {$bundleVariant['price']}\n";
        echo "  - Metafield value: " . substr($metafield['value'], 0, 100) . "...\n";

        $components = json_decode($metafield['value'], true);
        if (!is_array($components) || empty($components)) {
            echo "  - Skipped: Invalid JSON or empty components\n";
            $skipped++;
            continue;
        }

        echo "  - Components found: " . count($components) . "\n";

        $componentIds = [];
        $componentQty = [];

        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }

            $rawId = $component['variant_id']
                ?? $component['variantId']
                ?? $component['id']
                ?? null;
            $qty = $component['quantity']
                ?? $component['qty']
                ?? 1;

            if (!$rawId) {
                continue;
            }

            $variantGid = normalize_variant_gid($rawId);
            $componentIds[] = $variantGid;
            $componentQty[$variantGid] = (int)$qty;
        }

        if (empty($componentIds)) {
            echo "  - Skipped: No valid component IDs found\n";
            $skipped++;
            continue;
        }

        echo "  - Valid component IDs: " . implode(', ', array_map(function($id) { 
            return preg_replace('/^gid:\/\/shopify\/ProductVariant\//', '', $id); 
        }, $componentIds)) . "\n";

        $componentIds = array_values(array_unique($componentIds));

        $bundlePriceCents = price_to_cents($bundleVariant['price'] ?? '0');
        $computedPriceCents = 0;

        $chunks = array_chunk($componentIds, 100);
        foreach ($chunks as $chunk) {
            $priceResponse = $client->graphql($variantPricesQuery, [
                'ids' => $chunk,
            ]);

            $nodes = $priceResponse['data']['nodes'] ?? [];
            foreach ($nodes as $node) {
                if (!$node || empty($node['id'])) {
                    continue;
                }
                $priceCents = price_to_cents($node['price'] ?? '0');
                $qty = $componentQty[$node['id']] ?? 1;
                $computedPriceCents += ($priceCents * $qty);
            }
        }

        if ($bundlePriceCents === $computedPriceCents) {
            $skipped++;
            continue;
        }

        $newPrice = cents_to_price($computedPriceCents);
        echo "Updating bundle: {$product['title']} ({$product['handle']}) ";
        echo "from {$bundleVariant['price']} to {$newPrice}\n";

        if (!$dryRun) {
            $mutationResponse = $client->graphql($updateVariantMutation, [
                'input' => [
                    'id' => $bundleVariant['id'],
                    'price' => $newPrice,
                ],
            ]);

            $errorsList = $mutationResponse['data']['productVariantUpdate']['userErrors'] ?? [];
            if (!empty($errorsList)) {
                $errors++;
                echo "  Error updating variant: " . json_encode($errorsList) . "\n";
                continue;
            }
        }

        $updated++;
    }

    $pageInfo = $response['data']['products']['pageInfo'] ?? null;
    if (!$pageInfo || empty($pageInfo['hasNextPage'])) {
        break;
    }
    $after = $pageInfo['endCursor'] ?? null;
    if (!$after) {
        break;
    }
}

echo "\nDone.\n";
echo "Processed: {$processed}\n";
echo "Updated: {$updated}\n";
echo "Skipped: {$skipped}\n";
echo "Errors: {$errors}\n";

exit($errors > 0 ? 1 : 0);

function normalize_variant_gid($rawId): string
{
    $rawId = trim((string)$rawId);
    if (strpos($rawId, 'gid://shopify/ProductVariant/') === 0) {
        return $rawId;
    }
    return 'gid://shopify/ProductVariant/' . $rawId;
}

function price_to_cents($price): int
{
    $float = is_numeric($price) ? (float)$price : 0.0;
    return (int)round($float * 100);
}

function cents_to_price(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

