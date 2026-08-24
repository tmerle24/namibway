<?php

namespace App\Services\Routing;

/**
 * One end of a driving leg, resolved from the name a trip plan carries.
 *
 * `placeId` is what makes "already a stage" answerable: an attraction filed
 * under a place the traveller is spending the night at is something to do
 * there, not something to stop for on the road, and the two must not be
 * confused however close together the coordinates are. Null where the name
 * only resolved to a city — the trip plan still works, it just cannot make
 * that particular exclusion.
 */
final readonly class RoutePoint
{
    public function __construct(
        public float $lat,
        public float $lng,
        public ?int $placeId = null,
    ) {}
}
