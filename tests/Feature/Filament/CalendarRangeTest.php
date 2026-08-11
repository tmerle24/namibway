<?php

namespace Tests\Feature\Filament;

use App\Enums\CalendarRange;
use App\Enums\ListingType;
use App\Enums\ReservationSource;
use App\Filament\Partner\Pages\OccupancyCalendar;
use App\Models\BookingSlot;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * How much of the book is on screen, and what the day view adds to it.
 *
 * The ranges snap — a month is a month, a week starts on Monday — because the
 * jump control makes that the only honest reading. See CalendarRange.
 */
class CalendarRangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A Thursday, mid-month, so snapping is visible in both directions.
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_month_view_is_a_month_and_the_week_view_starts_on_monday(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $this->roomType($listing);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));

        $page = Livewire::test(OccupancyCalendar::class);

        $this->assertSame('2026-09-01', $page->instance()->grid()?->from->toDateString());
        $this->assertSame(30, $page->instance()->grid()?->columnCount());

        $page->call('showRange', 'week');

        $this->assertSame('2026-09-07', $page->instance()->grid()?->from->toDateString());
        $this->assertSame(7, $page->instance()->grid()?->columnCount());

        $page->call('showRange', 'day');

        $this->assertSame('2026-09-10', $page->instance()->grid()?->from->toDateString());
        $this->assertSame(1, $page->instance()->grid()?->columnCount());
    }

    public function test_the_arrows_move_one_whole_range(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $this->roomType($listing);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));

        $page = Livewire::test(OccupancyCalendar::class)->call('shift', 1);

        $this->assertSame('2026-10-01', $page->instance()->grid()?->from->toDateString());
        $this->assertSame(31, $page->instance()->grid()?->columnCount(), 'October has 31 days; the range is not a constant.');

        $page->call('shift', -1)->call('shift', -1);

        $this->assertSame('2026-08-01', $page->instance()->grid()?->from->toDateString());

        $page->call('today');

        $this->assertSame('2026-09-01', $page->instance()->grid()?->from->toDateString());
    }

    public function test_a_jump_lands_on_the_month_and_the_range_survives_it(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $this->roomType($listing);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));

        $page = Livewire::test(OccupancyCalendar::class)->call('jumpTo', 2, 2028);

        $this->assertSame('2028-02-01', $page->instance()->grid()?->from->toDateString());
        $this->assertSame(29, $page->instance()->grid()?->columnCount(), 'February 2028 is a leap year.');

        // Switching range keeps the month being looked at rather than going home.
        $page->call('showRange', 'week');

        $this->assertSame('2028-01-31', $page->instance()->grid()?->from->toDateString());
        $this->assertSame(CalendarRange::Week, $page->instance()->range());
    }

    public function test_nonsense_in_the_query_string_shows_a_calendar_rather_than_an_error(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $this->roomType($listing);

        $this->actingAs($user)->get('/partner/calendar?range=fortnight&from=whenever&res=7')->assertOk();

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));

        $page = Livewire::test(OccupancyCalendar::class)->call('jumpTo', 99, 1200);

        $this->assertSame(12, $page->instance()->grid()?->from->month);
        $this->assertSame(2006, $page->instance()->grid()?->from->year, 'Clamped, not refused.');
    }

    public function test_the_day_view_shows_the_hour_axis_only_where_there_is_a_timetable(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $chalet = $this->roomType($listing, ['name' => 'Standard Chalet']);

        $this->actingAs($user)
            ->get('/partner/calendar?range=day')
            ->assertOk()
            ->assertSee('Standard Chalet')
            ->assertDontSee('min per row');

        $tour = $this->roomType($listing, ['name' => 'Quad tour', 'total_units' => 8]);
        $morning = BookingSlot::create([
            'room_type_id' => $tour->id,
            'label' => 'Morning departure',
            'starts_at' => '09:00',
            'duration_minutes' => 180,
        ]);

        app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($tour, 3, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-11'), slot: $morning)],
            guestName: 'Anna Shipanga',
            source: ReservationSource::PartnerEntered,
            notify: false,
        ));

        $this->actingAs($user)
            ->get('/partner/calendar?range=day')
            ->assertOk()
            ->assertSee('Morning departure')
            ->assertSee('Anna Shipanga')
            ->assertSee('min per row')
            // Still one calendar: the chalet is right above it.
            ->assertSee('Standard Chalet');

        // And the month keeps both, the tour summed into its day.
        $this->actingAs($user)
            ->get('/partner/calendar')
            ->assertOk()
            ->assertSee('Standard Chalet')
            ->assertSee('Quad tour');

        $this->assertSame($listing->id, $chalet->listing_id);
    }

    /**
     * @return array{0: User, 1: Listing}
     */
    private function partnerWithProperty(string $name): array
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
    private function roomType(Listing $listing, array $attributes = []): RoomType
    {
        return RoomType::factory()->create(array_merge([
            'listing_id' => $listing->id,
            'total_units' => 3,
            'rate_per_night' => 1500.00,
            'currency' => 'NAD',
        ], $attributes));
    }
}
