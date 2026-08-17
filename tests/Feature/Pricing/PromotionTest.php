<?php

namespace Tests\Feature\Pricing;

use App\Enums\DiscountType;
use App\Enums\PricingStrategy;
use App\Enums\ReservationSource;
use App\Exceptions\Pricing\PromotionUnavailableException;
use App\Models\BookableUnit;
use App\Models\Listing;
use App\Models\Promotion;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Offers, which apply to a finished price and never reach back into how it was
 * reached.
 *
 * The arithmetic is checked against a table; the wiring is checked through a
 * real booking, because the two things that actually go wrong with discount
 * systems are a discount nobody can explain afterwards and a code that keeps
 * working after it has run out.
 */
class PromotionTest extends TestCase
{
    use RefreshDatabase;

    private InventoryWriter $writer;

    private Listing $listing;

    private BookableUnit $room;

    private RatePlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-11 09:00:00'));

        $this->writer = app(InventoryWriter::class);
        $this->listing = Listing::factory()->create();
        $this->room = BookableUnit::factory()->create([
            'listing_id' => $this->listing->id,
            'total_units' => 5,
            'base_rate' => 1000,
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
    private function promotion(array $attributes = []): Promotion
    {
        return Promotion::create([
            'listing_id' => $this->listing->id,
            'name' => 'Green season',
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 20,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function book(string $in = '2026-09-10', string $out = '2026-09-13', ?string $code = null): Reservation
    {
        return $this->writer->book(new BookingRequest(
            listing: $this->listing,
            lines: [new BookingLine($this->room, 1, Carbon::parse($in), Carbon::parse($out), $this->plan)],
            guestName: 'Guest',
            source: ReservationSource::WalkIn,
            promotionCode: $code,
            notify: false,
        ));
    }

    public function test_the_three_ways_an_offer_takes_money_off(): void
    {
        $nights = [1000.0, 1000.0, 1200.0];

        $percentage = $this->promotion(['discount_type' => DiscountType::Percentage, 'discount_value' => 20]);
        $this->assertSame(640.0, $percentage->discountOn(3200, $nights));

        $fixed = $this->promotion(['discount_type' => DiscountType::FixedAmount, 'discount_value' => 500, 'code' => 'FIX']);
        $this->assertSame(500.0, $fixed->discountOn(3200, $nights));

        // Stay 3, pay 2 — and the free night is a cheapest one, which is how it
        // is advertised and the way round that favours the guest.
        $free = $this->promotion(['discount_type' => DiscountType::FreeNights, 'discount_value' => 1, 'code' => 'FREE']);
        $this->assertSame(1000.0, $free->discountOn(3200, $nights));
    }

    public function test_an_offer_never_takes_more_than_the_stay_costs(): void
    {
        $huge = $this->promotion(['discount_type' => DiscountType::FixedAmount, 'discount_value' => 99999]);

        $this->assertSame(3000.0, $huge->discountOn(3000, [1000.0, 1000.0, 1000.0]));

        // And free nights never make the whole stay free by arithmetic
        // accident on a stay shorter than the offer expected.
        $everything = $this->promotion(['discount_type' => DiscountType::FreeNights, 'discount_value' => 9, 'code' => 'NINE']);

        $this->assertSame(2000.0, $everything->discountOn(3000, [1000.0, 1000.0, 1000.0]));
    }

    public function test_a_public_offer_applies_by_itself_and_is_recorded_on_the_stay(): void
    {
        $offer = $this->promotion();

        $reservation = $this->book();

        $this->assertSame(3000.0, $reservation->quoted_amount);
        $this->assertSame(600.0, $reservation->discount_amount);
        $this->assertSame(2400.0, $reservation->total_amount);
        $this->assertSame($offer->id, $reservation->promotion_id);

        // The nights keep the calendar's price. The discount is a fact about
        // the stay, not an invented per-night rate.
        $this->assertSame(
            [1000.0, 1000.0, 1000.0],
            $reservation->units->first()->nights->sortBy('date')->pluck('rate')->all(),
        );
    }

    public function test_the_best_public_offer_wins_and_they_never_stack(): void
    {
        $this->promotion(['name' => 'Small', 'discount_value' => 10]);
        $big = $this->promotion(['name' => 'Big', 'discount_value' => 25]);

        $reservation = $this->book();

        $this->assertSame($big->id, $reservation->promotion_id);
        $this->assertSame(750.0, $reservation->discount_amount);
    }

    public function test_a_coded_offer_does_nothing_until_somebody_types_it(): void
    {
        $this->promotion(['code' => 'AGENT26', 'discount_value' => 30]);

        $withoutCode = $this->book();
        $this->assertNull($withoutCode->promotion_id);
        $this->assertSame(3000.0, $withoutCode->total_amount);

        $withCode = $this->book(code: 'agent26');
        $this->assertSame(900.0, $withCode->discount_amount);
        $this->assertSame('AGENT26', $withCode->promotion_code);
    }

    public function test_a_typed_code_that_does_not_work_refuses_the_booking(): void
    {
        $this->promotion([
            'code' => 'WINTER',
            'stay_from' => '2026-06-01',
            'stay_until' => '2026-07-31',
        ]);

        // Quietly charging full price is how a guest finds out at check-out.
        $this->expectException(PromotionUnavailableException::class);
        $this->expectExceptionMessage('does not cover these dates');

        $this->book(code: 'WINTER');
    }

    public function test_an_unknown_code_says_so(): void
    {
        $this->expectException(PromotionUnavailableException::class);
        $this->expectExceptionMessage('no offer with the code');

        $this->book(code: 'NOPE');
    }

    public function test_the_cap_is_enforced_and_counted(): void
    {
        $offer = $this->promotion(['code' => 'TWICE', 'max_uses' => 2]);

        $this->book(code: 'TWICE');
        $this->book('2026-09-20', '2026-09-23', 'TWICE');

        $this->assertSame(2, $offer->refresh()->uses);

        $this->expectException(PromotionUnavailableException::class);
        $this->expectExceptionMessage('used as many times as it allows');

        $this->book('2026-10-01', '2026-10-04', 'TWICE');
    }

    public function test_a_stay_straddling_the_end_of_an_offer_does_not_get_half_of_it(): void
    {
        $this->promotion(['stay_until' => '2026-09-11']);

        // Arrives inside the window, leaves after it. Discounting part of it
        // would need the offer to reach into the per-night pricing, which is
        // the one thing it may not do.
        $reservation = $this->book('2026-09-10', '2026-09-13');

        $this->assertNull($reservation->promotion_id);
    }

    public function test_an_offer_scoped_to_another_bookable_unit_stays_out_of_it(): void
    {
        $other = BookableUnit::factory()->create(['listing_id' => $this->listing->id, 'currency' => 'NAD']);

        $offer = $this->promotion();
        $offer->bookableUnits()->attach($other);

        $this->assertNull($this->book()->promotion_id);

        $offer->bookableUnits()->sync([$this->room->id]);

        $this->assertSame($offer->id, $this->book('2026-10-10', '2026-10-13')->promotion_id);
    }

    public function test_the_minimum_stay_is_counted_in_nights(): void
    {
        $this->promotion(['min_nights' => 4]);

        $this->assertNull($this->book('2026-09-10', '2026-09-13')->promotion_id);
        $this->assertNotNull($this->book('2026-10-10', '2026-10-14')->promotion_id);
    }

    public function test_the_booking_window_is_not_the_stay_window(): void
    {
        // An early bird: book by August, stay any time.
        $this->promotion(['booked_until' => '2026-08-31']);

        $this->assertNotNull($this->book('2026-12-20', '2026-12-23')->promotion_id);

        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00'));

        $this->assertNull($this->book('2026-12-24', '2026-12-27')->promotion_id);
    }

    public function test_a_property_sells_in_one_currency(): void
    {
        // Caught when the room type is saved, not when somebody finally tries
        // to book: by then the rates are entered and the calendar has been
        // showing a symbol that belongs to another room.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already sells in NAD');

        BookableUnit::factory()->create([
            'listing_id' => $this->listing->id,
            'currency' => 'USD',
        ]);
    }

    public function test_the_screens_take_the_currency_from_the_room_it_prices(): void
    {
        // Not from the country: the rate is stored against the room type, and
        // a symbol from anywhere else would sit in front of the wrong number.
        $this->assertSame('NAD', $this->listing->sellingCurrency());
    }

    public function test_an_offer_another_property_runs_is_not_this_propertys_offer(): void
    {
        $theirs = Listing::factory()->create();
        Promotion::create([
            'listing_id' => $theirs->id,
            'name' => 'Not yours',
            'code' => 'THEIRS',
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 50,
            'is_active' => true,
        ]);

        $this->expectException(PromotionUnavailableException::class);

        $this->book(code: 'THEIRS');
    }
}
