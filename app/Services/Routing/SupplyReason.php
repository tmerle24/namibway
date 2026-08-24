<?php

namespace App\Services\Routing;

use App\Enums\SupplyService;

/**
 * Why this stop is worth naming — which is the whole of what makes a supply
 * stop different from an attraction.
 *
 * A traveller does not want a list of filling stations; they want to be told,
 * once, that this is the last one. So a stop never travels without the reason
 * it was named, and the reason is what the chip actually says: "last fuel for
 * ≈240 km", not "there is fuel here".
 */
final readonly class SupplyReason
{
    public function __construct(
        public SupplyService $service,
        /** How far the road ahead goes without this service, in kilometres. */
        public float $gapKm,
        /**
         * Whether what lies in that gap is a stage the traveller cooks for
         * themselves at. The gap alone would not have named this stop — it is
         * the self-catering night that makes a supermarket matter, and the
         * only case where a stop is named for something other than distance.
         */
        public bool $beforeSelfCatering = false,
    ) {}
}
