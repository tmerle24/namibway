<?php

use App\Connectors\ResConnect\DTOs\AvailabilityRequest;
use App\Connectors\ResConnect\DTOs\ReservationRequest;
use App\Connectors\ResConnect\ResConnectClient;
use App\Connectors\ResConnect\ResConnectConnector;
use App\Enums\ReservationStatus;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

beforeEach(function () {
    $this->client = Mockery::mock(ResConnectClient::class);
    $this->connector = new ResConnectConnector($this->client);
});

afterEach(fn () => Mockery::close());

it('returns available room types on successful availability check', function () {
    $this->client
        ->shouldReceive('get')
        ->once()
        ->with('availability', Mockery::on(fn ($q) => $q['property_code'] === 'PROP001'))
        ->andReturn([
            'room_types' => [
                [
                    'code' => 'CHALET',
                    'name' => 'Bush Chalet',
                    'available' => 3,
                    'rate_per_night' => 2500.00,
                    'currency' => 'NAD',
                    'total_rate' => 7500.00,
                    'meal_plan' => 'FB',
                ],
            ],
        ]);

    $request = new AvailabilityRequest(
        propertyCode: 'PROP001',
        checkIn: Carbon::parse('2026-09-01'),
        checkOut: Carbon::parse('2026-09-04'),
        adults: 2,
    );

    $response = $this->connector->checkAvailability($request);

    expect($response->available)->toBeTrue()
        ->and($response->roomTypes)->toHaveCount(1)
        ->and($response->roomTypes[0]->code)->toBe('CHALET')
        ->and($response->roomTypes[0]->ratePerNight)->toBe(2500.0)
        ->and($response->roomTypes[0]->currency)->toBe('NAD');
});

it('filters out unavailable room types', function () {
    $this->client
        ->shouldReceive('get')
        ->andReturn([
            'room_types' => [
                ['code' => 'A', 'name' => 'Type A', 'available' => 0, 'rate_per_night' => 1000, 'currency' => 'NAD', 'total_rate' => 2000],
                ['code' => 'B', 'name' => 'Type B', 'available' => 2, 'rate_per_night' => 1500, 'currency' => 'NAD', 'total_rate' => 3000],
            ],
        ]);

    $request = new AvailabilityRequest('PROP001', Carbon::tomorrow(), Carbon::tomorrow()->addDays(2));
    $response = $this->connector->checkAvailability($request);

    expect($response->available)->toBeTrue()
        ->and($response->roomTypes)->toHaveCount(1)
        ->and($response->roomTypes[0]->code)->toBe('B');
});

it('returns unavailable when no room types exist', function () {
    $this->client->shouldReceive('get')->andReturn(['room_types' => []]);

    $request = new AvailabilityRequest('PROP001', Carbon::tomorrow(), Carbon::tomorrow()->addDays(2));
    $response = $this->connector->checkAvailability($request);

    expect($response->available)->toBeFalse()
        ->and($response->roomTypes)->toBeEmpty();
});

it('handles API errors gracefully on availability check', function () {
    $httpResponse = Mockery::mock(Response::class);
    $httpResponse->shouldReceive('status')->andReturn(503);
    $httpResponse->shouldReceive('body')->andReturn('Service unavailable');

    $this->client
        ->shouldReceive('get')
        ->andThrow(new RequestException($httpResponse));

    $request = new AvailabilityRequest('PROP001', Carbon::tomorrow(), Carbon::tomorrow()->addDays(2));
    $response = $this->connector->checkAvailability($request);

    expect($response->available)->toBeFalse()
        ->and($response->error)->toContain('503');
});

it('creates a confirmed reservation', function () {
    $this->client
        ->shouldReceive('post')
        ->once()
        ->with('reservations', Mockery::on(fn ($p) => $p['property_code'] === 'PROP001' && $p['source'] === 'namibway'))
        ->andReturn([
            'status' => 'confirmed',
            'reservation_id' => 'RES-12345',
            'confirmation_number' => 'CNF-99999',
            'total_amount' => 7500.00,
            'currency' => 'NAD',
        ]);

    $request = new ReservationRequest(
        propertyCode: 'PROP001',
        roomTypeCode: 'CHALET',
        checkIn: Carbon::parse('2026-09-01'),
        checkOut: Carbon::parse('2026-09-04'),
        adults: 2,
        children: 0,
        guestName: 'Anna Müller',
        guestEmail: 'anna@example.com',
        guestPhone: '+49123456789',
        inquiryId: 42,
    );

    $response = $this->connector->createReservation($request);

    expect($response->success)->toBeTrue()
        ->and($response->status)->toBe(ReservationStatus::Confirmed)
        ->and($response->connectorReference)->toBe('RES-12345')
        ->and($response->confirmationNumber)->toBe('CNF-99999')
        ->and($response->totalAmount)->toBe(7500.0);
});

it('returns on_request status when property needs manual confirmation', function () {
    $this->client->shouldReceive('post')->andReturn([
        'status' => 'on_request',
        'reservation_id' => 'RES-99',
    ]);

    $request = new ReservationRequest(
        propertyCode: 'PROP001', roomTypeCode: 'CHALET',
        checkIn: Carbon::tomorrow(), checkOut: Carbon::tomorrow()->addDays(3),
        adults: 2, children: 0,
        guestName: 'Test Guest', guestEmail: 'test@example.com', guestPhone: '+1234567890',
    );

    $response = $this->connector->createReservation($request);

    expect($response->success)->toBeTrue()
        ->and($response->status)->toBe(ReservationStatus::OnRequest);
});

it('returns failed response on reservation API error', function () {
    $httpResponse = Mockery::mock(Response::class);
    $httpResponse->shouldReceive('status')->andReturn(422);
    $httpResponse->shouldReceive('body')->andReturn('Validation error');

    $this->client->shouldReceive('post')->andThrow(new RequestException($httpResponse));

    $request = new ReservationRequest(
        propertyCode: 'PROP001', roomTypeCode: 'CHALET',
        checkIn: Carbon::tomorrow(), checkOut: Carbon::tomorrow()->addDays(3),
        adults: 2, children: 0,
        guestName: 'Test', guestEmail: 'test@example.com', guestPhone: '+1234',
    );

    $response = $this->connector->createReservation($request);

    expect($response->success)->toBeFalse()
        ->and($response->status)->toBe(ReservationStatus::Failed)
        ->and($response->error)->toContain('422');
});

it('can cancel a reservation', function () {
    $this->client->shouldReceive('post')->with('reservations/RES-12345/cancel')->andReturn([]);

    expect($this->connector->cancelReservation('RES-12345'))->toBeTrue();
});

it('returns false when cancellation fails', function () {
    $httpResponse = Mockery::mock(Response::class);
    $httpResponse->shouldReceive('status')->andReturn(404);
    $httpResponse->shouldReceive('body')->andReturn('Not found');

    $this->client->shouldReceive('post')->andThrow(new RequestException($httpResponse));

    expect($this->connector->cancelReservation('RES-GHOST'))->toBeFalse();
});

it('reports the correct connector type', function () {
    expect($this->connector->getConnectorType())->toBe('resconnect');
});
