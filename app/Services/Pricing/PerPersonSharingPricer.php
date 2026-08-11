<?php

namespace App\Services\Pricing;

use App\Enums\GuestKind;
use App\Exceptions\Pricing\UnpriceableStayException;

/**
 * A price per person sharing a room, plus a supplement for somebody travelling
 * alone. How most Namibian lodges and safari camps quote, and the reason this
 * strategy exists at all.
 *
 * The nightly rate on the calendar is read as **the price of one adult
 * sharing**. Everybody else is a share of it: a child category set to 50% pays
 * half, an infant at 0% pays nothing. One number per night therefore prices
 * every combination of guests, and a season change stays one number to edit —
 * which is exactly why rate sheets are written this way.
 *
 * The single supplement applies when one person has the room to themselves,
 * not when there is one adult: an adult with two children is not travelling
 * alone, and charging them a supplement is the sort of thing a guest notices.
 */
final class PerPersonSharingPricer implements NightPricer
{
    /** The parameters this strategy reads out of the rate plan's config. */
    public const SUPPLEMENT_AMOUNT = 'single_supplement_amount';

    public const SUPPLEMENT_PERCENT = 'single_supplement_percent';

    public function price(NightPricingContext $context): float
    {
        $categories = $context->categories;
        $occupancy = $context->occupancy;

        if ($occupancy->isEmpty()) {
            throw UnpriceableStayException::occupancyRequired(
                $context->bookableUnit->name,
                $context->planLabel(),
            );
        }

        $total = 0.0;

        foreach ($occupancy->counts as $categoryId => $people) {
            // An unknown category pays the full share. It can only happen to a
            // stay whose category was removed afterwards, and the safe
            // direction there is the price the guest was quoted.
            $percent = $categories[$categoryId]->charge_percent ?? 100.0;

            $total += $people * $context->baseRate * ($percent / 100);
        }

        if ($this->travellingAlone($context)) {
            $total += $this->supplement($context);
        }

        return round($total, 2);
    }

    /**
     * Alone means one person in the room. An infant in a cot does not make a
     * single traveller a couple, so the count is the same one the tariff uses.
     */
    private function travellingAlone(NightPricingContext $context): bool
    {
        return $context->occupancy->occupancyCount($context->categories) === 1
            && $context->occupancy->countOfKind($context->categories, GuestKind::Adult) === 1;
    }

    /**
     * A flat amount where the plan has one, otherwise a percentage of the
     * per-person rate. Both exist because the market states it both ways; the
     * amount wins when somebody has entered both, because a number typed in
     * full is the more deliberate of the two.
     */
    private function supplement(NightPricingContext $context): float
    {
        $amount = $context->config->float(self::SUPPLEMENT_AMOUNT);

        if ($amount !== null) {
            return $amount;
        }

        $percent = $context->config->float(self::SUPPLEMENT_PERCENT);

        return $percent === null ? 0.0 : $context->baseRate * ($percent / 100);
    }
}
