<?php

use App\Connectors\Native\NativeConnector;
use App\Connectors\ResConnect\DTOs\AvailabilityRequest;
use App\Connectors\ResConnect\DTOs\ReservationRequest;
use App\Enums\ConnectorType;
use App\Enums\InquiryStatus;
use App\Enums\ReservationStatus;
use App\Models\BookableUnit;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\Partner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->connector = new NativeConnector;
    $this->partner = Partner::create(['name' => 'Native Lodge', 'connector_type' => ConnectorType::Native->value]);
    $this->listing = Listing::factory()->create(['partner_id' => $this->partner->id]);
    $this->bookableUnit = BookableUnit::create([
        'listing_id' => $this->listing->id,
        'code' => 'standard',
        'name' => 'Standard Room',
        'total_units' => 1,
        'base_rate' => 1000,
        'currency' => 'NAD',
    ]);
});

it('reports the correct connector type', function () {
    expect($this->connector->getConnectorType())->toBe('native');
});

it('reports availability when no overlapping bookings exist', function () {
    $response = $this->connector->checkAvailability(new AvailabilityRequest(
        propertyCode: $this->listing->slug,
        checkIn: Carbon::parse('2026-12-01'),
        checkOut: Carbon::parse('2026-12-04'),
    ));

    expect($response->available)->toBeTrue()
        ->and($response->roomTypes)->toHaveCount(1)
        ->and($response->roomTypes[0]->available)->toBe(1)
        ->and($response->roomTypes[0]->totalRate)->toBe(3000.0);
});

it('excludes a room type that is fully booked for the requested dates', function () {
    Inquiry::create([
        'listing_id' => $this->listing->id,
        'name' => 'Existing Guest',
        'email' => 'existing@example.com',
        'check_in' => '2026-12-02',
        'check_out' => '2026-12-05',
        'status' => InquiryStatus::Confirmed,
        'bookable_unit_code' => 'standard',
    ]);

    $response = $this->connector->checkAvailability(new AvailabilityRequest(
        propertyCode: $this->listing->slug,
        checkIn: Carbon::parse('2026-12-01'),
        checkOut: Carbon::parse('2026-12-04'),
    ));

    expect($response->available)->toBeFalse()
        ->and($response->roomTypes)->toHaveCount(0);
});

it('creates a reservation and sets a 24h hold expiry on the inquiry', function () {
    $inquiry = Inquiry::create([
        'listing_id' => $this->listing->id,
        'name' => 'Guest',
        'email' => 'guest@example.com',
        'check_in' => '2026-12-01',
        'check_out' => '2026-12-04',
        'status' => InquiryStatus::Processing,
    ]);

    $response = $this->connector->createReservation(new ReservationRequest(
        propertyCode: $this->listing->slug,
        roomTypeCode: 'standard',
        checkIn: Carbon::parse('2026-12-01'),
        checkOut: Carbon::parse('2026-12-04'),
        adults: 2,
        children: 0,
        guestName: 'Guest',
        guestEmail: 'guest@example.com',
        guestPhone: '',
        inquiryId: $inquiry->id,
    ));

    expect($response->success)->toBeTrue()
        ->and($response->status)->toBe(ReservationStatus::OnRequest)
        ->and($response->totalAmount)->toBe(3000.0);

    $inquiry->refresh();
    expect($inquiry->hold_expires_at)->not->toBeNull()
        ->and($inquiry->hold_expires_at->diffInHours(now(), true))->toBeLessThan(25)
        ->and($inquiry->hold_expires_at->diffInHours(now(), true))->toBeGreaterThan(23);
});

it('fails to reserve a room type that is already sold out', function () {
    Inquiry::create([
        'listing_id' => $this->listing->id,
        'name' => 'Existing Guest',
        'email' => 'existing@example.com',
        'check_in' => '2026-12-01',
        'check_out' => '2026-12-04',
        'status' => InquiryStatus::Confirmed,
        'bookable_unit_code' => 'standard',
    ]);

    $inquiry = Inquiry::create([
        'listing_id' => $this->listing->id,
        'name' => 'New Guest',
        'email' => 'new@example.com',
        'check_in' => '2026-12-02',
        'check_out' => '2026-12-03',
        'status' => InquiryStatus::Processing,
    ]);

    $response = $this->connector->createReservation(new ReservationRequest(
        propertyCode: $this->listing->slug,
        roomTypeCode: 'standard',
        checkIn: Carbon::parse('2026-12-02'),
        checkOut: Carbon::parse('2026-12-03'),
        adults: 2,
        children: 0,
        guestName: 'New Guest',
        guestEmail: 'new@example.com',
        guestPhone: '',
        inquiryId: $inquiry->id,
    ));

    expect($response->success)->toBeFalse()
        ->and($response->error)->toContain('sold out');
});

it('cancelReservation is a no-op that always succeeds', function () {
    expect($this->connector->cancelReservation('NATIVE-1'))->toBeTrue();
});
