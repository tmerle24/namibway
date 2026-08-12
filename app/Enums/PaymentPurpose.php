<?php

namespace App\Enums;

/**
 * What a payment attempt is for.
 *
 * The deposit is the one that matters today: under the split model it is the
 * only thing we collect, and it is what carries our commission. The other two
 * exist so the merchant model has a name for taking the whole folio, and so a
 * property that later asks a guest for the rest through us has somewhere to
 * put it.
 */
enum PaymentPurpose: string
{
    case Deposit = 'deposit';

    case Balance = 'balance';

    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit',
            self::Balance => 'Balance',
            self::Full => 'Full amount',
        };
    }

    /** What a guest reads on a payment page. */
    public function guestLabel(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit for your booking',
            self::Balance => 'Balance of your booking',
            self::Full => 'Your booking',
        };
    }
}
