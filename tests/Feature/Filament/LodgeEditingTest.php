<?php

namespace Tests\Feature\Filament;

use App\Enums\BlockReason;
use App\Enums\ListingType;
use App\Enums\ReservationSource;
use App\Enums\StayStatus;
use App\Filament\Partner\Pages\ArrivalsDepartures;
use App\Filament\Partner\Pages\OccupancyCalendar;
use App\Models\BookableUnit;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Inventory\AvailabilityCalendar;
use App\Services\Inventory\DTOs\BlockRequest;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The half of the lodge screens that writes.
 *
 * The scoping tests carry the same weight here as the read-only ones did in
 * LodgeScreensTest, and more: reading another partner's guest list is bad,
 * and changing their booking is worse.
 */
class LodgeEditingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_clicking_a_free_cell_prefills_the_bookable_unit_and_the_night(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $room = $this->bookableUnit($listing, ['name' => 'Standard Double']);

        $this->asPartner($user);

        Livewire::test(OccupancyCalendar::class)
            ->call('startBooking', $room->id, '2026-09-14')
            ->assertSet('prefillBookableUnitId', $room->id)
            ->assertSet('prefillDate', '2026-09-14');
    }

    public function test_a_cell_click_carrying_another_partners_bookable_unit_prefills_nothing(): void
    {
        [$mine, $myListing] = $this->partnerWithProperty('Mine');
        $this->bookableUnit($myListing);

        [, $theirListing] = $this->partnerWithProperty('Theirs');
        $theirRoom = $this->bookableUnit($theirListing, ['name' => 'Not Yours']);

        $this->asPartner($mine);

        Livewire::test(OccupancyCalendar::class)
            ->call('startBooking', $theirRoom->id, '2026-09-14')
            ->assertSet('prefillBookableUnitId', null);
    }

    public function test_a_stay_moves_through_its_day_from_the_arrivals_board(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Camp');
        $room = $this->bookableUnit($listing);
        $reservation = $this->book($listing, $room, '2026-09-10', '2026-09-13');

        $this->asPartner($user);

        Livewire::test(ArrivalsDepartures::class)
            ->call('showReservation', $reservation->id)
            ->call('transitionStay', StayStatus::DueIn->value)
            ->call('transitionStay', StayStatus::InHouse->value);

        $this->assertSame(StayStatus::InHouse, $reservation->refresh()->status);
    }

    public function test_an_impossible_transition_is_refused_and_changes_nothing(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Camp');
        $room = $this->bookableUnit($listing);
        $reservation = $this->book($listing, $room, '2026-09-11', '2026-09-13');

        $this->asPartner($user);

        Livewire::test(OccupancyCalendar::class)
            ->call('showReservation', $reservation->id)
            // Nobody can check out of a room they never arrived at.
            ->call('transitionStay', StayStatus::CheckedOut->value);

        $this->assertSame(StayStatus::Confirmed, $reservation->refresh()->status);
    }

    public function test_the_offered_transitions_are_the_ones_the_writer_would_allow(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Camp');
        $room = $this->bookableUnit($listing);
        $reservation = $this->book($listing, $room, '2026-09-11', '2026-09-13');

        $this->asPartner($user);

        $page = Livewire::test(OccupancyCalendar::class)->call('showReservation', $reservation->id);

        $this->assertSame(
            InventoryWriter::allowedTransitions()[StayStatus::Confirmed->value],
            $page->instance()->availableTransitions(),
        );
    }

    public function test_another_partners_stay_cannot_be_transitioned(): void
    {
        [$mine, $myListing] = $this->partnerWithProperty('Mine');
        $this->bookableUnit($myListing);

        [, $theirListing] = $this->partnerWithProperty('Theirs');
        $theirs = $this->book($theirListing, $this->bookableUnit($theirListing), '2026-09-11', '2026-09-13');

        $this->asPartner($mine);

        Livewire::test(OccupancyCalendar::class)
            ->call('showReservation', $theirs->id)
            ->assertSet('selectedReservationId', null)
            ->call('transitionStay', StayStatus::DueIn->value);

        $this->assertSame(StayStatus::Confirmed, $theirs->refresh()->status);
    }

    public function test_cancelling_a_stay_gives_the_rooms_back(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Camp');
        $room = $this->bookableUnit($listing, ['total_units' => 1]);
        $reservation = $this->book($listing, $room, '2026-09-11', '2026-09-13');

        $calendar = app(AvailabilityCalendar::class);
        $this->assertSame(0, $calendar->unitsFree($room, Carbon::parse('2026-09-11')));

        $this->asPartner($user);

        Livewire::test(OccupancyCalendar::class)
            ->call('showReservation', $reservation->id)
            ->callAction('cancelStay', ['reason' => 'Guest called to cancel']);

        $reservation->refresh();

        $this->assertContains($reservation->status, [StayStatus::Cancelled, StayStatus::CancelledLate]);
        $this->assertSame('Guest called to cancel', $reservation->cancellation_reason);
        $this->assertSame(1, $calendar->unitsFree($room, Carbon::parse('2026-09-11')));
    }

    public function test_a_block_can_be_put_back_on_sale_from_the_drawer(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Camp');
        $room = $this->bookableUnit($listing, ['total_units' => 2]);

        $block = app(InventoryWriter::class)->block(new BlockRequest(
            bookableUnit: $room,
            units: 2,
            firstNight: Carbon::parse('2026-09-12'),
            lastNight: Carbon::parse('2026-09-14'),
            reason: BlockReason::Maintenance,
        ));

        $calendar = app(AvailabilityCalendar::class);
        $this->assertSame(0, $calendar->unitsFree($room, Carbon::parse('2026-09-12')));

        $this->asPartner($user);

        Livewire::test(OccupancyCalendar::class)
            ->call('showBlock', $block->id)
            ->callAction('releaseBlock');

        $this->assertNotNull($block->refresh()->released_at);
        $this->assertSame(2, $calendar->unitsFree($room, Carbon::parse('2026-09-12')));
    }

    public function test_another_partners_block_cannot_be_released(): void
    {
        [$mine, $myListing] = $this->partnerWithProperty('Mine');
        $this->bookableUnit($myListing);

        [, $theirListing] = $this->partnerWithProperty('Theirs');

        $block = app(InventoryWriter::class)->block(new BlockRequest(
            bookableUnit: $this->bookableUnit($theirListing),
            units: 1,
            firstNight: Carbon::parse('2026-09-12'),
            lastNight: Carbon::parse('2026-09-13'),
        ));

        $this->asPartner($mine);

        Livewire::test(OccupancyCalendar::class)
            ->call('showBlock', $block->id)
            ->assertSet('selectedBlockId', null);

        $this->assertNull($block->refresh()->released_at);
    }

    public function test_a_demo_tenant_is_announced_on_every_screen(): void
    {
        $partner = Partner::create(['name' => 'Demo Lodge', 'is_demo' => true]);
        $user = User::factory()->create(['partner_id' => $partner->id]);
        $this->bookableUnit($this->propertyFor($partner->id, 'Demo Camp'));

        $this->actingAs($user)->get('/partner/calendar')->assertOk()->assertSee('This is a sandbox');
        $this->actingAs($user)->get('/partner/arrivals')->assertOk()->assertSee('This is a sandbox');
    }

    public function test_a_real_partner_never_sees_the_sandbox_banner(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Real Camp');
        $this->bookableUnit($listing);

        $this->actingAs($user)->get('/partner/calendar')->assertOk()->assertDontSee('This is a sandbox');
    }

    private function asPartner(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
    }

    /**
     * @return array{0: User, 1: Listing}
     */
    private function partnerWithProperty(string $name): array
    {
        $partner = Partner::create(['name' => $name, 'email' => fake()->unique()->safeEmail()]);
        $user = User::factory()->create(['partner_id' => $partner->id]);

        return [$user, $this->propertyFor($partner->id, $name)];
    }

    private function propertyFor(?int $partnerId, string $name): Listing
    {
        return Listing::factory()->create([
            'partner_id' => $partnerId,
            'type' => ListingType::Accommodation,
            'name' => $name,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function bookableUnit(Listing $listing, array $attributes = []): BookableUnit
    {
        return BookableUnit::factory()->create(array_merge([
            'listing_id' => $listing->id,
            'total_units' => 3,
            'rate_per_night' => 1500.00,
            'currency' => 'NAD',
            'is_active' => true,
        ], $attributes));
    }

    private function book(Listing $listing, BookableUnit $bookableUnit, string $checkIn, string $checkOut): Reservation
    {
        return app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($bookableUnit, 1, Carbon::parse($checkIn), Carbon::parse($checkOut))],
            guestName: 'Test Guest',
            source: ReservationSource::PartnerEntered,
        ));
    }
}
