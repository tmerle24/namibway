<?php

namespace App\Services\Payments;

use App\Enums\StayStatus;

/**
 * When our commission counts as earned, and when it stops counting.
 *
 * Its own class rather than three `if`s inside InventoryWriter, because this
 * is the one rule in the money side that is **commercial rather than
 * technical** and is expected to be argued about. Keeping it here means the
 * argument changes one file and every screen follows.
 *
 * ## The rule, and it is a default rather than a settled question
 *
 * PAYMENTS_BUILD.md § D names "when commission is earned, precisely" as a
 * decision for the business: at confirmation, at the cancellation deadline, or
 * after check-in. It is not answered yet. What is implemented is the reading
 * of PAYMENTS.md § 3 that needs no scheduler and no guesswork:
 *
 * - **Earned when the stay is confirmed.** A confirmed stay is a sale; a
 *   request that is still a question is not.
 * - **Reversed by a plain cancellation.** Nothing happened, so nothing was
 *   earned. The frozen amount stays on the row — only the timestamp is
 *   cleared — so the record of what the booking would have earned survives.
 * - **Kept by a late cancellation and by a no-show.** The property charged the
 *   guest and the room was held; earning nothing on a night somebody paid for
 *   would be us absorbing the property's penalty. `StayStatus` already
 *   separates these two from a plain cancellation, which is what makes the
 *   distinction expressible instead of a guess.
 *
 * The alternative reading — earned once the cancellation window has closed —
 * is defensible and needs a scheduled sweep to move the timestamp on the day
 * the window passes. Worth building when the business has answered; not worth
 * guessing at now.
 */
class CommissionPolicy
{
    /** Whether a stay in this state has earned us anything. */
    public function isEarnedIn(StayStatus $status): bool
    {
        return match ($status) {
            // A provisional hold is a question, not a sale.
            StayStatus::Provisional => false,

            // Nothing happened.
            StayStatus::Cancelled => false,

            // Something did: the guest was charged a penalty, or held a room
            // and did not come. Earning nothing here would mean absorbing the
            // property's penalty on their behalf.
            StayStatus::CancelledLate, StayStatus::NoShow => true,

            StayStatus::Confirmed,
            StayStatus::DueIn,
            StayStatus::InHouse,
            StayStatus::CheckedOut => true,
        };
    }
}
