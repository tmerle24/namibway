<?php

namespace App\Services\Payments\Settlement;

use App\Enums\SettlementModel;
use App\Models\Listing;
use App\Models\Reservation;
use App\Services\Payments\RateResolver;

/**
 * Resolves the strategy for a partner or a stay — the same shape as
 * ConnectorFactory, on purpose.
 *
 * There is no lookup of a stored model here, because there is no stored model.
 * A booking's deposit rate is frozen onto it, so a stay's arrangement is read
 * off the stay and stays true even after the partner's rate changes; a
 * property that has not booked anything yet resolves through the rate chain.
 */
class SettlementFactory
{
    public function __construct(private readonly RateResolver $rates = new RateResolver) {}

    /**
     * The arrangement a stay was taken under.
     *
     * From the *frozen* deposit rate, not from today's — which is what makes
     * a statement about last season still add up after a renegotiation. A stay
     * taken before rates existed has none, and falls back to the property's
     * current arrangement rather than refusing: it is the only answer
     * available, and it is right for every stay taken since.
     */
    public function for(Reservation $reservation): SettlementStrategy
    {
        $rate = $reservation->deposit_rate;

        if ($rate === null) {
            $listing = $reservation->listing;

            return $listing instanceof Listing
                ? $this->forListing($listing)
                : $this->make(SettlementModel::Split);
        }

        return $this->make(SettlementModel::forDepositRate($rate));
    }

    public function forListing(Listing $listing): SettlementStrategy
    {
        return $this->make(SettlementModel::forDepositRate($this->rates->for($listing)->depositRate));
    }

    public function make(SettlementModel $model): SettlementStrategy
    {
        return match ($model) {
            SettlementModel::Agency => new AgencySettlement,
            SettlementModel::Split => new SplitSettlement,
            SettlementModel::Merchant => new MerchantSettlement,
        };
    }
}
