<?php

namespace Tests\Feature\Pricing;

use App\Enums\GuestKind;
use App\Enums\PricingStrategy;
use App\Exceptions\Pricing\UnpriceableStayException;
use App\Models\GuestCategory;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\Pricing\NightPricingContext;
use App\Services\Pricing\Occupancy;
use App\Services\Pricing\Pricers;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The three ways a night becomes an amount, checked against a table of
 * examples.
 *
 * A table rather than prose because "we support everything" is worthless
 * unless every case is written down somewhere it can fail. Nothing here
 * touches the database — a pricer is a pure function of its context, which is
 * the property that makes this a real check and not a fixture.
 *
 * The numbers are the ones a Namibian rate sheet actually carries: 1000 / 1300
 * / 1500 by guest count, and per-person-sharing with children at half.
 */
class NightPricerTest extends TestCase
{
    private const ADULT = 1;

    private const CHILD = 2;

    private const INFANT = 3;

    /**
     * @return array<int, GuestCategory>
     */
    private function categories(): array
    {
        return [
            self::ADULT => $this->category(self::ADULT, 'Adult', GuestKind::Adult, 100, true),
            self::CHILD => $this->category(self::CHILD, 'Child', GuestKind::Child, 50, true),
            self::INFANT => $this->category(self::INFANT, 'Infant', GuestKind::Infant, 0, false),
        ];
    }

    private function category(int $id, string $name, GuestKind $kind, float $percent, bool $counts): GuestCategory
    {
        $category = new GuestCategory([
            'code' => strtoupper(substr($name, 0, 2)),
            'name' => $name,
            'kind' => $kind,
            'charge_percent' => $percent,
            'counts_toward_occupancy' => $counts,
        ]);
        $category->id = $id;

        return $category;
    }

    private function room(): RoomType
    {
        $room = new RoomType(['name' => 'Standard Chalet', 'max_adults' => 4, 'max_children' => 2]);
        $room->id = 1;

        return $room;
    }

    /**
     * @param  array<int, int>  $guests
     * @param  array<int, float>  $amounts
     */
    private function context(
        PricingStrategy $strategy,
        float $baseRate,
        array $guests = [],
        array $amounts = [],
        ?float $supplementAmount = null,
        ?float $supplementPercent = null,
    ): NightPricingContext {
        $plan = new RatePlan([
            'name' => 'Standard rate',
            'code' => 'STD',
            'pricing_strategy' => $strategy,
            'single_supplement_amount' => $supplementAmount,
            'single_supplement_percent' => $supplementPercent,
        ]);

        return new NightPricingContext(
            roomType: $this->room(),
            date: Carbon::parse('2026-09-10'),
            ratePlan: $plan,
            baseRate: $baseRate,
            occupancy: Occupancy::of($guests),
            categories: $this->categories(),
            guestAmounts: $amounts,
        );
    }

    public function test_per_unit_charges_the_same_whoever_is_in_the_room(): void
    {
        $pricer = Pricers::for(PricingStrategy::PerUnit);

        $this->assertSame(1800.0, $pricer->price($this->context(PricingStrategy::PerUnit, 1800)));
        $this->assertSame(1800.0, $pricer->price($this->context(
            PricingStrategy::PerUnit,
            1800,
            [self::ADULT => 2, self::CHILD => 2],
        )));
    }

    /**
     * The tariff Till described: 1000 for one, 1300 for two, 1500 for three.
     */
    public function test_per_occupancy_reads_the_amount_for_that_many_guests(): void
    {
        $amounts = [1 => 1000.0, 2 => 1300.0, 3 => 1500.0];
        $pricer = Pricers::for(PricingStrategy::PerOccupancy);

        $cases = [
            [[self::ADULT => 1], 1000.0],
            [[self::ADULT => 2], 1300.0],
            [[self::ADULT => 2, self::CHILD => 1], 1500.0],
            // An infant does not count as an occupant, so this stays the
            // two-guest price.
            [[self::ADULT => 2, self::INFANT => 1], 1300.0],
        ];

        foreach ($cases as [$guests, $expected]) {
            $this->assertSame(
                $expected,
                $pricer->price($this->context(PricingStrategy::PerOccupancy, 1200, $guests, $amounts)),
                'guests: '.json_encode($guests),
            );
        }
    }

