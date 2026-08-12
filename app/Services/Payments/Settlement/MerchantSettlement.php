<?php

namespace App\Services\Payments\Settlement;

use App\Enums\InvoiceIssuer;
use App\Enums\SettlementModel;
use App\Models\Reservation;
use App\Support\Money;

/**
 * We collect everything and pay the partner the folio less commission.
 *
 * Commission is certain — we simply do not pay it out. In exchange we hold
 * other people's money: payouts, refunds, chargebacks, currency (a guest
 * paying EUR against a lodge paid in NAD) and a genuinely different regulatory
 * posture. **That is a company decision, not a code decision**, and nothing
 * here should be read as it having been made.
 *
 * Some partners will actively want it. A small owner-run lodge with no card
 * facility is exactly the case where "you do everything" is the selling point.
 */
class MerchantSettlement implements SettlementStrategy
{
    public function model(): SettlementModel
    {
        return SettlementModel::Merchant;
    }

    /** The whole folio. */
    public function collectFromGuest(Reservation $reservation): float
    {
        return (float) ($reservation->total_amount ?? 0.0);
    }

    public function balanceWithPartner(Reservation $reservation): SettlementBalance
    {
        $total = Money::cents((float) ($reservation->total_amount ?? 0.0));
        $commission = Money::cents((float) ($reservation->commission_amount ?? 0.0));

        // Positive: we owe the property what we collected less what we earned.
        return new SettlementBalance(
            Money::fromCents($total - $commission),
            $reservation->currency,
        );
    }

    /** We took the money, so the guest's document is ours. */
    public function guestInvoiceIssuer(): InvoiceIssuer
    {
        return InvoiceIssuer::NamibWay;
    }
}
