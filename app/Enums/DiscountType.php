<?php

namespace App\Enums;

/**
 * How a promotion turns a finished price into a smaller one.
 *
 * The same shape as PricingStrategy and for the same reason: a closed, small
 * set, each member a calculation. A partner with an offer nobody here has seen
 * is one new case — no migration, and nothing about how a night is priced
 * changes, because a promotion never reaches back into the pricing. It applies
 * *to* a computed price. See BOOKING_SYSTEM.md.
 *
 * Free nights is here rather than dismissed as a special case because "stay 4,
 * pay 3" is what lodges here actually advertise, and expressing it as a
 * percentage would give a different number on every stay length.
 */
enum DiscountType: string
{
    case Percentage = 'percentage';

    case FixedAmount = 'fixed_amount';

    /** Stay four nights, pay for three. */
    case FreeNights = 'free_nights';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage off',
            self::FixedAmount => 'Amount off',
            self::FreeNights => 'Free nights',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Percentage => 'A share off the whole stay — 15% off, 20% off.',
            self::FixedAmount => 'A flat amount off the stay total, in the property’s own currency.',
            self::FreeNights => 'Stay a number of nights, pay for fewer. The cheapest nights are the free ones, which is how it is advertised and the way round that favours the guest.',
        };
    }
}
