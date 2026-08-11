<?php

namespace Tests\Feature\Inventory;

use App\Enums\BoardBasis;
use App\Enums\PricingStrategy;
use App\Enums\RatePlanEligibility;
use App\Enums\ReservationSource;
use App\Models\BookableUnit;
use App\Models\Listing;
use App\Models\RatePlan;
use App\Services\Inventory\ArrivalsBoard;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use App\Services\Pricing\RatePlanProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Board basis: what a rate includes at the table.
 *
 * There is no calculation to check — board is a property of a rate plan, which
 * is the whole point of modelling it that way. What is worth checking is that
 * a stay keeps saying what it was sold as after the product has moved on,
 * because that is the difference between a record and a guess.
 */
class BoardBasisTest extends TestCase
{
    use RefreshDatabase;

    private InventoryWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(InventoryWriter::class);
    }

    private function room(Listing $listing): BookableUnit
    {
        return BookableUnit::factory()->create([
            'listing_id' => $listing->id,
            'name' => 'Standard Chalet',
            'total_units' => 3,
            'rate_per_night' => 1500,
            'currency' => 'NAD',
        ]);
    }

    public function test_a_stay_records_what_it_was_sold_as(): void
    {
        $listing = Listing::factory()->create();
        $room = $this->room($listing);

        $plan = RatePlan::create([
            'listing_id' => $listing->id,
            'name' => 'Dinner, bed and breakfast',
            'code' => 'DBB',
            'board_basis' => BoardBasis::HalfBoard,
            'pricing_strategy' => PricingStrategy::PerUnit,
            'is_default' => true,
        ]);

        $reservation = $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-11'), $plan)],
            guestName: 'Dinner Guest',
            source: ReservationSource::WalkIn,
        ));

        $unit = $reservation->units->first();

        $this->assertSame(BoardBasis::HalfBoard, $unit->board_basis);
        $this->assertSame('Dinner, bed and breakfast', $unit->rate_plan_name);
        $this->assertSame('Dinner, bed and breakfast (DBB)', $unit->soldAs());
    }

    public function test_renaming_or_removing_the_plan_does_not_rewrite_the_past(): void
    {
        $listing = Listing::factory()->create();
        $room = $this->room($listing);

        $plan = RatePlan::create([
            'listing_id' => $listing->id,
            'name' => 'Standard rate',
            'code' => 'STD',
            'board_basis' => BoardBasis::HalfBoard,
            'pricing_strategy' => PricingStrategy::PerUnit,
            'is_default' => true,
        ]);

        $reservation = $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-11'), $plan)],
            guestName: 'Booked In February',
            source: ReservationSource::WalkIn,
        ));

        // The lodge changes its mind about the product, twice over.
        $plan->update(['name' => 'Room only rate', 'board_basis' => BoardBasis::RoomOnly]);
        $plan->delete();

        $unit = $reservation->refresh()->units->first();

        // The link is gone, the record is not: this guest booked dinner.
        $this->assertNull($unit->rate_plan_id);
        $this->assertSame(BoardBasis::HalfBoard, $unit->board_basis);
        $this->assertSame('Standard rate (DBB)', $unit->soldAs());
    }

    public function test_a_plan_that_states_no_board_claims_none(): void
    {
        $listing = Listing::factory()->create();
        $room = $this->room($listing);
        $plan = RatePlan::ensureDefaultFor($listing);

        $reservation = $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-11'), $plan)],
            guestName: 'No Claim',
            source: ReservationSource::WalkIn,
        ));

        $unit = $reservation->units->first();

        $this->assertNull($unit->board_basis);
        $this->assertSame('Standard rate', $unit->soldAs());
    }

    public function test_the_arrivals_board_counts_rooms_by_board(): void
    {
        $listing = Listing::factory()->create();
        $room = $this->room($listing);

        $dbb = RatePlan::create([
            'listing_id' => $listing->id, 'name' => 'DBB', 'code' => 'DBB',
            'board_basis' => BoardBasis::HalfBoard, 'pricing_strategy' => PricingStrategy::PerUnit, 'is_default' => true,
        ]);
        $bb = RatePlan::create([
            'listing_id' => $listing->id, 'name' => 'B&B', 'code' => 'BB',
            'board_basis' => BoardBasis::BedAndBreakfast, 'pricing_strategy' => PricingStrategy::PerUnit,
        ]);

        $night = Carbon::parse('2026-09-10');

        $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 2, $night, $night->copy()->addDay(), $dbb)],
            guestName: 'Two On Dinner',
            source: ReservationSource::WalkIn,
        ));

        $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, $night, $night->copy()->addDay(), $bb)],
            guestName: 'One On Breakfast',
            source: ReservationSource::WalkIn,
        ));

        $board = app(ArrivalsBoard::class)->forDate($listing, $night);

        $this->assertSame(['B&B' => 1, 'DBB' => 2], $board->boardTonight());
    }

    public function test_a_profile_creates_the_rates_a_lodge_recognises(): void
    {
        $listing = Listing::factory()->create();

        $created = RatePlanProfiles::apply($listing, 'per_person');

        $this->assertSame(2, $created);

        $plans = RatePlan::forListing($listing);

        $this->assertSame(['DBB', 'BB'], $plans->pluck('code')->all());
        $this->assertSame(BoardBasis::HalfBoard, $plans->firstWhere('code', 'DBB')?->board_basis);
        $this->assertSame(PricingStrategy::PerPersonSharing, $plans->firstWhere('code', 'BB')?->pricing_strategy);
        $this->assertTrue($plans->firstWhere('code', 'DBB')?->is_default);

        // Pressing it twice adds nothing and resets nothing.
        $plans->firstWhere('code', 'DBB')?->update(['name' => 'Our own name for it']);

        $this->assertSame(0, RatePlanProfiles::apply($listing, 'per_person'));
        $this->assertSame('Our own name for it', RatePlan::forListing($listing)->firstWhere('code', 'DBB')?->name);
    }

    public function test_a_second_profile_never_takes_the_default_away(): void
    {
        $listing = Listing::factory()->create();

        RatePlanProfiles::apply($listing, 'per_room');
        $existing = RatePlan::defaultFor($listing);

        RatePlanProfiles::apply($listing, 'resident');

        // The property already sold something; adding products does not change
        // what its booking form offers first.
        $this->assertSame($existing?->id, RatePlan::defaultFor($listing)?->id);
        $this->assertSame(4, RatePlan::forListing($listing)->count());
    }

    public function test_the_resident_profile_is_three_eligibilities_of_one_product(): void
    {
        $listing = Listing::factory()->create();

        RatePlanProfiles::apply($listing, 'resident');

        $eligibilities = RatePlan::forListing($listing)->pluck('eligibility')->all();

        $this->assertSame(
            [RatePlanEligibility::Public, RatePlanEligibility::Resident, RatePlanEligibility::Sadc],
            $eligibilities,
        );
    }

    public function test_an_unknown_profile_creates_nothing(): void
    {
        $listing = Listing::factory()->create();

        $this->assertSame(0, RatePlanProfiles::apply($listing, 'not-a-profile'));
        $this->assertSame(0, RatePlan::forListing($listing)->count());
    }
}
