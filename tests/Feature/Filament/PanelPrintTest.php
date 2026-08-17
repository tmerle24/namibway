<?php

namespace Tests\Feature\Filament;

use App\Enums\ListingType;
use App\Models\BookableUnit;
use App\Models\BookingSlot;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Printing, for the whole panel — the open bug BOOKING_SYSTEM.md recorded:
 * printing the arrivals board produced the navigation and none of the board.
 *
 * The fix is a stylesheet, and a stylesheet is not something a test can judge.
 * What a test *can* hold is that it is on the page at all, on every screen and
 * not only the one somebody was printing that day, and that the two rules the
 * bug actually turned on are still in it: Filament's shell is hidden, and the
 * page header stops being sticky. A sticky header on paper printed on top of
 * the first line of the board, which is where the date was — a passenger list
 * carried to a vehicle with no date on it is worse than no sheet, and nothing
 * about it is visible on screen.
 */
class PanelPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-10 06:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_every_partner_screen_carries_the_print_rules(): void
    {
        [$user] = $this->partnerWithProperty();

        foreach (['/partner/calendar', '/partner/arrivals', '/partner'] as $path) {
            $response = $this->actingAs($user)->get($path);

            $response->assertOk();
            $response->assertSee('@media print', escape: false);
            // The shell, which is what printed instead of the board.
            $response->assertSee('.fi-sidebar', escape: false);
            // And the sticky page header, which printed on top of it.
            $response->assertSee('position: static !important', escape: false);
        }
    }

    /**
     * The date is the one thing a printed sheet cannot be without, and on the
     * calendar it lives beside the arrows, which do not print.
     */
    public function test_the_calendar_prints_the_range_it_is_showing(): void
    {
        [$user, $listing] = $this->partnerWithProperty();

        BookableUnit::factory()->create([
            'listing_id' => $listing->id,
            'name' => 'Bush Chalet',
            'total_units' => 4,
            'base_rate' => 2450,
            'currency' => 'NAD',
        ]);

        $this->actingAs($user)
            ->get('/partner/calendar?range=week')
            ->assertOk()
            ->assertSee('nw-printonly', escape: false)
            ->assertSee('7 Sep – 13 Sep 2026', escape: false);
    }

    public function test_the_arrivals_board_prints_its_manifests(): void
    {
        [$user, $listing] = $this->partnerWithProperty();

        $tour = BookableUnit::factory()->create([
            'listing_id' => $listing->id,
            'name' => 'Quad tour',
            'total_units' => 8,
            'base_rate' => 950,
            'currency' => 'NAD',
        ]);

        BookingSlot::create([
            'bookable_unit_id' => $tour->id,
            'label' => 'Morning ride',
            'starts_at' => '09:00',
            'duration_minutes' => 180,
        ]);

        // No booking yet, so no manifest — an empty departure is a page of
        // nothing. The heading a lodge would see is gone too, because this
        // property sells no nights.
        $this->actingAs($user)
            ->get('/partner/arrivals')
            ->assertOk()
            ->assertDontSee('Morning ride')
            ->assertDontSee('Nobody is due to arrive on this date.');
    }

    /**
     * @return array{0: User, 1: Listing}
     */
    private function partnerWithProperty(): array
    {
        $partner = Partner::create(['name' => 'Okonjima Adventures', 'email' => 'desk@example.com']);
        $user = User::factory()->create(['partner_id' => $partner->id]);

        $listing = Listing::factory()->create([
            'partner_id' => $partner->id,
            'type' => ListingType::Accommodation,
            'name' => 'Okonjima Adventures',
        ]);

        return [$user, $listing];
    }
}
