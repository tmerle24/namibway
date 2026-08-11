<?php

namespace Tests\Feature\Booking;

use App\Enums\ListingType;
use App\Enums\ReservationSource;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Note;
use App\Models\Partner;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Booking\CustomerDirectory;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Who a booking is for.
 *
 * The two things that go wrong with customer records are both tested here: the
 * same person becoming two records, and one booking's typo rewriting what the
 * property knew about somebody.
 */
class CustomerTest extends TestCase
{
    use RefreshDatabase;

    private Partner $partner;

    private Listing $listing;

    private RoomType $room;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-11 09:00:00'));

        $this->partner = Partner::create(['name' => 'Okonjima', 'email' => 'stay@okonjima.test']);
        $this->listing = Listing::factory()->create([
            'partner_id' => $this->partner->id,
            'type' => ListingType::Accommodation,
        ]);
        $this->room = RoomType::factory()->create([
            'listing_id' => $this->listing->id,
            'total_units' => 5,
            'rate_per_night' => 1200,
            'currency' => 'NAD',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_every_booking_belongs_to_somebody(): void
    {
        $stay = $this->book('Anna Shipanga', 'anna@example.com');

        $this->assertNotNull($stay->customer_id);
        $this->assertSame('Anna Shipanga', $stay->customer?->name);
        $this->assertSame('anna@example.com', $stay->customer?->email);

        // And the typed strings stay on the stay, because they are what this
        // booking said rather than what the property knows now.
        $this->assertSame('Anna Shipanga', $stay->guest_name);
    }

    public function test_the_same_email_is_the_same_person_however_it_was_typed(): void
    {
        $first = $this->book('Anna Shipanga', 'anna@example.com');
        $second = $this->book('A. Shipanga', 'ANNA@Example.com ', '2026-10-01', '2026-10-03');

        $this->assertSame($first->customer_id, $second->customer_id);
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_a_booking_never_overwrites_what_the_property_already_knows(): void
    {
        $this->book('Anna Shipanga', 'anna@example.com', '2026-09-10', '2026-09-12', '081 234 5678');

        // A mistyped surname on a later booking must not rewrite a record built
        // over three seasons. Correcting a customer is its own act.
        $this->book('Anna Shipangaa', 'anna@example.com', '2026-10-01', '2026-10-03', '081 999 0000');

        $customer = Customer::query()->firstOrFail();

        $this->assertSame('Anna Shipanga', $customer->name);
        $this->assertSame('081 234 5678', $customer->phone);
    }

    public function test_an_account_is_matched_before_an_email_so_it_survives_an_email_change(): void
    {
        $traveller = User::factory()->create(['email' => 'anna@example.com']);

        $first = $this->book('Anna', 'anna@example.com', userId: $traveller->id);

        // Same person, new address. Matching on the email alone would make a
        // second customer here and split their history in half.
        $second = $this->book('Anna', 'anna@newjob.example.com', '2026-10-01', '2026-10-03', userId: $traveller->id);

        $this->assertSame($first->customer_id, $second->customer_id);
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_a_customer_created_at_the_desk_becomes_the_account_holder_when_they_sign_in(): void
    {
        $this->book('Anna', 'anna@example.com');

        $traveller = User::factory()->create(['email' => 'anna@example.com']);
        $this->book('Anna', 'anna@example.com', '2026-10-01', '2026-10-03', userId: $traveller->id);

        $customer = Customer::query()->firstOrFail();

        $this->assertSame($traveller->id, $customer->user_id);
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_walk_ins_without_an_email_do_not_collapse_into_one_person(): void
    {
        // The email is optional at a desk on purpose — a required field that
        // cannot always be filled produces x@x.com. Two people without one are
        // still two people.
        $first = $this->book('Walk-in one', null);
        $second = $this->book('Walk-in two', null, '2026-10-01', '2026-10-03');

        $this->assertNotNull($first->customer_id);
        $this->assertNotSame($first->customer_id, $second->customer_id);
    }

    public function test_a_phone_number_is_stored_the_way_it_is_searched_for(): void
    {
        $this->book('Anna', 'anna@example.com', phone: '081 234 5678');

        $customer = Customer::query()->firstOrFail();

        $this->assertSame('081 234 5678', $customer->phone);
        $this->assertSame('+264812345678', $customer->phone_normalised);
    }

    public function test_two_properties_of_the_same_partner_share_a_customer(): void
    {
        $second = Listing::factory()->create([
            'partner_id' => $this->partner->id,
            'type' => ListingType::Accommodation,
        ]);
        $secondRoom = RoomType::factory()->create([
            'listing_id' => $second->id,
            'total_units' => 2,
            'rate_per_night' => 900,
            'currency' => 'NAD',
        ]);

        $here = $this->book('Anna', 'anna@example.com');
        $there = app(InventoryWriter::class)->book(new BookingRequest(
            listing: $second,
            lines: [new BookingLine($secondRoom, 1, Carbon::parse('2026-09-20'), Carbon::parse('2026-09-22'))],
            guestName: 'Anna',
            guestEmail: 'anna@example.com',
            source: ReservationSource::WalkIn,
            notify: false,
        ));

        // NWR is one partner with about twenty camps. A guest of one of them is
        // the partner's guest, not the camp's.
        $this->assertSame($here->customer_id, $there->customer_id);
    }

    public function test_another_partner_keeps_their_own_record_of_the_same_person(): void
    {
        $otherPartner = Partner::create(['name' => 'Elsewhere', 'email' => 'other@example.com']);
        $otherListing = Listing::factory()->create([
            'partner_id' => $otherPartner->id,
            'type' => ListingType::Accommodation,
        ]);
        $otherRoom = RoomType::factory()->create([
            'listing_id' => $otherListing->id,
            'total_units' => 2,
            'rate_per_night' => 900,
            'currency' => 'NAD',
        ]);

        $mine = $this->book('Anna', 'anna@example.com');
        $theirs = app(InventoryWriter::class)->book(new BookingRequest(
            listing: $otherListing,
            lines: [new BookingLine($otherRoom, 1, Carbon::parse('2026-09-20'), Carbon::parse('2026-09-22'))],
            guestName: 'Anna',
            guestEmail: 'anna@example.com',
            source: ReservationSource::WalkIn,
            notify: false,
        ));

        // One operator's notes about a guest are not the other's to read.
        $this->assertNotSame($mine->customer_id, $theirs->customer_id);
        $this->assertSame(2, Customer::query()->count());
    }

    public function test_a_note_keeps_its_author_even_after_the_account_goes(): void
    {
        $stay = $this->book('Anna', 'anna@example.com');
        $staff = User::factory()->create(['name' => 'Tuli at reception']);

        Note::write($stay, 'Arriving late, keep dinner.', $staff);
        $staff->delete();

        $note = $stay->noteLog()->firstOrFail();

        $this->assertSame('Tuli at reception', $note->author_name);
        $this->assertNull($note->fresh()?->user_id);
    }

    public function test_a_property_with_no_partner_still_takes_a_booking(): void
    {
        // A scraped listing nobody has claimed has no business to own a
        // customer record. That is a missing link, not a failed booking.
        $unclaimed = Listing::factory()->create(['partner_id' => null, 'type' => ListingType::Accommodation]);
        $room = RoomType::factory()->create([
            'listing_id' => $unclaimed->id,
            'total_units' => 1,
            'rate_per_night' => 500,
            'currency' => 'NAD',
        ]);

        $stay = app(InventoryWriter::class)->book(new BookingRequest(
            listing: $unclaimed,
            lines: [new BookingLine($room, 1, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-11'))],
            guestName: 'Nobody',
            source: ReservationSource::WalkIn,
            notify: false,
        ));

        $this->assertNull($stay->customer_id);
    }

    public function test_the_directory_survives_two_bookings_for_one_person_at_once(): void
    {
        $directory = app(CustomerDirectory::class);

        $first = $directory->resolve($this->partner, 'Anna', 'anna@example.com');
        $second = $directory->resolve($this->partner, 'Anna', 'anna@example.com');

        $this->assertSame($first->id, $second->id);
    }

    private function book(
        string $name,
        ?string $email,
        string $in = '2026-09-10',
        string $out = '2026-09-12',
        ?string $phone = null,
        ?int $userId = null,
    ): Reservation {
        return app(InventoryWriter::class)->book(new BookingRequest(
            listing: $this->listing,
            lines: [new BookingLine($this->room, 1, Carbon::parse($in), Carbon::parse($out))],
            guestName: $name,
            guestEmail: $email,
            guestPhone: $phone,
            source: ReservationSource::WalkIn,
            notify: false,
            guestUserId: $userId,
        ));
    }
}
