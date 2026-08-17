<?php

namespace Tests\Feature\Filament;

use App\Enums\ListingType;
use App\Enums\ReservationSource;
use App\Filament\Partner\Pages\RatesAndAvailability;
use App\Models\BookableUnit;
use App\Models\BookableUnitCalendarDay;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\RatePlanDay;
use App\Models\User;
use App\Services\Inventory\AvailabilityCalendar;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The bulk rate editor, which exists because cell-by-cell entry is how you
 * make a lodge manager close the laptop.
 *
 * Two behaviours carry the most weight and are tested hardest: the write
 * lands on exactly the nights that were asked for and no others, and an empty
 * field changes nothing — somebody setting a weekend surcharge must not
 * silently clear a minimum stay they set last week.
 */
class LodgeRatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // A Monday, so "weekends" in these tests is unambiguous.
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-07 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_rate_is_written_to_exactly_the_range_asked_for(): void
    {
        [$user, $listing] = $this->partnerWithProperty();
        $room = $this->bookableUnit($listing);

        $this->asPartner($user);

        Livewire::test(RatesAndAvailability::class)
            ->fillForm([
                'bookable_unit_ids' => [$room->id],
                'from' => '2026-09-10',
                'to' => '2026-09-14',
                'rate' => 2200,
            ])
            ->call('apply');

        $calendar = app(AvailabilityCalendar::class);

        $this->assertSame(1500.0, $calendar->rateFor($room, Carbon::parse('2026-09-09')));
        $this->assertSame(2200.0, $calendar->rateFor($room, Carbon::parse('2026-09-10')));
        $this->assertSame(2200.0, $calendar->rateFor($room, Carbon::parse('2026-09-14')));
        $this->assertSame(1500.0, $calendar->rateFor($room, Carbon::parse('2026-09-15')));

        // Nothing outside the range gained a row at all — and a rate-only edit
        // writes no inventory row, because it changes nothing physical.
        $this->assertSame(5, RatePlanDay::where('bookable_unit_id', $room->id)->count());
        $this->assertSame(0, BookableUnitCalendarDay::where('bookable_unit_id', $room->id)->count());
    }

    public function test_a_weekend_surcharge_touches_only_weekends(): void
    {
        [$user, $listing] = $this->partnerWithProperty();
        $room = $this->bookableUnit($listing);

        $this->asPartner($user);

        Livewire::test(RatesAndAvailability::class)
            ->fillForm([
                'bookable_unit_ids' => [$room->id],
                'from' => '2026-09-07',
                'to' => '2026-09-20',
                'weekdays' => [6, 7],
                'rate' => 1900,
            ])
            ->call('apply');

        $calendar = app(AvailabilityCalendar::class);

        $this->assertSame(1900.0, $calendar->rateFor($room, Carbon::parse('2026-09-12'))); // Saturday
        $this->assertSame(1900.0, $calendar->rateFor($room, Carbon::parse('2026-09-13'))); // Sunday
        $this->assertSame(1500.0, $calendar->rateFor($room, Carbon::parse('2026-09-14'))); // Monday
        $this->assertSame(4, RatePlanDay::where('bookable_unit_id', $room->id)->count());
    }

    public function test_an_empty_field_leaves_what_is_already_there_alone(): void
    {
        [$user, $listing] = $this->partnerWithProperty();
        $room = $this->bookableUnit($listing);

        app(InventoryWriter::class)->setCalendar(
            $room,
            Carbon::parse('2026-09-10'),
            Carbon::parse('2026-09-12'),
            ['min_stay' => 3, 'closed_to_arrival' => true],
        );

        $this->asPartner($user);

        // Only a rate is given, so the restrictions must survive untouched.
        Livewire::test(RatesAndAvailability::class)
            ->fillForm([
                'bookable_unit_ids' => [$room->id],
                'from' => '2026-09-10',
                'to' => '2026-09-12',
                'rate' => 2500,
            ])
            ->call('apply');

        $day = RatePlanDay::where('bookable_unit_id', $room->id)
            ->whereDate('date', '2026-09-10')
            ->firstOrFail();

        $this->assertSame(2500.0, (float) $day->rate);
        $this->assertSame(3, $day->min_stay);
        $this->assertTrue($day->closed_to_arrival);
    }

    public function test_a_restriction_can_be_cleared_but_only_when_that_is_chosen(): void
    {
        [$user, $listing] = $this->partnerWithProperty();
        $room = $this->bookableUnit($listing);

        app(InventoryWriter::class)->setCalendar(
            $room,
            Carbon::parse('2026-09-10'),
            Carbon::parse('2026-09-12'),
            ['min_stay' => 3],
        );

        $this->asPartner($user);

        Livewire::test(RatesAndAvailability::class)
            ->fillForm([
                'bookable_unit_ids' => [$room->id],
                'from' => '2026-09-10',
                'to' => '2026-09-12',
                'min_stay_mode' => 'none',
            ])
            ->call('apply');

        $day = RatePlanDay::where('bookable_unit_id', $room->id)
            ->whereDate('date', '2026-09-10')
            ->firstOrFail();

        $this->assertNull($day->min_stay);
    }

    public function test_capacity_cannot_be_lowered_under_what_is_already_sold(): void
    {
        [$user, $listing] = $this->partnerWithProperty();
        $room = $this->bookableUnit($listing, ['total_units' => 4]);

        app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 3, Carbon::parse('2026-09-11'), Carbon::parse('2026-09-13'))],
            guestName: 'Already Here',
            source: ReservationSource::Website,
        ));

        $this->asPartner($user);

        Livewire::test(RatesAndAvailability::class)
            ->fillForm([
                'bookable_unit_ids' => [$room->id],
                'from' => '2026-09-10',
                'to' => '2026-09-14',
                'units_mode' => 'set',
                'units_total' => 1,
            ])
            ->call('apply');

        // Refused whole, not partly applied.
        $this->assertSame(
            4,
            app(AvailabilityCalendar::class)->capacityFor($room, Carbon::parse('2026-09-10')),
        );
    }

    public function test_another_partners_bookable_unit_cannot_be_repriced(): void
    {
        [$mine, $myListing] = $this->partnerWithProperty('Mine');
        $this->bookableUnit($myListing);

        [, $theirListing] = $this->partnerWithProperty('Theirs');
        $theirRoom = $this->bookableUnit($theirListing);

        $this->asPartner($mine);

        Livewire::test(RatesAndAvailability::class)
            ->fillForm([
                'bookable_unit_ids' => [$theirRoom->id],
                'from' => '2026-09-10',
                'to' => '2026-09-14',
                'rate' => 99,
            ])
            ->call('apply');

        $this->assertSame(
            1500.0,
            app(AvailabilityCalendar::class)->rateFor($theirRoom, Carbon::parse('2026-09-10')),
        );
        $this->assertSame(0, BookableUnitCalendarDay::where('bookable_unit_id', $theirRoom->id)->count());
        $this->assertSame(0, RatePlanDay::where('bookable_unit_id', $theirRoom->id)->count());
    }

    private function asPartner(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
    }

    /**
     * @return array{0: User, 1: Listing}
     */
    private function partnerWithProperty(string $name = 'Camp'): array
    {
        $partner = Partner::create(['name' => $name, 'email' => fake()->unique()->safeEmail()]);
        $user = User::factory()->create(['partner_id' => $partner->id]);

        $listing = Listing::factory()->create([
            'partner_id' => $partner->id,
            'type' => ListingType::Accommodation,
            'name' => $name,
        ]);

        return [$user, $listing];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function bookableUnit(Listing $listing, array $attributes = []): BookableUnit
    {
        return BookableUnit::factory()->create(array_merge([
            'listing_id' => $listing->id,
            'total_units' => 3,
            'base_rate' => 1500.00,
            'currency' => 'NAD',
            'is_active' => true,
        ], $attributes));
    }
}
