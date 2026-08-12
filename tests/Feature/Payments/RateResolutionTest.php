<?php

namespace Tests\Feature\Payments;

use App\Enums\ChargeBasis;
use App\Enums\ChargeKind;
use App\Enums\ReservationSource;
use App\Models\BookableUnit;
use App\Models\Charge;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\PaymentSettings;
use App\Models\Reservation;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use App\Services\Payments\RateResolver;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * PAYMENTS.md § 2a: commission is ours, the deposit is the partner's, and both
 * resolve most-specific-first with null meaning inherit.
 *
 * The tests that matter most are the two about *time*: a rate is resolved once,
 * at booking, and written down. A commission recomputed from today's setting is
 * not a record of anything.
 */
class RateResolutionTest extends TestCase
{
    use RefreshDatabase;

    private RateResolver $rates;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rates = app(RateResolver::class);
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_property_with_no_overrides_gets_the_platform_rates(): void
    {
        $settings = PaymentSettings::current();
        $listing = $this->listing();

        $resolved = $this->rates->for($listing);

        $this->assertSame($settings->commission_rate, $resolved->commissionRate);
        $this->assertSame($settings->deposit_rate, $resolved->depositRate);
    }

    public function test_the_platform_settings_are_seeded_from_config(): void
    {
        $settings = PaymentSettings::current();

        $this->assertSame((float) config('payments.commission_rate'), $settings->commission_rate);
        $this->assertSame((float) config('payments.deposit_rate'), $settings->deposit_rate);
    }

    public function test_a_partner_override_beats_the_platform_and_a_listing_override_beats_the_partner(): void
    {
        $listing = $this->listing();
        $partner = $listing->partner;

        $partner?->update(['commission_rate' => 8.0, 'deposit_rate' => 25.0]);

        $resolved = $this->rates->for($listing->fresh() ?? $listing);
        $this->assertSame(8.0, $resolved->commissionRate);
        $this->assertSame(25.0, $resolved->depositRate);

        $listing->update(['commission_rate' => 3.5, 'deposit_rate' => 40.0]);

        $resolved = $this->rates->for($listing->fresh() ?? $listing);
        $this->assertSame(3.5, $resolved->commissionRate);
        $this->assertSame(40.0, $resolved->depositRate);
    }

    /**
     * The rule that makes the sparse storage work. A null is not zero and not
     * "unset so use something arbitrary" — it means the level above.
     */
    public function test_a_null_at_one_level_falls_through_to_the_next(): void
    {
        $listing = $this->listing();
        $listing->partner?->update(['commission_rate' => 8.0, 'deposit_rate' => 25.0]);

        // The listing overrides only the deposit; commission falls through to
        // the partner.
        $listing->update(['deposit_rate' => 40.0]);

        $resolved = $this->rates->for($listing->fresh() ?? $listing);

        $this->assertSame(8.0, $resolved->commissionRate);
        $this->assertSame(40.0, $resolved->depositRate);

        // And clearing the partner's falls through again, to the platform.
        $listing->partner?->update(['commission_rate' => null]);

        $this->assertSame(
            PaymentSettings::current()->commission_rate,
            $this->rates->for($listing->fresh() ?? $listing)->commissionRate,
        );
    }

    public function test_the_deposit_floor_follows_the_commission_unless_it_is_set(): void
    {
        $settings = PaymentSettings::current();
        $settings->update(['commission_rate' => 7.0, 'minimum_deposit_rate' => null]);

        $this->assertSame(7.0, $settings->fresh()?->effectiveMinimumDepositRate());

        $settings->update(['minimum_deposit_rate' => 10.0]);

        $this->assertSame(10.0, $settings->fresh()?->effectiveMinimumDepositRate());
    }

    public function test_a_deposit_below_the_floor_is_refused_with_a_message_that_says_what_the_floor_is(): void
    {
        PaymentSettings::current()->update(['commission_rate' => 5.0, 'minimum_deposit_rate' => 15.0]);

        $listing = $this->listing();
        $partner = $listing->partner;

        $this->assertNotNull($partner);

        $refusal = $this->rates->refuseDeposit($partner, 10.0);

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('15%', $refusal);

        $this->assertNull($this->rates->refuseDeposit($partner, 15.0));
        $this->assertNull($this->rates->refuseDeposit($partner, 30.0));
    }

    public function test_zero_is_refused_like_any_other_deposit_below_the_floor(): void
    {
        $listing = $this->listing();
        $partner = $listing->partner;

        $this->assertNotNull($partner);
        $this->assertNotNull($this->rates->refuseDeposit($partner, 0.0));

        $this->expectException(InvalidArgumentException::class);
        $this->rates->assertDepositAllowed($partner, 0.0);
    }

    /*
    |--------------------------------------------------------------------------
    | Freezing
    |--------------------------------------------------------------------------
    */

    public function test_both_rates_and_their_amounts_are_frozen_onto_the_booking(): void
    {
        $listing = $this->listing();
        $listing->update(['commission_rate' => 10.0, 'deposit_rate' => 20.0]);

        $stay = $this->stay($listing->fresh() ?? $listing, $this->room($listing, 1000));

        // 2 nights at 1000, no charges: stay 2000, folio 2000.
        $this->assertSame(10.0, $stay->commission_rate);
        $this->assertSame(2000.0, $stay->commission_base);
        $this->assertSame(200.0, $stay->commission_amount);
        $this->assertSame(20.0, $stay->deposit_rate);
        $this->assertSame(400.0, $stay->deposit_amount);
    }

