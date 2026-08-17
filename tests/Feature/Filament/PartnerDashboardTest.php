<?php

namespace Tests\Feature\Filament;

use App\Enums\ListingType;
use App\Enums\ReservationSource;
use App\Filament\Partner\Widgets\ArrivalsTodayWidget;
use App\Filament\Partner\Widgets\CalendarWidget;
use App\Models\BookableUnit;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Reservation;
use App\Models\User;
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
 * The first screen after signing in: who arrives today, and the fortnight
 * ahead.
 *
 * Both widgets render the same services and the same grid partial the full
 * pages do, so what is worth testing here is what is different about them —
 * they are scoped to the partner, and they cannot write.
 */
class PartnerDashboardTest extends TestCase
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

    public function test_the_dashboard_shows_todays_arrivals_and_the_fortnight_ahead(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $room = $this->bookableUnit($listing, ['name' => 'Standard Double']);

        $this->book($listing, $room, '2026-09-10', '2026-09-13', 'Anna Shipanga');

        $this->actingAs($user)
            ->get('/partner')
            ->assertOk()
            ->assertSee('Arriving today')
            ->assertSee('Anna Shipanga')
            ->assertSee('The next two weeks')
            ->assertSee('Standard Double');
    }

    public function test_the_arrivals_widget_steps_a_day_at_a_time(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Camp');
        $room = $this->bookableUnit($listing);

        $this->book($listing, $room, '2026-09-11', '2026-09-13', 'Tomorrow Guest');

        $this->asPartner($user);

        Livewire::test(ArrivalsTodayWidget::class)
            ->assertDontSee('Tomorrow Guest')
            ->call('shift', 1)
            ->assertSee('Tomorrow Guest')
            ->call('today')
            ->assertDontSee('Tomorrow Guest');
    }

    public function test_neither_widget_shows_another_partners_business(): void
    {
        [$mine, $myListing] = $this->partnerWithProperty('Mine');
        $this->bookableUnit($myListing, ['name' => 'My Room']);

        [, $theirListing] = $this->partnerWithProperty('Theirs');
        $theirRoom = $this->bookableUnit($theirListing, ['name' => 'Their Room']);
        $this->book($theirListing, $theirRoom, '2026-09-10', '2026-09-12', 'Their Guest');

        $this->actingAs($mine)
            ->get('/partner')
            ->assertOk()
            ->assertSee('My Room')
            ->assertDontSee('Their Guest')
            ->assertDontSee('Their Room');
    }

    /**
     * A dashboard is where somebody looks before deciding what to do. A
     * booking form opening from the landing page is a way to take a booking
     * by accident, so the widget renders the grid without any of it.
     */
    public function test_the_calendar_widget_offers_nothing_that_writes(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Camp');
        $room = $this->bookableUnit($listing);
        $this->book($listing, $room, '2026-09-11', '2026-09-13', 'Somebody');

        $this->asPartner($user);

        $html = Livewire::test(CalendarWidget::class)->html();

        $this->assertStringNotContainsString('startBooking', $html);
        $this->assertStringNotContainsString('showReservation', $html);
        $this->assertStringNotContainsString('New booking', $html);
    }

    public function test_rendering_the_dashboard_writes_nothing(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Camp');
        $room = $this->bookableUnit($listing);
        $this->book($listing, $room, '2026-09-10', '2026-09-12', 'Anna Shipanga');

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) === 1
                && (str_contains($query->sql, 'bookable_unit_calendar_days')
                    || str_contains($query->sql, 'reservation')
                    || str_contains($query->sql, 'inventory_blocks'))) {
                $writes[] = $query->sql;
            }
        });

        $this->actingAs($user)->get('/partner')->assertOk();

        $this->assertSame([], $writes, 'The dashboard shows the book; it must not keep it.');
    }

    public function test_a_partner_without_a_property_gets_an_explanation_not_an_error(): void
    {
        $partner = Partner::create(['name' => 'New Partner', 'email' => 'new@example.com']);
        $user = User::factory()->create(['partner_id' => $partner->id]);

        $this->actingAs($user)->get('/partner')->assertOk();
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

    private function book(Listing $listing, BookableUnit $bookableUnit, string $checkIn, string $checkOut, string $guest): Reservation
    {
        return app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($bookableUnit, 1, Carbon::parse($checkIn), Carbon::parse($checkOut))],
            guestName: $guest,
            source: ReservationSource::PartnerEntered,
        ));
    }
}
