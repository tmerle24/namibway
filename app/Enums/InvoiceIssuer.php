<?php

namespace App\Enums;

/**
 * Whose document this is — whose name is at the top, whose VAT number, whose
 * bank details.
 *
 * A column rather than an assumption, because it genuinely differs per
 * settlement model (PAYMENTS.md §4): under agency and under the balance half
 * of split the property invoices the guest, under merchant we do, and our
 * commission is a separate document we address to the partner.
 *
 * Not to be confused with PaymentCollector, which is about one *payment*: who
 * the money went to. The two usually agree and are allowed not to — a property
 * can invoice a guest for a stay whose deposit was paid to us.
 */
enum InvoiceIssuer: string
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
