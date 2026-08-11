<?php

namespace App\Enums;

/**
 * How a reservation reached us. Operationally this is the first thing a lodge
 * asks about a booking it doesn't recognise, and it's what separates the stays
 * that came through NamibWay from the ones staff typed in themselves.
 */
enum ReservationSource: string
{
    /** Came from the traveller-facing site, so it has an Inquiry behind it. */
    case Website = 'website';

    /** Guest standing at the desk. */
    case WalkIn = 'walk_in';

    case Telephone = 'telephone';

    case Email = 'email';

    /** Entered by the partner from their own channel — the catch-all for
     * "we took this booking somewhere else and are recording it here", which
     * during a pilot alongside an existing system is most of them. */
    case PartnerEntered = 'partner_entered';

    public function label(): string
    {
        return match ($this) {
            self::Website => 'Website',
            self::WalkIn => 'Walk-in',
            self::Telephone => 'Telephone',
            self::Email => 'Email',
            self::PartnerEntered => 'Entered by partner',
        };
    }
}
