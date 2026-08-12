<?php

namespace App\Enums;

/**
 * How certain the money is.
 *
 * The one field on a payment that may change after creation — see the payments
 * migration. `recorded → cleared` is a fact arriving late, not a fact being
 * rewritten, which is why it is allowed where editing an amount is not.
 *
 * Not to be confused with FolioStatus, which is about a *stay* rather than a
 * payment: this says "did this money really arrive", that says "does this stay
 * still owe anything".
 */
enum PaymentStatus: string
{
    /** Somebody's word that the money moved. An EFT the guest says they sent. */
    case Recorded = 'recorded';

    /** The money's own word. Cash in the drawer, a confirmed capture. */
    case Cleared = 'cleared';

    /**
     * It did not arrive after all — a bounced transfer, a declined capture.
     *
     * Failed rather than deleted, because a payment that was expected and did
     * not come is exactly the thing somebody needs to find later.
     */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Recorded => 'Recorded',
            self::Cleared => 'Cleared',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Recorded => 'warning',
            self::Cleared => 'success',
            self::Failed => 'danger',
        };
    }

    /**
     * Whether this payment moves the balance.
     *
     * A recorded payment does. A desk handed an envelope of cash is not
     * waiting for a bank, and a stay that reads unpaid because nobody has
     * ticked a second box is a stay somebody gets asked to pay twice.
     */
    public function countsTowardsBalance(): bool
    {
        return $this !== self::Failed;
    }

    /**
     * Whether the money itself has confirmed, as opposed to somebody saying
     * so. The difference between the two is what a property chasing an EFT
     * needs to see, and it is why this is a field rather than an assumption.
     */
    public function isCertain(): bool
    {
        return $this === self::Cleared;
    }

    /** @return array<int, self> */
    public static function counting(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => $status->countsTowardsBalance()));
    }
}
