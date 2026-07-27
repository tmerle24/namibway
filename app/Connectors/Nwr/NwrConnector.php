<?php

namespace App\Connectors\Nwr;

use App\Connectors\Contracts\BookingConnector;
use App\Connectors\ResConnect\DTOs\AvailabilityRequest;
use App\Connectors\ResConnect\DTOs\AvailabilityResponse;
use App\Connectors\ResConnect\DTOs\ReservationRequest;
use App\Connectors\ResConnect\DTOs\ReservationResponse;
use App\Connectors\ResConnect\DTOs\RoomType;
use App\Enums\ReservationStatus;

/**
 * NWR (Namibia Wildlife Resorts) concierge connector.
 *
 * NWR operates state-run camps in Etosha, Sossusvlei, and Fish River Canyon.
 * Their booking system is notoriously unreliable — properties frequently show
 * as full when availability exists. NWR provides no API.
 *
 * This connector implements the concierge model: all NWR requests are routed
 * to the NamibWay team for manual verification and booking on the traveler's
 * behalf. Availability always returns "on request", and reservations are
 * created as NWR_PENDING inquiries for the team to action in Filament.
 */
class NwrConnector implements BookingConnector
{
    private const CONTACT_NOTE = 'NWR availability is checked manually by the NamibWay team. '
        .'Their system frequently shows properties as full when availability exists — '
        .'we verify directly with each camp.';

    public function getConnectorType(): string
    {
        return 'nwr';
    }

    /**
     * NWR availability is always "on request" — no live API exists.
     * Returns a single placeholder room type to trigger the concierge flow.
     */
    public function checkAvailability(AvailabilityRequest $request): AvailabilityResponse
    {
        return new AvailabilityResponse(
            propertyCode: $request->propertyCode,
            available: true,
            roomTypes: [
                new RoomType(
                    code: 'nwr_on_request',
                    name: 'On Request — NamibWay Concierge',
                    available: 1,
                    ratePerNight: 0,
                    currency: 'NAD',
                    totalRate: 0,
                    description: self::CONTACT_NOTE,
                ),
            ],
        );
    }

    /**
     * Creates a concierge reservation request. No API call is made —
     * the InquiryStatus::NwrPending flag in the Inquiry triggers the
     * Filament team task for manual NWR verification.
     */
    public function createReservation(ReservationRequest $request): ReservationResponse
    {
        return new ReservationResponse(
            success: true,
            status: ReservationStatus::OnRequest,
            connectorReference: 'NWR-MANUAL-'.$request->inquiryId,
            confirmationNumber: null,
            totalAmount: null,
            currency: 'NAD',
        );
    }

    /**
     * NWR has no system reference — status is tracked internally via InquiryStatus.
     */
    public function getReservation(string $reference): ReservationResponse
    {
        return new ReservationResponse(
            success: true,
            status: ReservationStatus::OnRequest,
            connectorReference: $reference,
        );
    }

    public function cancelReservation(string $reference): bool
    {
        return true;
    }
}
