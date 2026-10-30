<?php
declare(strict_types=1);
namespace Shop\repositories;

use Shop\domain\Money;

/** Pure conversion; callers supply images in sort_order,id order. */
final class ProductDtoMapper
{
    public static function map(array $product, array $images, bool $card = false): array
    {
        $dto = [
            'id' => $product['id'],
            'categoryId' => $product['category_id'],
            'name' => $product['name'],
            'price' => Money::format(Money::parse($product['price'])),
            'discountPrice' => $product['discount_price'] === null ? null : Money::format(Money::parse($product['discount_price'])),
            'stock' => $product['stock'],
            'rating' => $product['rating'] === null ? null : (float)$product['rating'],
            'isActive' => $product['is_active'],
            'reviewsCount' => $product['reviews_count'],
        ];
        return $dto + ($card ? [
            'imageUrl' => $images[0] ?? null,
            'descriptionSnippet' => mb_substr($product['description'], 0, 257, 'UTF-8'),
            'descriptionComplete' => $product['description_complete'] ?? mb_strlen($product['description'], 'UTF-8') <= 257,
        ] : ['description' => $product['description'], 'imageUrls' => $images]);
    }
}
