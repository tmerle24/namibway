<?php

namespace Tests\Feature\Filament;

use App\Enums\BlockReason;
use App\Enums\ListingType;
use App\Enums\ReservationSource;
use App\Filament\Partner\Pages\OccupancyCalendar;
use App\Filament\Partner\Support\SelectedProperty;
use App\Models\BookableUnit;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Inventory\DTOs\BlockRequest;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The two lodge-facing screens as a partner actually meets them: through the
 * panel, scoped to their own partner, and read-only.
 *
 * The isolation tests are the ones that matter most. A partner seeing another
 * partner's guest list is not a bug to be fixed later; it is the reason a lodge
 * would never let us near their inventory again.
 */
class LodgeScreensTest extends TestCase
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

    public function test_the_calendar_shows_this_partners_stays_and_blocks(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $room = $this->bookableUnit($listing, ['name' => 'Standard Double', 'total_units' => 4]);

        $this->book($listing, $room, '2026-09-11', '2026-09-14', guest: 'Anna Shipanga');

        app(InventoryWriter::class)->block(new BlockRequest(
            bookableUnit: $room,
            units: 1,
            firstNight: Carbon::parse('2026-09-16'),
            lastNight: Carbon::parse('2026-09-18'),
            reason: BlockReason::Maintenance,
        ));

        $this->actingAs($user)
            ->get('/partner/calendar')
            ->assertOk()
            ->assertSee('Anna Shipanga')
            ->assertSee('Standard Double')
            ->assertSee('Maintenance');
    }

    public function test_a_partner_cannot_see_another_partners_stays_on_either_screen(): void
    {
        [$mine, $myListing] = $this->partnerWithProperty('Mine');
        $this->book($myListing, $this->bookableUnit($myListing), '2026-09-10', '2026-09-12', guest: 'My Guest');

        [, $theirListing] = $this->partnerWithProperty('Theirs');
        $this->book($theirListing, $this->bookableUnit($theirListing), '2026-09-10', '2026-09-12', guest: 'Their Guest');

        $this->actingAs($mine)
            ->get('/partner/calendar')
            ->assertOk()
            ->assertSee('My Guest')
            ->assertDontSee('Their Guest');

        $this->actingAs($mine)
            ->get('/partner/arrivals')
            ->assertOk()
            ->assertSee('My Guest')
            ->assertDontSee('Their Guest');
    }

    public function test_the_detail_drawer_refuses_another_partners_reservation(): void
    {
        [$mine, $myListing] = $this->partnerWithProperty('Mine');
        $this->bookableUnit($myListing);

        [, $theirListing] = $this->partnerWithProperty('Theirs');
        $theirs = $this->book($theirListing, $this->bookableUnit($theirListing), '2026-09-10', '2026-09-12', guest: 'Their Guest');

        $this->actingAs($mine);
        Filament::setCurrentPanel(Filament::getPanel('partner'));

        Livewire::test(OccupancyCalendar::class)
            ->call('showReservation', $theirs->id)
            ->assertSet('selectedReservationId', null)
            ->assertDontSee('Their Guest');
    }

    public function test_the_property_switcher_offers_only_this_partners_accommodation(): void
    {
        [$user, $first] = $this->partnerWithProperty('Camp One');
        $second = $this->propertyFor($first->partner_id, 'Camp Two');

        // Not a property: a room type cannot exist on it, so a calendar cannot either.
        Listing::factory()->create([
            'partner_id' => $first->partner_id,
            'type' => ListingType::Activity,
            'name' => 'Sunset Drive',
        ]);

        [, $foreign] = $this->partnerWithProperty('Somebody Else');

        $this->actingAs($user);

        $options = app(SelectedProperty::class)->options()->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['Camp One', 'Camp Two'], $options);
        $this->assertNotContains('Sunset Drive', $options);
        $this->assertNotContains($foreign->name, $options);
        $this->assertSame($second->partner_id, $first->partner_id);
    }

    public function test_the_switcher_scopes_both_screens_and_ignores_a_foreign_id(): void
    {
        [$user, $first] = $this->partnerWithProperty('Camp One');
        $second = $this->propertyFor($first->partner_id, 'Camp Two');

        $this->book($first, $this->bookableUnit($first), '2026-09-10', '2026-09-12', guest: 'Guest At One');
        $this->book($second, $this->bookableUnit($second), '2026-09-10', '2026-09-12', guest: 'Guest At Two');

        [, $foreign] = $this->partnerWithProperty('Somebody Else');
        $this->book($foreign, $this->bookableUnit($foreign), '2026-09-10', '2026-09-12', guest: 'Foreign Guest');

        // Defaults to the first property alphabetically.
        $this->actingAs($user)->get('/partner/calendar')->assertSee('Guest At One')->assertDontSee('Guest At Two');

        $this->actingAs($user)
            ->post(route('filament.partner.property.select'), ['property' => $second->id])
            ->assertRedirect();

        $this->actingAs($user)->get('/partner/calendar')->assertSee('Guest At Two')->assertDontSee('Guest At One');
        $this->actingAs($user)->get('/partner/arrivals')->assertSee('Guest At Two')->assertDontSee('Guest At One');

        // Another partner's id must not become the selection, and must not
        // clear it into some third state either — it simply is not an option.
        $this->actingAs($user)
            ->post(route('filament.partner.property.select'), ['property' => $foreign->id])
            ->assertRedirect();

        $this->actingAs($user)
            ->get('/partner/calendar')
            ->assertDontSee('Foreign Guest')
            ->assertSee('Guest At One');
    }

    public function test_the_arrivals_board_separates_the_three_movements_on_one_date(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Camp');
        $room = $this->bookableUnit($listing, ['total_units' => 6]);

        $this->book($listing, $room, '2026-09-10', '2026-09-13', guest: 'Arriving Guest');
        $this->book($listing, $room, '2026-09-07', '2026-09-10', guest: 'Departing Guest');
        $this->book($listing, $room, '2026-09-08', '2026-09-12', guest: 'Resident Guest');

        $response = $this->actingAs($user)->get('/partner/arrivals')->assertOk();

        $response->assertSee('Arriving Guest');
        $response->assertSee('Departing Guest');
        $response->assertSee('Resident Guest');
        $response->assertSee('Arriving (1)');
        $response->assertSee('Departing (1)');
        $response->assertSee('Staying on (1)');
    }

    public function test_the_arrivals_board_follows_the_date_in_the_url(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Camp');
        $room = $this->bookableUnit($listing, ['total_units' => 4]);

        $this->book($listing, $room, '2026-09-20', '2026-09-22', guest: 'Later Guest');

        $this->actingAs($user)->get('/partner/arrivals')->assertDontSee('Later Guest');
        $this->actingAs($user)->get('/partner/arrivals?date=2026-09-20')->assertSee('Later Guest');
    }

    public function test_an_unparseable_date_shows_today_rather_than_an_error(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Camp');
        $this->bookableUnit($listing);

        $this->actingAs($user)->get('/partner/arrivals?date=not-a-date')->assertOk();
        $this->actingAs($user)->get('/partner/calendar?from=whenever')->assertOk();
    }

    public function test_neither_screen_writes_anything(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Camp');
        $room = $this->bookableUnit($listing);
        $this->book($listing, $room, '2026-09-10', '2026-09-12', guest: 'Anna Shipanga');

        // A mock with no expectations throws on any call, so resolving the
        // write service at all fails the test.
        $this->mock(InventoryWriter::class);

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->actingAs($user)->get('/partner/calendar')->assertOk();
        $this->actingAs($user)->get('/partner/arrivals')->assertOk();

        // Session writes go through the session driver, not the query log for
        // these tables, so anything here is the screens touching the book.
        $inventoryWrites = array_values(array_filter(
            $writes,
            fn (string $sql) => str_contains($sql, 'bookable_unit_calendar_days')
                || str_contains($sql, 'reservation')
                || str_contains($sql, 'inventory_blocks')
        ));

        $this->assertSame([], $inventoryWrites, 'The calendar shows the book; it must not keep it.');
    }

    public function test_a_partner_without_a_property_gets_an_explanation_not_an_error(): void
    {
        $partner = Partner::create(['name' => 'New Partner', 'email' => 'new@example.com']);
        $user = User::factory()->create(['partner_id' => $partner->id]);

        $this->actingAs($user)->get('/partner/calendar')->assertOk()->assertSee('No property yet');
        $this->actingAs($user)->get('/partner/arrivals')->assertOk()->assertSee('No property yet');
    }

    public function test_a_property_without_bookable_units_says_so(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Empty Camp');

        $this->actingAs($user)
            ->get('/partner/calendar')
            ->assertOk()
            ->assertSee('has no room types yet');

        $this->assertSame(0, $listing->bookableUnits()->count());
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
        ], $attributes));
    }

    private function book(
        Listing $listing,
        BookableUnit $bookableUnit,
        string $checkIn,
        string $checkOut,
        string $guest = 'Test Guest',
    ): Reservation {
        return app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($bookableUnit, 1, Carbon::parse($checkIn), Carbon::parse($checkOut))],
            guestName: $guest,
            source: ReservationSource::PartnerEntered,
        ));
    }
}
