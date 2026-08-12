<?php

namespace App\Enums;

/**
 * Where a website subscription stands.
 *
 * Four states and no more, because the collection is not part of this. There is
 * no payment provider for this market yet and billing runs by invoice, so the
 * machine has to work with a human deciding "they have paid" — and it must go
 * on working unchanged when a provider is finally plugged in behind it. Nothing
 * here names a price, a currency or a gateway for exactly that reason.
 */
enum SubscriptionStatus: string
{
    /** The owner has asked for a website. Nobody has agreed to anything yet. */
    case Requested = 'requested';

    /** They are a customer. This is the one state that unlocks anything. */
    case Active = 'active';

    /**
     * Held: unpaid, or on hold at the customer's request. The website keeps
     * existing — suspension takes the entitlement away, not the content.
     */
    case Suspended = 'suspended';

    /** Over. Kept rather than deleted, because it is the record of a customer. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Requested => 'warning',
            self::Active => 'success',
            self::Suspended => 'danger',
            self::Cancelled => 'gray',
        };
    }

    /**
     * Whether this state entitles the partner to the product.
     *
     * The single question the rest of the application asks. Everything else
     * here is bookkeeping.
     */
    public function isEntitled(): bool
    {
        return $this === self::Active;
    }
}
