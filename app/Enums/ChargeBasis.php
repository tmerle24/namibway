<?php

namespace App\Enums;

/**
 * How a charge turns a finished price into an amount.
 *
 * The same shape as DiscountType and PricingStrategy, and for the same reason:
 * a closed, small set, each member one calculation. A charge nobody here has
 * seen is one new case, not a migration — and none of them may reach back into
 * how the price was reached. See BOOKING_SYSTEM.md.
 *
 * The four cover what Namibian lodges actually charge: VAT and the tourism
 * levy are percentages, park permits and conservancy fees are per person per
 * night, a bed levy is per unit per night, and a one-off booking fee exists in
 * enough rate sheets to be worth having.
 */
enum ChargeBasis: string
{
    case Percentage = 'percentage';

    case PerPersonPerNight = 'per_person_per_night';

    case PerUnitPerNight = 'per_unit_per_night';

    case PerBooking = 'per_booking';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage of the stay',
            self::PerPersonPerNight => 'Per person, per night',
            self::PerUnitPerNight => 'Per room, per night',
            self::PerBooking => 'Once per booking',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Percentage => 'VAT at 15%, the tourism levy at 2% — a share of what the stay costs.',
            self::PerPersonPerNight => 'A park permit or conservancy fee: an amount for each guest, each night.',
            self::PerUnitPerNight => 'A bed levy: an amount for each room, each night, however many people are in it.',
            self::PerBooking => 'A single amount on the whole booking, whatever its length.',
        };
    }

    /** Whether the entered number is a percentage rather than money. */
    public function isPercentage(): bool
    {
        return $this === self::Percentage;
    }
}
