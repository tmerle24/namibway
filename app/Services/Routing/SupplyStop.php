<?php

namespace App\Services\Routing;

use App\Models\SupplyPoint;

/**
 * One supply point, named on one leg, with the reasons it was named.
 *
 * A DTO rather than the cloned-model-with-extra-attributes trick RouteStopFinder
 * uses for attractions, because a supply point genuinely carries more than one
 * reason at once — the Solitaire pumps and the Solitaire shop are one row and
 * two separate things about to run out.
 */
final readonly class SupplyStop
{
    /**
     * @param  array<int, SupplyReason>  $reasons  strongest first
     */
    public function __construct(
        public SupplyPoint $point,
        public array $reasons,
        /** Where along the whole route this sits, in kilometres from the start. */
        public float $position,
        /** Roughly what going there costs on top of the leg. */
        public float $detourKm,
    ) {}
}
