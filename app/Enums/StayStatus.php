<?php

namespace App\Enums;

/**
 * Where a stay is in its life at the property — the front-desk view.
 *
 * This sits alongside InquiryStatus and replaces nothing. InquiryStatus tracks
 * a traveller's *request* ("did the partner say yes?"); this tracks the *stay*
 * ("is the guest standing here?"). A walk-in has a stay and never had a
 * request, and a declined request never becomes a stay — so the two cannot be
 * folded into one enum. See CLAUDE.md, "How a confirmed Inquiry becomes a
 * Reservation".
 *
 * Not to be confused with App\Enums\ReservationStatus, which is older and
 * means something else entirely: the result a *connector* reports back from a
 * partner's PMS.
 */
enum StayStatus: string
{
    /** Held, but not yet promised to the guest — the state a soft hold is in. */
    case Provisional = 'provisional';

    /** Promised to the guest; the normal state before arrival day. */
    case Confirmed = 'confirmed';

    /** Arriving today, not yet checked in. */
    case DueIn = 'due_in';

    /** Guest is at the property. */
    case InHouse = 'in_house';

    case CheckedOut = 'checked_out';

    /** Arrival day passed with no guest. The room was held, so it stays occupied. */
    case NoShow = 'no_show';

    case Cancelled = 'cancelled';

    /** Cancelled inside the property's penalty window — a commercially different
     * outcome from a plain cancellation, which is why it is its own state
     * rather than a flag. Which window that is resolves per country; see
     * App\Support\CountrySettings. */
    case CancelledLate = 'cancelled_late';

    public function label(): string
    {
        return match ($this) {
            self::Provisional => 'Provisional',
            self::Confirmed => 'Confirmed',
            self::DueIn => 'Due in',
            self::InHouse => 'In house',
            self::CheckedOut => 'Checked out',
            self::NoShow => 'No show',
            self::Cancelled => 'Cancelled',
            self::CancelledLate => 'Cancelled (late)',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Provisional => 'warning',
            self::Confirmed => 'success',
            self::DueIn => 'info',
            self::InHouse => 'primary',
            self::CheckedOut => 'gray',
            self::NoShow => 'danger',
            self::Cancelled, self::CancelledLate => 'gray',
        };
    }

    /**
     * Whether a stay in this state is still holding units.
     *
     * A no-show keeps its units: the room was blocked for a guest who never
     * came, the night is normally charged, and releasing it retroactively
     * would make an occupancy report for a past date change after the fact.
     * Only a cancellation gives units back.
     */
    public function occupiesInventory(): bool
    {
        return ! in_array($this, [self::Cancelled, self::CancelledLate], true);
    }

    /** @return array<int, self> */
    public static function occupying(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => $status->occupiesInventory()));
    }
}
