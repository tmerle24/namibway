<?php

namespace App\Enums;

/**
 * Whose bank account the money landed in.
 *
 * This one column is what makes the split settlement model expressible at all
 * (PAYMENTS.md §2C): a deposit paid to us and a balance paid at the property
 * are two payments on the same folio that differ in exactly this. Without it
 * the folio would balance and nobody could say who is holding the money.
 *
 * It is a fact about a payment, never a setting. What a partner's settlement
 * model *is* gets derived from their deposit share (slice 4); what actually
 * happened to one payment is recorded here.
 */
enum PaymentCollector: string
{
    case NamibWay = 'namibway';

    case Partner = 'partner';

    public function label(): string
    {
        return match ($this) {
            self::NamibWay => 'NamibWay',
            self::Partner => 'The property',
        };
    }
}
