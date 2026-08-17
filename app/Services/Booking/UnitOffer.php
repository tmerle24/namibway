<?php

namespace App\Services\Booking;

use App\Models\BookableUnit;
use App\Services\Pricing\ComputedCharge;

/**
 * One bookable unit offered to a traveller, in either date-range or
 * slot-based mode — the generalised successor to RoomOffer.
 *
 * Date-range (accommodation, vehicle): `slots` is empty; `total`, `totalPayable`
 * and `unitsLeft` are set.
 *
 * Slot-based (activity): `slots` lists the departures with their own counts and
 * prices; unit-level `total`, `totalPayable` and `unitsLeft` are null — the
 * slot is the thing being bought, not the unit itself.
 *
 * See BOOKING_BEYOND_ROOMS.md §7.1 for why there is one shape rather than two.
 */
final class UnitOffer
{
    /**
     * @param  int|null  $unitsLeft      Null for slot-based units — use slot.unitsLeft.
     * @param  float|null  $total        Null for slot-based units — use slot.total.
     * @param  float|null  $totalPayable Null for slot-based units.
     * @param  array<int, SlotOffer>  $slots  Empty for date-range units.
     * @param  array<int, ComputedCharge>  $charges
     */
    public function __construct(
        public readonly BookableUnit $bookableUnit,
        public readonly ?int $unitsLeft,
        /** How many periods (nights / days) this unit covers — 1 for slot-based units. */
        public readonly int $periods,
        public readonly string $currency,
        public readonly ?float $total,
        public readonly ?float $totalPayable,
        public readonly array $charges,
        public readonly array $slots,
    ) {}
}
