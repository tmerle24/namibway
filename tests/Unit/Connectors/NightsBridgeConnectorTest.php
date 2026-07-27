<?php

use App\Connectors\NightsBridge\NightsBridgeClient;
use App\Connectors\NightsBridge\NightsBridgeConnector;
use App\Connectors\ResConnect\DTOs\AvailabilityRequest;
use App\Connectors\ResConnect\DTOs\ReservationRequest;
use App\Enums\ReservationStatus;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

beforeEach(function () {
    $this->client = Mockery::mock(NightsBridgeClient::class);
    $this->connector = new NightsBridgeConnector($this->client);
});

afterEach(fn () => Mockery::close());

it('reports the correct connector type', function () {
    expect($this->connector->getConnectorType())->toBe('nightsbridge');
});

it('returns available rooms on successful availability check', function () {
    $this->client->shouldReceive('get')
        ->once()
        ->with('availability', Mockery::on(fn ($q) => $q['BBId'] === 'BB123'))
        ->andReturn([
            'Rooms' => [
                ['RoomId' => 'R1', 'RoomName' => 'Garden Suite', 'Availability' => 2, 'Rate' => 1800, 'Currency' => 'NAD', 'TotalRate' => 5400],
                ['RoomId' => 'R2', 'RoomName' => 'Pool Room', 'Availability' => 0, 'Rate' => 2200, 'Currency' => 'NAD', 'TotalRate' => 6600],
            ],
        ]);

    $response = $this->connector->checkAvailability(
        new AvailabilityRequest('BB123', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-04'), 2)
    );

    expect($response->available)->toBeTrue()
        ->and($response->roomTypes)->toHaveCount(1)
        ->and($response->roomTypes[0]->code)->toBe('R1')
        ->and($response->roomTypes[0]->ratePerNight)->toBe(1800.0);
});

it('returns unavailable when all rooms are full', function () {
    $this->client->shouldReceive('get')->andReturn([
        'Rooms' => [
            ['RoomId' => 'R1', 'RoomName' => 'Room', 'Availability' => 0, 'Rate' => 1000, 'Currency' => 'NAD', 'TotalRate' => 2000],
        ],
    ]);

    $response = $this->connector->checkAvailability(
        new AvailabilityRequest('BB123', Carbon::tomorrow(), Carbon::tomorrow()->addDays(2))
    );

    expect($response->available)->toBeFalse();
});

it('handles API errors gracefully on availability', function () {
    $httpResponse = Mockery::mock(Response::class);
    $httpResponse->shouldReceive('status')->andReturn(500);
    $httpResponse->shouldReceive('body')->andReturn('Internal Server Error');
    $httpResponse->shouldReceive('toPsrResponse')->andReturn(Mockery::mock(\Psr\Http\Message\ResponseInterface::class));
    $this->client->shouldReceive('get')->andThrow(new RequestException($httpResponse));

    $response = $this->connector->checkAvailability(
        new AvailabilityRequest('BB123', Carbon::tomorrow(), Carbon::tomorrow()->addDays(2))
    );

    expect($response->available)->toBeFalse()
        ->and($response->error)->toContain('500');
});

it('creates a confirmed reservation via NightsBridge', function () {
    $this->client->shouldReceive('post')
        ->once()
        ->with('reservations', Mockery::on(fn ($p) => $p['BBId'] === 'BB123' && $p['Source'] === 'namibway'))
        ->andReturn([
            'Status' => 'confirmed',
            'BookingId' => 'NB-7777',
            'ConfirmationNumber' => 'CNF-NB-001',
            'TotalAmount' => 5400.0,
            'Currency' => 'NAD',
        ]);

    $request = new ReservationRequest(
        propertyCode: 'BB123',
        roomTypeCode: 'R1',
        checkIn: Carbon::parse('2026-10-01'),
        checkOut: Carbon::parse('2026-10-04'),
        adults: 2,
        children: 0,
        guestName: 'Hans Müller',
        guestEmail: 'hans@example.com',
        guestPhone: '+49123456',
        inquiryId: 10,
    );

    $response = $this->connector->createReservation($request);

    expect($response->success)->toBeTrue()
        ->and($response->status)->toBe(ReservationStatus::Confirmed)
        ->and($response->connectorReference)->toBe('NB-7777')
        ->and($response->totalAmount)->toBe(5400.0);
});

it('maps pending/onrequest status correctly', function () {
    $this->client->shouldReceive('post')->andReturn(['Status' => 'OnRequest', 'BookingId' => 'NB-8888']);

    $request = new ReservationRequest(
        'BB123', 'R1',
        Carbon::tomorrow(), Carbon::tomorrow()->addDays(3),
        2, 0, 'Test', 'test@e.com', '+1234',
    );

    $response = $this->connector->createReservation($request);

    expect($response->status)->toBe(ReservationStatus::OnRequest);
});

it('returns false on failed cancellation', function () {
    $httpResponse = Mockery::mock(Response::class);
    $httpResponse->shouldReceive('status')->andReturn(404);
    $httpResponse->shouldReceive('body')->andReturn('Not found');
    $httpResponse->shouldReceive('toPsrResponse')->andReturn(Mockery::mock(\Psr\Http\Message\ResponseInterface::class));
    $this->client->shouldReceive('post')->andThrow(new RequestException($httpResponse));

    expect($this->connector->cancelReservation('NB-GHOST'))->toBeFalse();
});
