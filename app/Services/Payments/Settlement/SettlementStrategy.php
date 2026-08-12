<?php

namespace App\Services\Payments\Settlement;

use App\Enums\InvoiceIssuer;
use App\Enums\SettlementModel;
use App\Models\Reservation;

/**
 * How one settlement model behaves. Three questions and no more — PAYMENTS.md
 * § 2.
 *
 * The narrowness is the point. The folio, the payment records, the invoice,
 * the commission calculation and the moment commission is *earned* are
 * identical in all three models; only the direction and timing of the net
 * transfer differ. Anything wider than these three methods would be a fourth
 * place for the three models to drift into three different answers to "what do
 * we earn", which is exactly what CommissionPolicy exists to prevent.
 */
interface SettlementStrategy
{
    public function model(): SettlementModel;

    /**
     * What we ask the guest for now, at the moment of booking.
     *
     * Zero under the agency model — the guest pays the property and we touch
     * no guest money at all.
     */
    public function collectFromGuest(Reservation $reservation): float;

    /**
     * What is owed between us and the partner once the stay is earned.
     *
     * Signed from the partner's point of view: positive means we owe them,
     * negative means they owe us. See SettlementBalance for why one signed
     * number rather than an amount and a direction.
     */
    public function balanceWithPartner(Reservation $reservation): SettlementBalance;

    /** Who issues the guest's invoice for the stay. */
    public function guestInvoiceIssuer(): InvoiceIssuer;
}
