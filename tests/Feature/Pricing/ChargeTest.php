<?php

namespace Tests\Feature\Pricing;

use App\Enums\ChargeBase;
use App\Enums\ChargeBasis;
use App\Enums\ChargeKind;
use App\Enums\DiscountType;
use App\Enums\PricingStrategy;
use App\Enums\ReservationSource;
use App\Models\Charge;
use App\Models\Listing;
use App\Models\Promotion;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Taxes, levies and fees — the last link in the chain, and the one that must
 * never reach backwards.
 *
 * The arithmetic is checked against a table, because the two things that go
 * wrong with tax are both silent: a rate that already contains VAT being taxed
 * again, and a total nobody can take apart afterwards.
 */
class ChargeTest extends TestCase
{
    use RefreshDatabase;

    private InventoryWriter $writer;

    private Listing $listing;

    private RoomType $room;

    private RatePlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-11 09:00:00'));

        $this->writer = app(InventoryWriter::class);
        $this->listing = Listing::factory()->create();
        $this->room = RoomType::factory()->create([
            'listing_id' => $this->listing->id,
            'total_units' => 5,
            'rate_per_night' => 1000,
            'currency' => 'NAD',
        ]);
        $this->plan = RatePlan::create([
            'listing_id' => $this->listing->id,
            'name' => 'Standard rate',
            'code' => 'STD',
            'pricing_strategy' => PricingStrategy::PerUnit,
            'is_default' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param array<string, mixed> $attributes */
    private function charge(array $attributes = []): Charge
    {
        return Charge::create([
            'listing_id' => $this->listing->id,
            'name' => 'VAT',
            'kind' => ChargeKind::Tax,
            'basis' => ChargeBasis::Percentage,
            'rate' => 15,
            ...$attributes,
        ]);
    }

    private function book(string $in = '2026-09-10', string $out = '2026-09-13', int $adults = 2, int $children = 0): Reservation
    {
        return $this->writer->book(new BookingRequest(
            listing: $this->listing,
            lines: [new BookingLine($this->room, 1, Carbon::parse($in), Carbon::parse($out), $this->plan)],
            guestName: 'Guest',
            source: ReservationSource::WalkIn,
            adults: $adults,
            children: $children,
            notify: false,
        ));
    }

    public function test_a_property_with_no_taxes_is_unchanged(): void
    {
        $stay = $this->book();

        $this->assertSame(3000.0, $stay->total_amount);
        $this->assertNull($stay->charges_amount);
        $this->assertCount(0, $stay->charges);
    }

    public function test_vat_added_on_top_raises_the_total(): void
    {
        $this->charge();

        $stay = $this->book();

        $this->assertSame(3000.0, $stay->quoted_amount);
        $this->assertSame(450.0, $stay->charges_amount);
        $this->assertSame(3450.0, $stay->total_amount);
        $this->assertSame(3000.0, $stay->stayAmount());
    }

    public function test_vat_already_in_the_rate_is_named_and_changes_nothing(): void
    {
        // The common case here: a published rate is VAT inclusive. Extracting
        // is not the same arithmetic as adding — 15% of 3,000 is 450, but the
        // VAT *inside* 3,000 is 391.30.
        $this->charge(['is_included' => true]);

        $stay = $this->book();

        $this->assertSame(3000.0, $stay->total_amount);
        $this->assertNull($stay->charges_amount);
        $this->assertSame(391.30, $stay->charges->first()?->amount);
        $this->assertTrue($stay->charges->first()?->is_included);
    }

    public function test_a_per_person_fee_counts_people_and_nights(): void
    {
        $this->charge([
            'name' => 'Park permit',
            'kind' => ChargeKind::Fee,
            'basis' => ChargeBasis::PerPersonPerNight,
            'rate' => 80,
        ]);

        // Two adults, one child, three nights.
        $stay = $this->book(adults: 2, children: 1);

        $this->assertSame(720.0, $stay->charges_amount);
        $this->assertSame(3720.0, $stay->total_amount);
    }

    public function test_a_per_person_fee_can_leave_children_out(): void
    {
        $this->charge([
            'name' => 'Park permit',
            'kind' => ChargeKind::Fee,
            'basis' => ChargeBasis::PerPersonPerNight,
            'rate' => 80,
            'charges_children' => false,
        ]);

        $stay = $this->book(adults: 2, children: 1);

        $this->assertSame(480.0, $stay->charges_amount);
    }

    public function test_a_per_room_levy_counts_rooms_and_nights(): void
    {
        $this->charge([
            'name' => 'Bed levy',
            'kind' => ChargeKind::Levy,
            'basis' => ChargeBasis::PerUnitPerNight,
            'rate' => 20,
        ]);

        $stay = $this->writer->book(new BookingRequest(
            listing: $this->listing,
            lines: [new BookingLine($this->room, 2, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-13'), $this->plan)],
            guestName: 'Guest',
            source: ReservationSource::WalkIn,
            notify: false,
        ));

        // Two rooms, three nights.
        $this->assertSame(120.0, $stay->charges_amount);
    }

    public function test_a_booking_fee_is_charged_once(): void
    {
        $this->charge([
            'name' => 'Booking fee',
            'kind' => ChargeKind::Fee,
            'basis' => ChargeBasis::PerBooking,
            'rate' => 150,
        ]);

        $this->assertSame(150.0, $this->book()->charges_amount);
        $this->assertSame(150.0, $this->book('2026-10-01', '2026-10-08')->charges_amount);
    }

    public function test_vat_can_be_charged_on_a_levy_that_is_passed_on(): void
    {
        $this->charge([
            'name' => 'Tourism levy',
            'kind' => ChargeKind::Levy,
            'rate' => 2,
            'sort' => 1,
        ]);
        $this->charge([
            'name' => 'VAT',
            'rate' => 15,
            'base' => ChargeBase::StayPlusPreceding,
            'sort' => 2,
        ]);

        $stay = $this->book();

        // 3,000 + 2% = 3,060, and 15% of that is 459.
        $this->assertSame([60.0, 459.0], $stay->charges->pluck('amount')->all());
        $this->assertSame(519.0, $stay->charges_amount);
        $this->assertSame(3519.0, $stay->total_amount);
    }

    public function test_two_percentages_do_not_compound_by_accident(): void
    {
        $this->charge(['name' => 'Tourism levy', 'kind' => ChargeKind::Levy, 'rate' => 2, 'sort' => 1]);
        $this->charge(['name' => 'VAT', 'rate' => 15, 'sort' => 2]);

        // Both work from the stay unless a lodge says otherwise: 60 + 450.
        $this->assertSame(510.0, $this->book()->charges_amount);
    }

    public function test_tax_follows_the_discount_rather_than_the_list_price(): void
    {
        $this->charge();
        Promotion::create([
            'listing_id' => $this->listing->id,
            'name' => 'Green season',
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 20,
            'is_active' => true,
        ]);

        $stay = $this->book();

        // 3,000 less 20% is 2,400, and VAT is charged on what is actually
        // charged — not on a price nobody paid.
        $this->assertSame(600.0, $stay->discount_amount);
        $this->assertSame(360.0, $stay->charges_amount);
        $this->assertSame(2760.0, $stay->total_amount);
    }

    public function test_tax_follows_an_overridden_price_too(): void
    {
        $this->charge();

        $stay = $this->writer->book(new BookingRequest(
            listing: $this->listing,
            lines: [new BookingLine($this->room, 1, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-13'), $this->plan)],
            guestName: 'Guest',
            source: ReservationSource::WalkIn,
            totalOverride: 2000,
            overrideReason: 'Regular guest',
            notify: false,
        ));

        $this->assertSame(300.0, $stay->charges_amount);
        $this->assertSame(2300.0, $stay->total_amount);
        $this->assertTrue($stay->priceWasOverridden());
    }

    public function test_a_charge_is_frozen_onto_the_stay(): void
    {
        $charge = $this->charge();

        $stay = $this->book();
        $line = $stay->charges->first();

        $this->assertSame('VAT', $line?->name);
        $this->assertSame(15.0, $line?->rate);
        $this->assertSame(3000.0, $line?->base_amount);

        // Raising VAT, or deleting the charge outright, must leave last
        // month's invoices exactly as they were.
        $charge->update(['rate' => 16]);
        $charge->delete();

        $line = $stay->fresh()?->charges->first();

        $this->assertSame(15.0, $line?->rate);
        $this->assertSame(450.0, $line?->amount);
        $this->assertNull($line?->charge_id);
    }

    public function test_a_charge_that_has_not_started_yet_is_not_charged(): void
    {
        $this->charge(['valid_from' => '2026-10-01']);

        $this->assertNull($this->book('2026-09-10', '2026-09-13')->charges_amount);
        $this->assertSame(450.0, $this->book('2026-10-05', '2026-10-08')->charges_amount);
    }

    public function test_a_charge_switched_off_is_not_charged(): void
    {
        $this->charge(['is_active' => false]);

        $this->assertNull($this->book()->charges_amount);
    }

    public function test_another_propertys_taxes_are_not_this_propertys(): void
    {
        $theirs = Listing::factory()->create();
        Charge::create([
            'listing_id' => $theirs->id,
            'name' => 'Their VAT',
            'kind' => ChargeKind::Tax,
            'basis' => ChargeBasis::Percentage,
            'rate' => 15,
        ]);

        $this->assertNull($this->book()->charges_amount);
    }
}
