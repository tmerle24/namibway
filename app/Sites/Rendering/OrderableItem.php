<?php

namespace App\Sites\Rendering;

/**
 * One line an order form offers, whichever table it came from.
 *
 * `id` is the id in that table — a `menu_items.id` for a restaurant order, a
 * `shop_products.id` for a product order. Which one it means follows from the
 * form type, which the server knows from the block and never takes from the
 * browser; a posted id is only ever looked up inside the business's own rows.
 */
final class OrderableItem
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $description,
        public readonly float $price,
        public readonly string $currency,
        public readonly string $section,
    ) {}

    public function priceLabel(): string
    {
        return $this->currency.' '.number_format($this->price, 2);
    }
}
