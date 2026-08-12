<?php

namespace App\Services\Payments\DTOs;

use App\Support\Money;

/**
 * The two rates that apply to a property right now, and the floor beneath the
 * deposit.
 *
 * A value object, and read-only. What a *booking* earned is a different thing
 * and lives frozen on the reservation — this is the answer at one moment, and
 * treating it as durable is exactly the mistake the freeze exists to prevent.
 */
class ResolvedRates
{
    public function __construct(
        public readonly float $commissionRate,
        public readonly float $depositRate,
        public readonly float $minimumDepositRate,
    ) {}

    /**
     * What we earn on a stay.
     *
     * The base is the stay **before** tax and levy — PAYMENTS.md § 3. Charging
     * commission on the government's VAT and the NTB's tourism levy is
     * indefensible in front of an operator, and `ChargeKind` already
     * distinguishes those from a property's own fees, which is what makes this
     * expressible rather than a guess.
     */
    public function commissionOn(float $stayBeforeTaxAndLevy): float
    {
        return $this->applyRate($stayBeforeTaxAndLevy, $this->commissionRate);
    }

    /**
     * What the guest is asked for up front.
     *
     * A share of the whole folio, tax included — a deposit is a commitment
     * signal and a cash-flow arrangement, not a share of revenue, so it has a
     * different base from the commission on purpose.
     */
    public function depositOn(float $folioTotal): float
    {
        return $this->applyRate($folioTotal, $this->depositRate);
    }

    /**
     * A percentage of an amount, rounded to the cent exactly once.
     *
     * `amount × rate` where the rate is a percentage already gives hundredths
     * of the amount — which is to say cents — so one rounding turns it into
     * money. Doing it as `amount × rate / 100` and rounding after would round
     * twice on some values and land a cent away from what a spreadsheet says.
     */
    private function applyRate(float $amount, float $rate): float
    {
        return Money::fromCents((int) round($amount * $rate));
    }

    /**
     * Whether the deposit covers the commission.
     *
     * The case worth naming: at exactly the floor these cancel and no money
     * has to move between us and the partner at all — no payout run, no
     * statement, no reconciliation. That is why the floor defaults to the
     * commission rate rather than to zero.
     */
    public function depositCoversCommission(): bool
    {
        return $this->depositRate >= $this->commissionRate;
    }
}
