<?php

namespace App\Adapters\WwsRestService;

use App\Core\Contracts\AdapterInterface;
use App\Core\Models\Order;

/**
 * WWS Order/Transaction Adapter
 * Converts WWS API data to/from Core Order Model
 */
class OrderAdapter implements AdapterInterface
{
    /**
     * Convert WWS transaction data to core Order model
     * @param array $wwsData Raw WWS API response
     * @return Order
     */
    public function toCoreModel(array $wwsData): Order
    {
        $order = new Order();
        
        // Core identification
        $order->id = (string)($wwsData['id'] ?? null);
        $order->orderNumber = $wwsData['transactionNumber'] ?? $wwsData['orderNumber'] ?? null;
        
        // Customer
        $order->customerId = $wwsData['customerId'] ?? null;
        $order->email = $wwsData['email'] ?? null;
        
        // Items
        $order->items = [];
        if (!empty($wwsData['items']) && is_array($wwsData['items'])) {
            foreach ($wwsData['items'] as $item) {
                $order->items[] = [
                    'sku' => $item['sku'] ?? null,
                    'product_id' => $item['productId'] ?? null,
                    'quantity' => $item['quantity'] ?? 1,
                    'price' => $item['price'] ?? 0,
                    'title' => $item['title'] ?? $item['name'] ?? null,
                ];
            }
        }
        
        // Financial
        $order->subtotal = $wwsData['subtotal'] ?? 0;
        $order->totalTax = $wwsData['tax'] ?? 0;
        $order->totalShipping = $wwsData['shipping'] ?? 0;
        $order->total = $wwsData['total'] ?? 0;
        $order->currency = $wwsData['currency'] ?? 'USD';
        
        // Status
        $order->status = $wwsData['status'] ?? 'pending';
        $order->fulfillmentStatus = $wwsData['fulfillmentStatus'] ?? 'unfulfilled';
        
        // Dates
        $order->createdAt = $wwsData['createdAt'] ?? $wwsData['created_at'] ?? null;
        $order->updatedAt = $wwsData['updatedAt'] ?? $wwsData['updated_at'] ?? null;
        
        return $order;
    }
    
    /**
     * Convert core Order model to WWS format
     * @param Order $order
     * @return array
     */
    public function fromCoreModel($order): array
    {
        return [
            'customerId' => $order->customerId,
            'email' => $order->email,
            'items' => $order->items,
            'subtotal' => $order->subtotal,
            'tax' => $order->totalTax,
            'shipping' => $order->totalShipping,
            'total' => $order->total,
            'currency' => $order->currency,
            'status' => $order->status,
        ];
    }
}

