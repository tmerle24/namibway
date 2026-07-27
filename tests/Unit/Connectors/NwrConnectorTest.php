<?php

use App\Connectors\Nwr\NwrConnector;
use App\Connectors\ResConnect\DTOs\AvailabilityRequest;
use App\Connectors\ResConnect\DTOs\ReservationRequest;
use App\Enums\ReservationStatus;
use Carbon\Carbon;

beforeEach(function () {
    $this->connector = new NwrConnector;
});

it('reports the correct connector type', function () {
    expect($this->connector->getConnectorType())->toBe('nwr');
});

it('always returns available with a concierge room type', function () {
    $request = new AvailabilityRequest(
        propertyCode: 'NWR-ETOSHA',
        checkIn: Carbon::parse('2026-12-01'),
        checkOut: Carbon::parse('2026-12-05'),
        adults: 2,
    );

    $response = $this->connector->checkAvailability($request);

    expect($response->available)->toBeTrue()
        ->and($response->roomTypes)->toHaveCount(1)
        ->and($response->roomTypes[0]->code)->toBe('nwr_on_request')
        ->and($response->roomTypes[0]->description)->toContain('NamibWay team');
});

it('creates an on_request reservation without an API call', function () {
    $request = new ReservationRequest(
        propertyCode: 'NWR-ETOSHA',
        roomTypeCode: 'nwr_on_request',
        checkIn: Carbon::parse('2026-12-01'),
        checkOut: Carbon::parse('2026-12-05'),
        adults: 2,
        children: 0,
        guestName: 'Klaus Meier',
        guestEmail: 'klaus@example.com',
        guestPhone: '+49177000000',
        inquiryId: 55,
    );

    $response = $this->connector->createReservation($request);

    expect($response->success)->toBeTrue()
        ->and($response->status)->toBe(ReservationStatus::OnRequest)
        ->and($response->connectorReference)->toBe('NWR-MANUAL-55')
        ->and($response->confirmationNumber)->toBeNull();
});

it('returns on_request for getReservation', function () {
    $response = $this->connector->getReservation('NWR-MANUAL-55');

    expect($response->status)->toBe(ReservationStatus::OnRequest)
        ->and($response->connectorReference)->toBe('NWR-MANUAL-55');
});

it('always returns true for cancelReservation', function () {
    expect($this->connector->cancelReservation('NWR-MANUAL-55'))->toBeTrue();
});
