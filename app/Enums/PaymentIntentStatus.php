<?php

namespace App\Enums;

/**
 * How far an attempt to take money got.
 *
 * Note what is *not* here: "refunded". A refund is a movement on the folio,
 * not a change to the attempt that originally succeeded — the same reason a
 * refund is a negative payment rather than a deleted one. An intent says what
 * happened once; the ledger says what has happened since.
 */
enum PaymentIntentStatus: string
{
    /** Created, and the guest has not finished with it. */
    case Pending = 'pending';

    /** The money moved. Exactly one payment row comes out of this. */
    case Succeeded = 'succeeded';

    /** The gateway said no — a decline, an expired card, insufficient funds. */
    case Failed = 'failed';

    /** The guest walked away. Not a failure; nobody said no. */
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for the guest',
            self::Succeeded => 'Paid',
            self::Failed => 'Declined',
            self::Abandoned => 'Abandoned',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Abandoned => 'gray',
        };
    }

    /** Whether anything more can happen to this attempt. */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
