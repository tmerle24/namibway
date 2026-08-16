<?php

namespace App\Services\Booking;

use App\Models\Inquiry;
use App\Models\MenuItem;
use App\Models\ShopProduct;
use App\Models\Site;
use App\Sites\Blocks\EnquiryFormType;
use Illuminate\Support\Collection;

/**
 * Prices an order placed from a customer's own website.
 *
 * The same rule `MenuOrder` follows on namibway.com, and for the same reason:
 * **the browser sends ids and quantities and nothing else.** Every name and
 * every price is read here, from the rows belonging to this site, and frozen
 * onto `inquiry_items`. A form that posts a price is a form somebody can edit
 * to post a different one.
 *
 * Two sources, one shape. A restaurant order is priced from the listing's
 * `menu_items`; a product order from the site's own `shop_products`. Which one
 * applies follows from the form type stored on the block — never from the
 * request, so a page cannot ask to be priced out of somebody else's catalogue.
 *
 * Anything unpriced is not orderable and is skipped rather than guessed at: a
 * product carrying only "Call for price" has no number to multiply.
 */
class SiteOrder
{
    /**
     * @param  array<int, array{id: int, name: string, quantity: int, unit_price: float, line_total: float, currency: string}>  $lines
     */
    private function __construct(
        public readonly array $lines,
        public readonly float $total,
        public readonly string $currency,
    ) {}

    /**
     * @param  array<int|string, int|string>  $quantities  item id => quantity, as posted
     */
    public static function price(Site $site, EnquiryFormType $type, array $quantities): self
    {
        $wanted = self::collapse($quantities);

        if ($wanted === []) {
            return new self([], 0.0, 'NAD');
        }

        $rows = $type === EnquiryFormType::ProductOrder
            ? self::products($site, array_keys($wanted))
            : self::menu($site, array_keys($wanted));

        $lines = [];
        $total = 0.0;
        $currency = 'NAD';

        foreach ($rows as $id => $row) {
            $quantity = $wanted[$id];
            $lineTotal = round($row['price'] * $quantity, 2);
            $total += $lineTotal;
            $currency = $row['currency'];

            $lines[] = [
                'id' => $id,
                'name' => $row['name'],
                'quantity' => $quantity,
                'unit_price' => $row['price'],
                'line_total' => $lineTotal,
                'currency' => $row['currency'],
            ];
        }

        return new self($lines, round($total, 2), $currency);
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * Write the lines onto a request that has just been created.
     *
     * `menu_item_id` is only set for a restaurant order — a product line points
     * at `shop_products`, a different table, and pretending otherwise would put
     * a foreign key on a row that does not exist there. The frozen name and
     * price are what the business reads either way, which is the whole reason
     * they are frozen.
     */
    public function attachTo(Inquiry $inquiry, EnquiryFormType $type): void
    {
        $isMenu = $type === EnquiryFormType::RestaurantOrder;

        $inquiry->items()->createMany(array_map(fn (array $line): array => [
            'menu_item_id' => $isMenu ? $line['id'] : null,
            'name' => $line['name'],
            'quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'],
            'line_total' => $line['line_total'],
            'currency' => $line['currency'],
        ], $this->lines));

        $inquiry->forceFill([
            'total_amount' => $this->total,
            'currency' => $this->currency,
        ])->save();
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array{name: string, price: float, currency: string}>
     */
    private static function menu(Site $site, array $ids): array
    {
        $listing = $site->sourceListing;

        if ($listing === null) {
            return [];
        }

        /** @var Collection<int, MenuItem> $rows */
        $rows = $listing->menuItems()->available()->whereIn('id', $ids)->get();

        return $rows->mapWithKeys(fn (MenuItem $item): array => [
            $item->id => ['name' => $item->name, 'price' => $item->price, 'currency' => $item->currency],
        ])->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array{name: string, price: float, currency: string}>
     */
    private static function products(Site $site, array $ids): array
    {
        /** @var Collection<int, ShopProduct> $rows */
        $rows = $site->shopProducts()->orderable()->whereIn('id', $ids)->get();

        return $rows->mapWithKeys(fn (ShopProduct $product): array => [
            $product->id => ['name' => $product->title, 'price' => (float) $product->price, 'currency' => $product->currency],
        ])->all();
    }

    /**
     * @param  array<int|string, int|string>  $quantities
     * @return array<int, int>
     */
    private static function collapse(array $quantities): array
    {
        $wanted = [];

        foreach ($quantities as $id => $quantity) {
            $id = (int) $id;
            $quantity = (int) $quantity;

            if ($id > 0 && $quantity > 0) {
                $wanted[$id] = min($quantity, 99);
            }
        }

        return $wanted;
    }
}
