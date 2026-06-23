<?php

namespace App\Adapters\Shopify;

use App\Core\Contracts\AdapterInterface;
use App\Core\Models\Order;

/**
 * Shopify Order Adapter
 * Converts Shopify API data to/from Core Order Model
 */
class OrderAdapter implements AdapterInterface
{
    /**
     * Convert Shopify order data to core Order model
     * @param array $shopifyData Raw Shopify API response
     * @return Order
     */
    public function toCoreModel(array $shopifyData): Order
    {
        $order = new Order();
        
        // Core identification
        $order->id = (string)($shopifyData['id'] ?? null);
        $order->orderNumber = (string)($shopifyData['order_number'] ?? null);
        $order->name = $shopifyData['name'] ?? null;
        
        // Customer
        $order->customer = $shopifyData['customer'] ?? null;
        $order->customerId = $shopifyData['customer']['id'] ?? null;
        $order->email = $shopifyData['email'] ?? null;
        
        // Addresses
        $order->billingAddress = $shopifyData['billing_address'] ?? null;
        $order->shippingAddress = $shopifyData['shipping_address'] ?? null;
        
        // Items
        $order->items = [];
        if (!empty($shopifyData['line_items']) && is_array($shopifyData['line_items'])) {
            foreach ($shopifyData['line_items'] as $item) {
                $order->items[] = [
                    'id' => $item['id'] ?? null,
                    'sku' => $item['sku'] ?? null,
                    'product_id' => $item['product_id'] ?? null,
                    'variant_id' => $item['variant_id'] ?? null,
                    'quantity' => $item['quantity'] ?? 1,
                    'price' => $item['price'] ?? 0,
                    'title' => $item['title'] ?? null,
                ];
            }
        }
        
        // Financial
        $order->subtotal = (float)($shopifyData['subtotal_price'] ?? 0);
        $order->totalTax = (float)($shopifyData['total_tax'] ?? 0);
        $order->totalShipping = (float)($shopifyData['total_shipping_price_set']['shop_money']['amount'] ?? 0);
        $order->totalDiscount = (float)($shopifyData['total_discounts'] ?? 0);
        $order->total = (float)($shopifyData['total_price'] ?? 0);
        $order->currency = $shopifyData['currency'] ?? 'USD';
        
        // Shipping
        $order->shippingMethod = $shopifyData['shipping_lines'][0]['title'] ?? null;
        $order->shippingCost = $order->totalShipping;
        $order->fulfillmentStatus = $shopifyData['fulfillment_status'] ?? 'unfulfilled';
        
        // Status
        $order->status = $shopifyData['financial_status'] ?? 'pending';
        $order->financialStatus = $shopifyData['financial_status'] ?? 'pending';
        
        // Dates
        $order->createdAt = $shopifyData['created_at'] ?? null;
        $order->updatedAt = $shopifyData['updated_at'] ?? null;
        $order->cancelledAt = $shopifyData['cancelled_at'] ?? null;
        
        // Additional
        $order->notes = $shopifyData['note'] ?? null;
        $order->tags = !empty($shopifyData['tags']) ? explode(', ', $shopifyData['tags']) : [];
        
        return $order;
    }
    
    /**
     * Convert core Order model to Shopify format
     * @param Order $order
     * @return array
     */
    public function fromCoreModel($order): array
    {
        $shopifyOrder = [
            'email' => $order->email,
            'financial_status' => $order->financialStatus ?? 'pending',
            'fulfillment_status' => $order->fulfillmentStatus ?? 'unfulfilled',
            'line_items' => [],
            'shipping_address' => $order->shippingAddress,
            'billing_address' => $order->billingAddress,
        ];
        
        // Convert items
        foreach ($order->items as $item) {
            $shopifyOrder['line_items'][] = [
                'variant_id' => $item['variant_id'] ?? null,
                'quantity' => $item['quantity'] ?? 1,
                'price' => (string)($item['price'] ?? 0),
            ];
        }
        
        return $shopifyOrder;
    }
}

