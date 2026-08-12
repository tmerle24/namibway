<?php

namespace App\Services\Payments\Settlement;

use App\Enums\InvoiceIssuer;
use App\Enums\SettlementModel;
use App\Models\Reservation;
use App\Support\Money;

/**
 * A deposit to us, the balance at the property — the default.
 *
 * The commission collects itself: it is already inside the deposit we are
 * holding, so nothing is invoiced and nothing is chased. And where the deposit
 * is set exactly equal to the commission, **no money moves between us and the
 * partner at all** — no payout run, no statement, no reconciliation. That is a
 * real operational saving and the reason this is the default and the reason
 * the deposit floor follows the commission rate.
 *
 * Deposit and commission stay two separate numbers even so. A 5 % deposit is a
 * weak commitment signal, and part of the point of taking one is that the
 * partner can see the guest is serious; where the deposit exceeds the
 * commission the difference is genuinely owed to the partner, which is model
 * B's payout machinery at a smaller amount.
 */
class SplitSettlement implements SettlementStrategy
{
    public function model(): SettlementModel
    {
        return SettlementModel::Split;
    }

    public function collectFromGuest(Reservation $reservation): float
    {
        return (float) ($reservation->deposit_amount ?? 0.0);
    }

    public function balanceWithPartner(Reservation $reservation): SettlementBalance
    {
        $deposit = Money::cents((float) ($reservation->deposit_amount ?? 0.0));
        $commission = Money::cents((float) ($reservation->commission_amount ?? 0.0));

        // What is left of the deposit once our commission is out of it. Zero
        // at the floor, which is the case this model exists for.
        return new SettlementBalance(
            Money::fromCents($deposit - $commission),
            $reservation->currency,
        );
    }

    /**
     * The property. The guest's contract is with them for the stay — we are
     * holding a deposit against it, not selling the room.
     */
    public function guestInvoiceIssuer(): InvoiceIssuer
    {
        return InvoiceIssuer::Partner;
    }
}
