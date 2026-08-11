<?php

namespace App\Services\Pricing;

use App\Enums\ChargeBase;
use App\Enums\ChargeBasis;
use App\Models\Charge;
use App\Models\Listing;
use Carbon\CarbonInterface;

/**
 * Turns a finished price into the taxes, levies and fees on top of it.
 *
 * The last link in the chain, and the one that must never reach backwards:
 *
 *     rate → price → discount → **charges** → total
 *
 * Everything it is allowed to know arrives in a ChargeableStay. It cannot see
 * the rate plan, the nights or the occupancy machinery, so no charge can ever
 * become a hidden part of how a night is priced — which is the failure that
 * makes a pricing system unauditable, because the number on the screen then
 * has no single explanation.
 *
 * ## Two kinds of charge, and only one changes the total
 *
 * **Added** charges are what the guest pays on top: a park permit, a
 * conservancy fee, VAT where the lodge quotes net rates.
 *
 * **Included** charges are already inside the rate the lodge entered — the
 * common case in this market, where a published rate is VAT inclusive. They
 * are extracted rather than added, so the invoice can name the tax without the
 * total moving. Extracting is the standard gross-to-net step: for 15% VAT
 * inside 1,150, the tax is 1,150 − 1,150 ÷ 1.15 = 150.
 *
 * ## No tax on tax, unless a lodge says so
 *
 * Every charge works from the stay amount by default, so two percentages never
 * compound by accident. A charge marked ChargeBase::StayPlusPreceding works
 * from the stay plus everything sorted above it, which is how VAT sits on top
 * of a tourism levy the lodge passes on. The order is the sort order the lodge
 * already sees on its own screen; nothing infers it.
 */
class ChargeCalculator
{
    /**
     * @return array<int, ComputedCharge> in invoice order
     */
    public function for(Listing $listing, CarbonInterface $checkIn, ChargeableStay $stay): array
    {
        return $this->compute(Charge::forStay($listing, $checkIn)->all(), $stay);
    }

    /**
     * @param  array<int, Charge>  $charges
     * @return array<int, ComputedCharge>
     */
    public function compute(array $charges, ChargeableStay $stay): array
    {
        $computed = [];

        // Only *added* charges accumulate. An included one is already inside
        // the stay amount, so counting it here would tax it twice.
        $runningAdded = 0.0;

        foreach ($charges as $index => $charge) {
            $base = $charge->base === ChargeBase::StayPlusPreceding
                ? $stay->stayAmount + $runningAdded
                : $stay->stayAmount;

            $amount = $this->amountFor($charge, $stay, $base);

            if ($amount <= 0.0) {
                // A charge that comes to nothing — a per-person fee on a stay
                // with no chargeable guests — is left off the invoice rather
                // than printed as a zero somebody has to think about.
                continue;
            }

            $computed[] = new ComputedCharge(
                charge: $charge,
                name: $charge->name,
                kind: $charge->kind,
                basis: $charge->basis,
                rate: $charge->rate,
                baseAmount: round($base, 2),
                amount: $amount,
                isIncluded: $charge->is_included,
                sort: $charge->sort !== 0 ? $charge->sort : $index,
            );

            if (! $charge->is_included) {
                $runningAdded += $amount;
            }
        }

        return $computed;
    }

    /**
     * What the guest pays on top of the stay.
     *
     * @param  array<int, ComputedCharge>  $computed
     */
    public function addedTotal(array $computed): float
    {
        $total = 0.0;

        foreach ($computed as $charge) {
            if (! $charge->isIncluded) {
                $total += $charge->amount;
            }
        }

        return round($total, 2);
    }

    private function amountFor(Charge $charge, ChargeableStay $stay, float $base): float
    {
        $amount = match ($charge->basis) {
            ChargeBasis::Percentage => $charge->is_included
                // Extracted from a price that already contains it, which is
                // not the same arithmetic as adding it: 15% of the gross is
                // more than the tax inside the gross.
                ? $base - ($base / (1 + ($charge->rate / 100)))
                : $base * ($charge->rate / 100),
            ChargeBasis::PerPersonPerNight => $charge->rate
                * $stay->guests($charge->charges_children)
                * $stay->nights,
            ChargeBasis::PerUnitPerNight => $charge->rate * $stay->unitNights,
            ChargeBasis::PerBooking => $charge->rate,
        };

        // A fixed charge said to be inside the rate cannot be more than the
        // rate: that would make the stay itself cost a negative amount, and
        // the honest reading of the lodge's claim is "up to all of it".
        if ($charge->is_included && ! $charge->basis->isPercentage()) {
            $amount = min($amount, $base);
        }

        return round(max(0.0, $amount), 2);
    }
}
