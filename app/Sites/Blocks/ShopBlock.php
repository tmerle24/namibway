<?php

namespace App\Sites\Blocks;

/**
 * A product gallery section.
 *
 * The block holds only the section heading. The products themselves live in
 * shop_products, not in the block JSON — a block payload is a fixed document,
 * and a shop with fifty products cannot fit in one. The renderer loads them
 * separately and the controller decides whether to show the block at all (no
 * published products → no section, regardless of the toggle).
 */
class ShopBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'shop';
    }

    public function label(): string
    {
        return 'Shop';
    }

    public function navDefault(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'heading' => ['nullable', 'string', 'max:120'],
            'product_count' => ['nullable', 'integer', 'min:1', 'max:60'],
        ], $this->navRules());
    }

    public function isFilled(array $data): bool
    {
        // Whether there is anything to show is answered by the product count,
        // which the block itself cannot know. The controller's shouldRender()
        // covers that; isFilled() just has to say "yes, the block is set up".
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'heading' => 'Shop',
            'product_count' => 8,
        ];
    }
}
