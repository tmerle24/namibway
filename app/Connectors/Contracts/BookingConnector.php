<?php

namespace App\Connectors\Contracts;

use App\Connectors\ResConnect\DTOs\AvailabilityRequest;
use App\Connectors\ResConnect\DTOs\AvailabilityResponse;
use App\Connectors\ResConnect\DTOs\ReservationRequest;
use App\Connectors\ResConnect\DTOs\ReservationResponse;

interface BookingConnector
{
    /**
     * Check availability for a property.
     */
    public function checkAvailability(AvailabilityRequest $request): AvailabilityResponse;

    /**
     * Create a reservation / booking request.
     */
    public function createReservation(ReservationRequest $request): ReservationResponse;

    /**
     * Retrieve an existing reservation by its connector-side reference.
     */
    public function getReservation(string $reference): ReservationResponse;

    /**
     * Cancel an existing reservation.
     */
    public function cancelReservation(string $reference): bool;

    /**
     * Return the connector identifier (e.g. "resconnect", "nightsbridge").
     */
    public function getConnectorType(): string;
}
