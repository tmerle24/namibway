<?php

namespace App\Enums;

/**
 * How the money moved.
 *
 * Deliberately short. This is a label on a ledger row, not a routing decision
 * — nothing calculates differently because of it, exactly as with ChargeKind.
 * A longer list (Visa vs Mastercard, which bank the EFT came from) belongs in
 * `reference`, where it costs nothing and constrains nothing.
 *
 * `Eft` is first-class rather than a fallback: it has no callback, somebody
 * marks it received, and it is realistically how a good share of Namibian
 * lodge business is settled today. That is precisely why PaymentStatus
 * separates recorded from cleared.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';

    /** Taken at the property's own terminal — not a gateway capture. */
    case Card = 'card';

    /** A bank transfer. Arrives days late and clears when somebody says so. */
    case Eft = 'eft';

    /** Through a payment provider — see slice 5. */
    case Online = 'online';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Card => 'Card',
            self::Eft => 'Bank transfer',
            self::Online => 'Online',
            self::Other => 'Other',
        };
    }

    /**
     * Whether the money is certain the moment it is entered.
     *
     * Cash in a drawer and a card slip from the property's own terminal are;
     * a bank transfer somebody has been told about is not. This decides the
     * status a new payment starts in, and nothing else.
     */
    public function isImmediate(): bool
    {
        return match ($this) {
            self::Cash, self::Card => true,
            self::Eft, self::Online, self::Other => false,
        };
    }
}