    public function test_per_occupancy_falls_back_to_the_nightly_rate_when_nothing_is_entered(): void
    {
        $pricer = Pricers::for(PricingStrategy::PerOccupancy);

        $this->assertSame(1200.0, $pricer->price(
            $this->context(PricingStrategy::PerOccupancy, 1200, [self::ADULT => 2]),
        ));
    }

    public function test_per_occupancy_refuses_a_guest_count_nobody_priced(): void
    {
        $pricer = Pricers::for(PricingStrategy::PerOccupancy);

        $this->expectException(UnpriceableStayException::class);
        $this->expectExceptionMessage('no price for 4 guests');

        $pricer->price($this->context(
            PricingStrategy::PerOccupancy,
            1200,
            [self::ADULT => 4],
            [1 => 1000.0, 2 => 1300.0],
        ));
    }

    public function test_per_person_sharing_multiplies_and_takes_the_childs_share(): void
    {
        $pricer = Pricers::for(PricingStrategy::PerPersonSharing);

        $cases = [
            // Two adults sharing, at 1500 each.
            [[self::ADULT => 2], 3000.0],
            // Plus a child at half.
            [[self::ADULT => 2, self::CHILD => 1], 3750.0],
            // An infant is free.
            [[self::ADULT => 2, self::INFANT => 1], 3000.0],
        ];

        foreach ($cases as [$guests, $expected]) {
            $this->assertSame(
                $expected,
                $pricer->price($this->context(PricingStrategy::PerPersonSharing, 1500, $guests)),
                'guests: '.json_encode($guests),
            );
        }
    }

    public function test_the_single_supplement_applies_only_to_somebody_actually_alone(): void
    {
        $pricer = Pricers::for(PricingStrategy::PerPersonSharing);

        $alone = $this->context(PricingStrategy::PerPersonSharing, 1500, [self::ADULT => 1], supplementAmount: 600);
        $this->assertSame(2100.0, $pricer->price($alone));

        // One adult with a child is not travelling alone, whatever the child
        // pays. Charging the supplement here is the sort of thing a guest
        // notices at check-out.
        $withChild = $this->context(
            PricingStrategy::PerPersonSharing,
            1500,
            [self::ADULT => 1, self::CHILD => 1],
            supplementAmount: 600,
        );
        $this->assertSame(2250.0, $pricer->price($withChild));

        // An infant in a cot does not make a single traveller a couple.
        $withInfant = $this->context(
            PricingStrategy::PerPersonSharing,
            1500,
            [self::ADULT => 1, self::INFANT => 1],
            supplementAmount: 600,
        );
        $this->assertSame(2100.0, $pricer->price($withInfant));
    }

    public function test_a_percentage_supplement_follows_the_season(): void
    {
        $pricer = Pricers::for(PricingStrategy::PerPersonSharing);

        $low = $this->context(PricingStrategy::PerPersonSharing, 1000, [self::ADULT => 1], supplementPercent: 50);
        $high = $this->context(PricingStrategy::PerPersonSharing, 2000, [self::ADULT => 1], supplementPercent: 50);

        $this->assertSame(1500.0, $pricer->price($low));
        $this->assertSame(3000.0, $pricer->price($high));
    }

    public function test_an_amount_beats_a_percentage_when_both_are_set(): void
    {
        $pricer = Pricers::for(PricingStrategy::PerPersonSharing);

        $context = $this->context(
            PricingStrategy::PerPersonSharing,
            1000,
            [self::ADULT => 1],
            supplementAmount: 400,
            supplementPercent: 50,
        );

        $this->assertSame(1400.0, $pricer->price($context));
    }

    public function test_a_plan_that_prices_by_guests_refuses_a_booking_that_names_none(): void
    {
        foreach ([PricingStrategy::PerOccupancy, PricingStrategy::PerPersonSharing] as $strategy) {
            try {
                Pricers::for($strategy)->price($this->context($strategy, 1500));
                $this->fail($strategy->value.' should refuse an empty occupancy.');
            } catch (UnpriceableStayException $refusal) {
                $this->assertStringContainsString('who is in the', $refusal->getMessage());
            }
        }
    }
}
