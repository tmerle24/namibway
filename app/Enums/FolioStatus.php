<?php

namespace App\Enums;

/**
 * Whether a stay still owes anything.
 *
 * Stored on the reservation as a result of the payments against it, never
 * computed at the call site — see the folio columns migration.
 *
 * A stay nobody has priced yet is the one awkward case, and it is answered
 * deliberately: with no total there is nothing to be square with, so money
 * received against it reads as `PartPaid` rather than `Paid` or `Overpaid`.
 * "Something came in and the price is missing" is the true statement, and it
 * is the one that gets the stay onto the unpaid list where somebody looks at
 * it.
 */
enum FolioStatus: string
{
    case Unpaid = 'unpaid';

    case PartPaid = 'part_paid';

    case Paid = 'paid';

    /** More came in than was owed. A refund is due, or the price is wrong. */
    case Overpaid = 'overpaid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::PartPaid => 'Part paid',
            self::Paid => 'Paid',
            self::Overpaid => 'Overpaid',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'danger',
            self::PartPaid => 'warning',
            self::Paid => 'success',
            self::Overpaid => 'info',
        };
    }

    /** Whether somebody still has to chase this one. */
    public function isSettled(): bool
    {
        return $this === self::Paid;
    }

    /**
     * The statuses an "unpaid" list shows.
     *
     * Overpaid is on it. The property owes the guest rather than the other way
     * round, but it is equally something nobody should discover a year later.
     *
     * @return array<int, self>
     */
    public static function outstanding(): array
    {
        return [self::Unpaid, self::PartPaid, self::Overpaid];
    }
}
