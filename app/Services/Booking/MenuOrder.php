<?php

namespace App\Services\Booking;

use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\MenuItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Turns "these menu item ids, these quantities" into priced, frozen order lines.
 *
 * **The browser sends ids and counts and nothing else.** No name, no price, no
 * total — every one of those is read from the menu in the database here. A form
 * that posts a price is a form that can be edited to post a different one, and
 * a restaurant finding out at the pass that dinner was ordered for N$ 1 is not
 * a bug anybody wants to explain. The client's own total is a display; this is
 * the one that gets stored.
 *
 * Availability is checked at the same moment for the same reason: a dish taken
 * off the menu while somebody had the page open must not arrive in the kitchen.
 */
class MenuOrder
{
    /**
     * @param  array<int, array{menu_item_id?: int|string, quantity?: int|string}>  $lines
     *
     * @throws ValidationException when an item is not on this restaurant's menu
     */
    public static function price(Listing $listing, array $lines): self
    {
        $quantities = self::collapse($lines);

        if ($quantities === []) {
            throw ValidationException::withMessages([
                'items' => __('Choose at least one item to order.'),
            ]);
        }

        /** @var Collection<int, MenuItem> $items */
        $items = MenuItem::query()
            ->where('listing_id', $listing->id)
            ->available()
            ->whereIn('id', array_keys($quantities))
            ->get()
            ->keyBy('id');

        $missing = array_diff(array_keys($quantities), $items->keys()->all());

        if ($missing !== []) {
            // Deliberately one message rather than a list of ids: the reader is
            // a hungry person, not an integrator, and the only useful next step
            // is to look at the menu again.
            throw ValidationException::withMessages([
                'items' => __('Something you picked is no longer on the menu. Please check your order and try again.'),
            ]);
        }

        $rows = [];
        $total = 0.0;

        foreach ($quantities as $id => $quantity) {
            $item = $items[$id];
            $lineTotal = round($item->price * $quantity, 2);
            $total += $lineTotal;

            $rows[] = [
                'menu_item_id' => $item->id,
                'name' => $item->name,
                'quantity' => $quantity,
                'unit_price' => $item->price,
                'line_total' => $lineTotal,
                'currency' => $item->currency,
            ];
        }

        /** @var MenuItem $first */
        $first = $items->first();

        return new self($rows, round($total, 2), $first->currency);
    }

    /**
     * @param  array<int, array{menu_item_id: int, name: string, quantity: int, unit_price: float, line_total: float, currency: string}>  $lines
     */
    private function __construct(
        public readonly array $lines,
        public readonly float $total,
        public readonly string $currency,
    ) {}

    /**
     * Write the lines onto a request that has just been created.
     *
     * The total goes onto the inquiry itself as well as into the lines, because
     * `total_amount` is what every existing reader — the partner mail, the
     * admin table, the promotion path — already looks at for "what is this
     * worth", and an order that reported nothing there would be invisible to
     * all of them.
     */
    public function attachTo(Inquiry $inquiry): void
    {
        $inquiry->items()->createMany($this->lines);

        $inquiry->forceFill([
            'total_amount' => $this->total,
            'currency' => $this->currency,
        ])->save();
    }

    /**
     * Sum the posted lines per menu item, dropping anything ordered zero times.
     *
     * Summing rather than overwriting: the same dish appearing twice is a
     * client that built its payload from two places, and "two starters" is the
     * only reading of it that does not silently lose one.
     *
     * Both keys are declared optional because they genuinely are here: the
     * controller's validation guarantees them, but this class is the write path
     * for orders and is not obliged to trust its only current caller. A missing
     * or zero value is dropped rather than trusted, which is also what makes an
     * empty order fail as "choose at least one item" instead of as a total of
     * nothing.
     *
     * @param  array<int, array{menu_item_id?: int|string, quantity?: int|string}>  $lines
     * @return array<int, int>
     */
    private static function collapse(array $lines): array
    {
        $quantities = [];

        foreach ($lines as $line) {
            $id = (int) ($line['menu_item_id'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($id <= 0 || $quantity <= 0) {
                continue;
            }

            $quantities[$id] = ($quantities[$id] ?? 0) + $quantity;
        }

        return $quantities;
    }
}