    public function test_a_platform_rate_change_does_not_alter_an_existing_bookings_frozen_rate(): void
    {
        $listing = $this->listing();
        $stay = $this->stay($listing, $this->room($listing, 1000));

        $before = [$stay->commission_rate, $stay->commission_amount];

        PaymentSettings::current()->update(['commission_rate' => 30.0, 'deposit_rate' => 90.0]);

        $stay->refresh();

        $this->assertSame($before, [$stay->commission_rate, $stay->commission_amount]);

        // And a booking taken afterwards does get the new rate — the freeze is
        // about the past, not a refusal to ever change.
        $next = $this->stay($listing, $this->room($listing, 1000), '2026-10-01', '2026-10-03');
        $this->assertSame(30.0, $next->commission_rate);
    }

    /**
     * PAYMENTS.md § 3: charging commission on the government's VAT and the
     * NTB's levy is indefensible in front of an operator.
     */
    public function test_commission_excludes_tax_and_levy_whether_added_or_included(): void
    {
        $listing = $this->listing();
        $listing->update(['commission_rate' => 10.0]);

        // One added on top, one already inside the rate.
        $this->charge($listing, 'VAT', ChargeKind::Tax, 15.0, included: false);
        $this->charge($listing, 'Tourism levy', ChargeKind::Levy, 2.0, included: true);
        // A property's own fee is revenue and stays in the base.
        $this->charge($listing, 'Conservancy fee', ChargeKind::Fee, 5.0, included: false);

        $stay = $this->stay($listing->fresh() ?? $listing, $this->room($listing, 1000));
        $charges = $stay->charges()->get();

        $includedLevy = $charges->firstWhere('kind', ChargeKind::Levy)?->amount ?? 0.0;

        // The base is the stay (2000) less the levy already inside it. The
        // added VAT and the added fee were never part of the stay amount.
        $this->assertSame(
            Money::cents(round(2000.0 - $includedLevy, 2)),
            Money::cents((float) $stay->commission_base),
        );
        $this->assertGreaterThan(0.0, $includedLevy, 'The included levy has to be non-zero for this test to mean anything.');

        // And the base is genuinely smaller than what the guest pays.
        $this->assertLessThan(Money::cents((float) $stay->total_amount), Money::cents((float) $stay->commission_base));
    }

    /**
     * A deposit is a commitment signal and a cash-flow arrangement, not a
     * share of revenue — so it is taken on the whole folio, tax included.
     */
    public function test_the_deposit_is_a_share_of_the_whole_folio(): void
    {
        $listing = $this->listing();
        $listing->update(['deposit_rate' => 10.0]);
        $this->charge($listing, 'VAT', ChargeKind::Tax, 15.0, included: false);

        $stay = $this->stay($listing->fresh() ?? $listing, $this->room($listing, 1000));

        $this->assertSame(
            Money::cents(round((float) $stay->total_amount * 0.10, 2)),
            Money::cents((float) $stay->deposit_amount),
        );
    }

    public function test_a_deposit_equal_to_the_commission_leaves_nothing_owed_either_way(): void
    {
        $listing = $this->listing();
        $listing->update(['commission_rate' => 5.0, 'deposit_rate' => 5.0]);

        $rates = $this->rates->for($listing->fresh() ?? $listing);

        $this->assertTrue($rates->depositCoversCommission());
        $this->assertSame($rates->commissionOn(1000.0), $rates->depositOn(1000.0));
    }

    /*
    |--------------------------------------------------------------------------
    | Plumbing
    |--------------------------------------------------------------------------
    */

    private function listing(string $name = 'Test Property'): Listing
    {
        $partner = Partner::create(['name' => $name, 'email' => fake()->unique()->safeEmail()]);

        return Listing::factory()->create([
            'partner_id' => $partner->id,
            'name' => $name,
            'is_published' => true,
        ]);
    }

    private function room(Listing $listing, float $rate = 1000): BookableUnit
    {
        return BookableUnit::factory()->create([
            'listing_id' => $listing->id,
            'total_units' => 5,
            'rate_per_night' => $rate,
            'currency' => 'NAD',
        ]);
    }

    private function charge(Listing $listing, string $name, ChargeKind $kind, float $rate, bool $included): Charge
    {
        return Charge::create([
            'listing_id' => $listing->id,
            'name' => $name,
            'kind' => $kind,
            'basis' => ChargeBasis::Percentage,
            'rate' => $rate,
            'is_included' => $included,
            'is_active' => true,
        ]);
    }

    private function stay(
        Listing $listing,
        BookableUnit $room,
        string $checkIn = '2026-09-10',
        string $checkOut = '2026-09-12',
    ): Reservation {
        return app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, Carbon::parse($checkIn), Carbon::parse($checkOut))],
            guestName: 'Test Guest',
            source: ReservationSource::WalkIn,
        ));
    }
}
