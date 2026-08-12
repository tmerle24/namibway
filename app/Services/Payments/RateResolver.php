<?php

namespace App\Services\Payments;

use App\Models\Listing;
use App\Models\Partner;
use App\Models\PaymentSettings;
use App\Services\Payments\DTOs\ResolvedRates;
use InvalidArgumentException;

/**
 * What commission we take and what deposit is asked for, for one property.
 *
 * One rule, applied twice:
 *
 *     listing override → partner override → platform setting → config default
 *
 * with **null meaning inherit** at every level. Deliberately the same rule the
 * availability calendar uses for its sparse overrides, chosen again for the
 * same reasons: nothing has to be written to say "unchanged", the common case
 * lives in one place, and changing the platform rate moves everybody who has
 * not negotiated separately.
 *
 * Read only, and it decides nothing about *when* a rate applies. What a
 * booking earned is frozen onto the reservation at the moment it is taken
 * (see the migration); this is what answers the question at that moment, and
 * what a screen shows when somebody asks what a property currently pays.
 */
class RateResolver
{
    public function for(Listing $listing): ResolvedRates
    {
        $partner = $listing->partner;
        $settings = PaymentSettings::current();

        return new ResolvedRates(
            commissionRate: $this->resolve(
                $listing->commission_rate,
                $partner?->commission_rate,
                $settings->commission_rate,
            ),
            depositRate: $this->resolve(
                $listing->deposit_rate,
                $partner?->deposit_rate,
                $settings->deposit_rate,
            ),
            minimumDepositRate: $settings->effectiveMinimumDepositRate(),
        );
    }

    /**
     * The same chain read from the partner alone, for a screen that is about
     * the business rather than about one of its properties.
     */
    public function forPartner(Partner $partner): ResolvedRates
    {
        $settings = PaymentSettings::current();

        return new ResolvedRates(
            commissionRate: $this->resolve(null, $partner->commission_rate, $settings->commission_rate),
            depositRate: $this->resolve(null, $partner->deposit_rate, $settings->deposit_rate),
            minimumDepositRate: $settings->effectiveMinimumDepositRate(),
        );
    }

    /**
     * Whether a partner may set this deposit for themselves, and if not, why.
     *
     * Returns null when it is allowed. A sentence rather than a boolean,
     * because "15 % is the lowest deposit you can set" is actionable and
     * "invalid" is not — and because the floor is not a fixed number a partner
     * could have memorised: it follows our commission.
     *
     * Zero is refused separately and by name. It is not a smaller number; it
     * is the arrangement where we collect nothing and have to invoice for our
     * commission, which moves collection risk onto us. Slice 4 adds the
     * per-partner unlock; until then nobody may reach it.
     */
    public function refuseDeposit(Partner $partner, float $rate): ?string
    {
        $floor = PaymentSettings::current()->effectiveMinimumDepositRate();

        if ($rate < 0 || $rate > 100) {
            return 'A deposit is a percentage of the booking, so it has to be between 0 and 100.';
        }

        if ($rate < $floor) {
            return 'The lowest deposit you can set is '.$this->formatRate($floor).'%. '
                .'Below that we would be collecting less than our commission, which is an arrangement we agree case by case.';
        }

        return null;
    }

    /**
     * @throws InvalidArgumentException when the deposit is not one this partner may set
     */
    public function assertDepositAllowed(Partner $partner, float $rate): void
    {
        $refusal = $this->refuseDeposit($partner, $rate);

        if ($refusal !== null) {
            throw new InvalidArgumentException($refusal);
        }
    }

    /**
     * Most specific wins; null falls through. Written out rather than done with
     * `??` chaining so the order is legible as the rule it is.
     */
    private function resolve(?float $listing, ?float $partner, float $platform): float
    {
        return $listing ?? $partner ?? $platform;
    }

    /** 15 rather than 15.000, and 4.75 rather than 4.750. */
    private function formatRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 3, '.', ''), '0'), '.');
    }
}
