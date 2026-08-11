<?php

namespace App\Services\Pricing;

use App\Exceptions\Pricing\PromotionUnavailableException;
use App\Models\Listing;
use App\Models\Promotion;

/**
 * Which offer applies to a stay, if any.
 *
 * **At most one.** Promotions do not stack, and that is a decision rather than
 * an omission: stacking is where discount systems stop being auditable, and
 * where a lodge running two reasonable offers accidentally gives sixty percent
 * off a peak-season chalet. Where several public offers fit, the guest gets
 * the best one — which is the only tie-break nobody has to defend.
 *
 * A typed code beats every public offer, even a larger one. Somebody handed
 * that code to that guest for a reason, and quietly substituting a different
 * discount would make the agent's own paperwork wrong.
 */
class PromotionFinder
{
    /**
     * @throws PromotionUnavailableException when a code was given and does not work
     */
    public function find(Listing $listing, StaySummary $stay, ?string $code = null): ?PromotionMatch
    {
        return filled($code)
            ? $this->byCode($listing, trim((string) $code), $stay)
            : $this->bestPublic($listing, $stay);
    }

    /**
     * @throws PromotionUnavailableException
     */
    private function byCode(Listing $listing, string $code, StaySummary $stay): PromotionMatch
    {
        // Case-insensitive: a code is read off a printed voucher or an email,
        // and refusing "summer26" because the lodge typed "SUMMER26" is a
        // support call, not a security measure.
        $promotion = Promotion::query()
            ->where('listing_id', $listing->id)
            ->whereRaw('lower(code) = ?', [mb_strtolower($code)])
            ->with(['ratePlans', 'bookableUnits'])
            ->first();

        if ($promotion === null) {
            throw PromotionUnavailableException::notFound($code);
        }

        // Three separate refusals rather than one, because they have three
        // different answers: wait, try a different code, or change the dates.
        if (! $promotion->is_active) {
            throw PromotionUnavailableException::inactive($code);
        }

        if ($promotion->isExhausted()) {
            throw PromotionUnavailableException::exhausted($code);
        }

        if (! $this->covers($promotion, $stay)) {
            throw PromotionUnavailableException::notApplicable($code);
        }

        return new PromotionMatch($promotion, $promotion->discountOn($stay->total, $stay->nightlyAmounts));
    }

    /**
     * The best offer that applies by itself. A public offer that does not fit
     * is not an error — nobody asked for it — so this returns null rather than
     * refusing anything.
     */
    private function bestPublic(Listing $listing, StaySummary $stay): ?PromotionMatch
    {
        $candidates = Promotion::query()
            ->where('listing_id', $listing->id)
            ->where('is_active', true)
            ->whereNull('code')
            ->with(['ratePlans', 'bookableUnits'])
            ->get();

        $best = null;

        foreach ($candidates as $promotion) {
            if ($promotion->isExhausted() || ! $this->covers($promotion, $stay)) {
                continue;
            }

            $amount = $promotion->discountOn($stay->total, $stay->nightlyAmounts);

            if ($amount <= 0.0) {
                continue;
            }

            if ($best === null || $amount > $best->amount) {
                $best = new PromotionMatch($promotion, $amount);
            }
        }

        return $best;
    }

    private function covers(Promotion $promotion, StaySummary $stay): bool
    {
        return $promotion->appliesTo(
            $stay->checkIn,
            $stay->checkOut,
            $stay->nights(),
            $stay->ratePlanIds,
            $stay->bookableUnitIds,
        );
    }
}
