<?php

namespace App\Sites\Rendering;

use App\Models\MenuItem;
use App\Models\ShopProduct;
use App\Models\Site;
use App\Sites\Blocks\EnquiryFormType;
use App\Support\MediaUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * What an order form on a customer's website offers, and what it costs.
 *
 * Two very different tables answer the same question depending on the form
 * type — a restaurant's `menu_items`, a shop's `shop_products` — so the view
 * gets one shape either way: an id, a name, a price and a section. The view
 * renders a list; it does not learn which table it came from, and it does not
 * query anything itself.
 *
 * Only priced rows are offered. A product with nothing but "Call for price" is
 * a real thing a shop sells and shows in the catalogue, but a form that let
 * somebody put a quantity against it could not total the order, and an order
 * with no total is not one a business can act on.
 */
final class EnquiryItems
{
    /**
     * @param  Collection<int, OrderableItem>  $items
     */
    private function __construct(
        public readonly Collection $items,
        public readonly string $currency,
    ) {}

    public static function for(Site $site, EnquiryFormType $type): self
    {
        return match ($type) {
            EnquiryFormType::RestaurantOrder => self::fromMenu($site),
            EnquiryFormType::ProductOrder => self::fromShop($site),
            default => new self(collect(), 'NAD'),
        };
    }

    /** Whether this form has anything to sell. An order form with none is not rendered. */
    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    /**
     * The items grouped into their sections, in the order they were entered.
     *
     * @return array<int, array{section: string, items: array<int, OrderableItem>}>
     */
    public function sections(): array
    {
        $grouped = [];

        foreach ($this->items as $item) {
            $grouped[$item->section][] = $item;
        }

        return array_map(
            fn (string $section, array $items): array => ['section' => $section, 'items' => $items],
            array_keys($grouped),
            array_values($grouped),
        );
    }

    private static function fromMenu(Site $site): self
    {
        $listing = $site->sourceListing;

        if ($listing === null) {
            return new self(collect(), 'NAD');
        }

        $rows = $listing->menuItems()->available()->get();
        $first = $rows->first();

        return new self(
            $rows->map(fn (MenuItem $item) => new OrderableItem(
                id: $item->id,
                name: $item->name,
                description: $item->description,
                price: $item->price,
                currency: $item->currency,
                section: $item->section(),
                imageUrl: self::resolveMenuItemImageUrl($item->image),
            )),
            $first === null ? 'NAD' : $first->currency,
        );
    }

    private static function fromShop(Site $site): self
    {
        $rows = $site->shopProducts()->orderable()->orderBy('sort')->orderBy('id')->with('images')->get();
        $first = $rows->first();

        return new self(
            $rows->map(fn (ShopProduct $product) => new OrderableItem(
                id: $product->id,
                name: $product->title,
                description: $product->description,
                price: (float) $product->price,
                currency: $product->currency,
                section: filled($product->category) ? $product->category : 'Products',
                imageUrl: $product->firstImageUrl(120),
            )),
            $first === null ? 'NAD' : $first->currency,
        );
    }

    private static function resolveMenuItemImageUrl(?string $image): ?string
    {
        if (blank($image)) {
            return null;
        }

        if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://') || str_starts_with($image, '/')) {
            return MediaUrl::thumb($image, 120);
        }

        return MediaUrl::thumb(Storage::disk('r2')->url($image), 120);
    }
}
