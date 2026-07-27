<?php

use App\Connectors\HopeCloud\DTOs\HopeCloudReservationResponse;
use App\Connectors\HopeCloud\HopeCloudClient;
use App\Connectors\HopeCloud\HopeCloudConnector;
use App\Connectors\ResConnect\DTOs\AvailabilityRequest;
use App\Connectors\ResConnect\DTOs\ReservationRequest;
use App\Enums\ReservationStatus;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

beforeEach(function () {
    $this->client = Mockery::mock(HopeCloudClient::class);
    $this->connector = new HopeCloudConnector($this->client);
});

afterEach(fn () => Mockery::close());

it('reports the correct connector type', function () {
    expect($this->connector->getConnectorType())->toBe('hopecloud');
});

it('returns available rooms with tourism levy surfaced in description', function () {
    $this->client->shouldReceive('get')
        ->once()
        ->with('availability', Mockery::on(fn ($q) => $q['property_id'] === 'HOPE-001'))
        ->andReturn([
            'tourism_levy_rate' => 15.0,
            'room_types' => [
                [
                    'code' => 'CHALET',
                    'name' => 'Bush Chalet',
                    'available' => 2,
                    'rate_per_night' => 2000.0,
                    'currency' => 'NAD',
                    'total_rate' => 6090.0,
                    'description' => 'Comfortable bush chalet',
                ],
            ],
        ]);

    $request = new AvailabilityRequest(
        propertyCode: 'HOPE-001',
        checkIn: Carbon::parse('2026-11-01'),
        checkOut: Carbon::parse('2026-11-04'),
        adults: 2,
        children: 1,
    );

    $response = $this->connector->checkAvailability($request);

    expect($response->available)->toBeTrue()
        ->and($response->roomTypes)->toHaveCount(1)
        ->and($response->roomTypes[0]->description)->toContain('Tourism Levy')
        ->and($response->roomTypes[0]->description)->toContain('N$135.00'); // 15 * 3 persons * 3 nights
});

it('returns unavailable when no rooms available', function () {
    $this->client->shouldReceive('get')->andReturn([
        'tourism_levy_rate' => 15.0,
        'room_types' => [
            ['code' => 'R1', 'name' => 'Room', 'available' => 0, 'rate_per_night' => 1000, 'currency' => 'NAD', 'total_rate' => 2000],
        ],
    ]);

    $response = $this->connector->checkAvailability(
        new AvailabilityRequest('HOPE-001', Carbon::tomorrow(), Carbon::tomorrow()->addDays(2))
    );

    expect($response->available)->toBeFalse();
});

it('handles availability API errors gracefully', function () {
    $httpResponse = Mockery::mock(Response::class);
    $httpResponse->shouldReceive('status')->andReturn(503);
    $httpResponse->shouldReceive('body')->andReturn('Service unavailable');
    $httpResponse->shouldReceive('toPsrResponse')->andReturn(makePsrResponseStub());
    $this->client->shouldReceive('get')->andThrow(new RequestException($httpResponse));

    $response = $this->connector->checkAvailability(
        new AvailabilityRequest('HOPE-001', Carbon::tomorrow(), Carbon::tomorrow()->addDays(2))
    );

    expect($response->available)->toBeFalse()
        ->and($response->error)->toContain('503');
});

it('creates a reservation and returns tourism levy and NTB reference', function () {
    $this->client->shouldReceive('post')
        ->once()
        ->with('reservations', Mockery::on(fn ($p) => $p['report_tourism_levy'] === true && $p['source'] === 'namibway'))
        ->andReturn([
            'status' => 'confirmed',
            'reservation_id' => 'HC-55555',
            'confirmation_number' => 'CNF-HC-001',
            'total_amount' => 6090.0,
            'currency' => 'NAD',
            'ntb_report_reference' => 'NTB-2026-88888',
            'tourism_levy' => [
                'rate_per_person_per_night' => 15.0,
                'currency' => 'NAD',
                'total' => 90.0,
                'nights' => 3,
                'persons' => 2,
                'included_in_rate' => false,
            ],
        ]);

    $request = new ReservationRequest(
        propertyCode: 'HOPE-001',
        roomTypeCode: 'CHALET',
        checkIn: Carbon::parse('2026-11-01'),
        checkOut: Carbon::parse('2026-11-04'),
        adults: 2,
        children: 0,
        guestName: 'Anna Schmidt',
        guestEmail: 'anna@example.com',
        guestPhone: '+49176000000',
        inquiryId: 99,
    );

    $response = $this->connector->createReservation($request);

    expect($response)->toBeInstanceOf(HopeCloudReservationResponse::class)
        ->and($response->success)->toBeTrue()
        ->and($response->status)->toBe(ReservationStatus::Confirmed)
        ->and($response->connectorReference)->toBe('HC-55555')
        ->and($response->ntbReportReference)->toBe('NTB-2026-88888')
        ->and($response->tourismLevy)->not->toBeNull()
        ->and($response->tourismLevy->ratePerPersonPerNight)->toBe(15.0)
        ->and($response->tourismLevy->totalLevy)->toBe(90.0)
        ->and($response->tourismLevy->includedInRate)->toBeFalse();
});

it('returns null tourism levy when API does not return levy data', function () {
    $this->client->shouldReceive('post')->andReturn([
        'status' => 'on_request',
        'reservation_id' => 'HC-66666',
    ]);

    $request = new ReservationRequest(
        'HOPE-001', 'CHALET',
        Carbon::tomorrow(), Carbon::tomorrow()->addDays(2),
        2, 0, 'Test', 'test@e.com', '+1234',
    );

    $response = $this->connector->createReservation($request);

    expect($response)->toBeInstanceOf(HopeCloudReservationResponse::class)
        ->and($response->status)->toBe(ReservationStatus::OnRequest)
        ->and($response->tourismLevy)->toBeNull();
});

it('returns failed response on reservation API error', function () {
    $httpResponse = Mockery::mock(Response::class);
    $httpResponse->shouldReceive('status')->andReturn(422);
    $httpResponse->shouldReceive('body')->andReturn('Validation error');
    $httpResponse->shouldReceive('toPsrResponse')->andReturn(makePsrResponseStub());
    $this->client->shouldReceive('post')->andThrow(new RequestException($httpResponse));

    $request = new ReservationRequest(
        'HOPE-001', 'CHALET',
        Carbon::tomorrow(), Carbon::tomorrow()->addDays(2),
        2, 0, 'Test', 'test@e.com', '+1234',
    );

    $response = $this->connector->createReservation($request);

    expect($response->success)->toBeFalse()
        ->and($response->status)->toBe(ReservationStatus::Failed)
        ->and($response->error)->toContain('422');
});

it('can cancel a reservation', function () {
    $this->client->shouldReceive('post')->with('reservations/HC-55555/cancel')->andReturn([]);

    expect($this->connector->cancelReservation('HC-55555'))->toBeTrue();
});

it('returns false when cancellation fails', function () {
    $httpResponse = Mockery::mock(Response::class);
    $httpResponse->shouldReceive('status')->andReturn(404);
    $httpResponse->shouldReceive('body')->andReturn('Not found');
    $httpResponse->shouldReceive('toPsrResponse')->andReturn(makePsrResponseStub());
    $this->client->shouldReceive('post')->andThrow(new RequestException($httpResponse));

    expect($this->connector->cancelReservation('HC-GHOST'))->toBeFalse();
});
