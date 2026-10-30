<?php
declare(strict_types=1);
namespace Shop\repositories;

use Shop\domain\{Ids,Money};

/** Pure historical snapshot conversion; no current-product lookup. */
final class OrderDtoMapper
{
    public static function map(array $order, array $preloadedItems): array
    {
        $items = array_map(static fn(array $item): array => [
            'productId' => $item['product_id'],
            'productName' => $item['product_name'],
            'quantity' => $item['quantity'],
            'priceSnapshot' => Money::format(Money::parse($item['price_snapshot'])),
        ], $preloadedItems);
        return [
            'id' => $order['id'], 'number' => $order['number'], 'userId' => $order['user_id'],
            'status' => $order['status'], 'items' => $items,
            'totalAmount' => Money::format(Money::parse($order['total_amount'])),
            'deliveryAddressId' => $order['delivery_address_id'], 'deliveryAddress' => $order['delivery_address'],
            'paymentId' => $order['payment_id'], 'paymentMethod' => $order['payment_method'],
            'createdAt' => Ids::timestamp($order['created_at']), 'comment' => $order['comment'],
        ];
    }
}
