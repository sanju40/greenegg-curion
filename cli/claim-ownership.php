<?php

/**
 * Shopify Bundle Ownership Debug Script
 *
 * What it does:
 * 1. Reads product details
 * 2. Attempts productSet with claimOwnership.bundles = true
 * 3. Prints response/errors clearly
 *
 * Usage:
 *   php cli/claim-ownership.php
 */

require __DIR__ . '/../src/bootstrap.php';

$config = \App\Core\Config::get();

$shop        = $config['shopify']['shop_domain'];
$accessToken = $config['shopify']['access_token'];
$apiVersion  = $config['shopify']['api_version'];

// Existing product GID you want to test
$productGid = 'gid://shopify/Product/10354024907068';

// Optional: change title slightly so productSet definitely performs an update
$updatedTitle = 'Ownership Test Product ' . date('Y-m-d H:i:s');

// NEW: values you actually want to update
$newPrice = '9999.00';
$newInventoryQty = 8;

// IMPORTANT: replace this with your actual Shopify Location GID
$locationGid = 'gid://shopify/Location/101968412988';

function shopifyGraphQL($shop, $token, $apiVersion, $query, $variables = [])
{
    $url = "https://{$shop}/admin/api/{$apiVersion}/graphql.json";

    $payload = json_encode([
        'query' => $query,
        'variables' => $variables,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Shopify-Access-Token: ' . $token,
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        throw new Exception('cURL error: ' . curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'body' => json_decode($response, true),
        'raw' => $response,
    ];
}

try {
    echo "==============================\n";
    echo "1) FETCH PRODUCT DETAILS\n";
    echo "==============================\n";

    $productQuery = <<<'GRAPHQL'
query GetProduct($id: ID!) {
  product(id: $id) {
    id
    title
    status
    handle
    createdAt
    updatedAt
    variants(first: 20) {
      nodes {
        id
        title
        price
        inventoryItem {
          id
          sku
          tracked
        }
      }
    }
    bundleComponents(first: 50) {
      nodes {
        quantity
        componentProduct {
          id
          title
        }
        componentVariants(first: 20) {
          nodes {
            id
            title
            sku
            price
          }
        }
      }
    }
  }
}
GRAPHQL;

    $productResp = shopifyGraphQL(
        $shop,
        $accessToken,
        $apiVersion,
        $productQuery,
        ['id' => $productGid]
    );

    echo "HTTP: " . $productResp['http_code'] . "\n";
    echo "<pre>";
    print_r($productResp['body']);
    echo "</pre>";
    echo "\n\n";

    // Read the bundle's first variant and inventory item
    $variantNodes = $productResp['body']['data']['product']['variants']['nodes'] ?? [];
    if (empty($variantNodes)) {
        throw new Exception('No variants found for this product.');
    }

    $bundleVariantId = $variantNodes[0]['id'] ?? null;
    $inventoryItemId = $variantNodes[0]['inventoryItem']['id'] ?? null;

    if (!$bundleVariantId) {
        throw new Exception('Bundle variant ID not found.');
    }

    if (!$inventoryItemId) {
        throw new Exception('Inventory item ID not found.');
    }

    echo "Detected bundleVariantId: {$bundleVariantId}\n";
    echo "Detected inventoryItemId: {$inventoryItemId}\n\n";

    echo "==============================\n";
    echo "2) TRY productSet WITH claimOwnership\n";
    echo "==============================\n";

    $mutation = <<<'GRAPHQL'
mutation ProductSetOwnershipTest($input: ProductSetInput!, $synchronous: Boolean!) {
  productSet(input: $input, synchronous: $synchronous) {
    product {
      id
      title
      status
      updatedAt
    }
    productSetOperation {
      id
      status
      userErrors {
        field
        message
        code
      }
    }
    userErrors {
      field
      message
      code
    }
  }
}
GRAPHQL;

    $variables = [
        'synchronous' => false,
        'input' => [
            'id' => $productGid,
            'title' => $updatedTitle,
            'claimOwnership' => [
                'bundles' => false
            ]
        ]
    ];

    $ownershipResp = shopifyGraphQL(
        $shop,
        $accessToken,
        $apiVersion,
        $mutation,
        $variables
    );

    echo "HTTP: " . $ownershipResp['http_code'] . "\n";
    echo "<pre>";
    print_r($ownershipResp['body']);
    echo "</pre>";
    echo "\n\n";

    echo "==============================\n";
    echo "2A) UPDATE BUNDLE PRICE\n";
    echo "==============================\n";

    $priceMutation = <<<'GRAPHQL'
mutation UpdateBundlePrice($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
  productVariantsBulkUpdate(productId: $productId, variants: $variants) {
    product {
      id
      title
    }
    productVariants {
      id
      title
      price
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

    $priceVariables = [
        'productId' => $productGid,
        'variants' => [
            [
                'id' => $bundleVariantId,
                'price' => $newPrice,
            ]
        ]
    ];

    $priceResp = shopifyGraphQL(
        $shop,
        $accessToken,
        $apiVersion,
        $priceMutation,
        $priceVariables
    );

    echo "HTTP: " . $priceResp['http_code'] . "\n";
    echo "<pre>";
    print_r($priceResp['body']);
    echo "</pre>";
    echo "\n\n";

    echo "==============================\n";
    echo "2B) UPDATE BUNDLE INVENTORY\n";
    echo "==============================\n";

    $inventoryMutation = <<<'GRAPHQL'
mutation UpdateBundleInventory($input: InventorySetQuantitiesInput!) {
  inventorySetQuantities(input: $input) {
    inventoryAdjustmentGroup {
      createdAt
      reason
      changes {
        name
        delta
      }
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

    $inventoryVariables = [
        'input' => [
            'name' => 'available',
            'reason' => 'correction',
            'referenceDocumentUri' => 'app://bundle-debug/' . basename($productGid),
            //'ignoreCompareQuantity' => true,
            'quantities' => [
                [
                    'inventoryItemId' => $inventoryItemId,
                    'locationId' => $locationGid,
                    'quantity' => $newInventoryQty,
                    'changeFromQuantity' => 0
                    //'compareQuantity' => 0 // or actual current qty
                ]
            ]
        ]
    ];

    $inventoryResp = shopifyGraphQL(
        $shop,
        $accessToken,
        $apiVersion,
        $inventoryMutation,
        $inventoryVariables
    );

    echo "HTTP: " . $inventoryResp['http_code'] . "\n";
    echo "<pre>";
    print_r($inventoryResp['body']);
    echo "</pre>";
    echo "\n\n";

    echo "==============================\n";
    echo "3) FETCH PRODUCT AGAIN\n";
    echo "==============================\n";

    $productResp2 = shopifyGraphQL(
        $shop,
        $accessToken,
        $apiVersion,
        $productQuery,
        ['id' => $productGid]
    );

    echo "HTTP: " . $productResp2['http_code'] . "\n";
    echo "<pre>";
    print_r($productResp2['body']);
    echo "</pre>";
    echo "\n\n";

    echo "==============================\n";
    echo "DONE\n";
    echo "==============================\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}