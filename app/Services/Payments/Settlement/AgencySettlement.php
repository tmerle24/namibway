<?php

namespace App\Services\Payments\Settlement;

use App\Enums\InvoiceIssuer;
use App\Enums\SettlementModel;
use App\Models\Reservation;

/**
 * The property collects, and we invoice them for commission afterwards.
 *
 * The simplest model to sell to a suspicious owner — "you keep control of the
 * money" — and the most expensive one for us to operate: it is the only one
 * with collection risk on our side, and the only one that needs a partner
 * statement, payment terms and a dunning process. That asymmetry is why 0 % is
 * unlocked per partner by us rather than typed by them.
 */
class AgencySettlement implements SettlementStrategy
{
    public function model(): SettlementModel
    {
        return SettlementModel::Agency;
    }

    /** Nothing. We touch no guest money at all under this model. */
    public function collectFromGuest(Reservation $reservation): float
    {
        return 0.0;
    }

    public function balanceWithPartner(Reservation $reservation): SettlementBalance
    {
        // Negative: the partner owes us. The whole commission, because we
        // collected nothing to keep it out of.
        return new SettlementBalance(
            -(float) ($reservation->commission_amount ?? 0.0),
            $reservation->currency,
        );
    }

    /** Their guest, their money, their document. */
    public function guestInvoiceIssuer(): InvoiceIssuer
    {
        return InvoiceIssuer::Partner;
    }
}
