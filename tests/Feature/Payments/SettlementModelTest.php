<?php

namespace Tests\Feature\Payments;

use App\Enums\ChargeBasis;
use App\Enums\ChargeKind;
use App\Enums\InvoiceIssuer;
use App\Enums\ReservationSource;
use App\Enums\SettlementModel;
use App\Enums\StayStatus;
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
use App\Services\Payments\Settlement\SettlementFactory;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PAYMENTS.md § 2: three models, chosen by one number.
 *
 * The test that matters most is the structural one — there is no
 * `settlement_model` column and there must never be one, because a stored
 * model plus a stored deposit is two places to say the same thing and
 * therefore a way to configure a partner into "merchant model, we collect
 * nothing".
 */
class SettlementModelTest extends TestCase
{
    use RefreshDatabase;

    private SettlementFactory $settlements;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settlements = app(SettlementFactory::class);
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_each_deposit_share_produces_the_expected_model(): void
    {
        $this->assertSame(SettlementModel::Agency, SettlementModel::forDepositRate(0.0));
        $this->assertSame(SettlementModel::Split, SettlementModel::forDepositRate(0.001));
        $this->assertSame(SettlementModel::Split, SettlementModel::forDepositRate(15.0));
        $this->assertSame(SettlementModel::Split, SettlementModel::forDepositRate(99.999));
        $this->assertSame(SettlementModel::Merchant, SettlementModel::forDepositRate(100.0));
    }

    /**
     * The model cannot contradict the deposit because there is nowhere to
     * store a contradiction.
     */
    public function test_the_settlement_model_is_not_a_stored_setting_anywhere(): void
    {
        foreach (['partners', 'listings', 'reservations', 'payment_settings'] as $table) {
            $this->assertFalse(
                Schema::hasColumn($table, 'settlement_model'),
                "[{$table}] has a settlement_model column. The model is derived from the deposit share "
                .'(App\\Enums\\SettlementModel) — storing it as well makes it possible to configure a '
                .'partner into an arrangement their deposit contradicts.'
            );
        }
    }

    public function test_a_stay_resolves_its_strategy_from_its_own_frozen_deposit_rate(): void
    {
        $listing = $this->listing();
        $listing->update(['deposit_rate' => 100.0]);

        $stay = $this->stay($listing->fresh() ?? $listing, $this->room($listing));

        $this->assertSame(SettlementModel::Merchant, $this->settlements->for($stay)->model());

        // The property renegotiates. The stay keeps the arrangement it was
        // taken under — which is what makes a statement about last season
        // still add up.
        $listing->update(['deposit_rate' => 15.0]);

        $this->assertSame(SettlementModel::Merchant, $this->settlements->for($stay->fresh() ?? $stay)->model());
        $this->assertSame(SettlementModel::Split, $this->settlements->forListing($listing->fresh() ?? $listing)->model());
    }

    public function test_the_agency_model_collects_nothing_and_leaves_the_commission_to_be_invoiced(): void
    {
        $listing = $this->listing(allowZeroDeposit: true);
        $listing->update(['commission_rate' => 5.0, 'deposit_rate' => 0.0]);

        $stay = $this->stay($listing->fresh() ?? $listing, $this->room($listing, 1000));
        $strategy = $this->settlements->for($stay);

        $this->assertSame(SettlementModel::Agency, $strategy->model());
        $this->assertSame(0.0, $strategy->collectFromGuest($stay));

        $balance = $strategy->balanceWithPartner($stay);
        $this->assertTrue($balance->isOwedToUs());
        $this->assertSame(-100.0, $balance->amount); // 5% of 2000
        $this->assertSame(InvoiceIssuer::Partner, $strategy->guestInvoiceIssuer());
        $this->assertTrue($strategy->model()->commissionMustBeInvoiced());
    }

    public function test_the_merchant_model_collects_everything_and_pays_out_net(): void
    {
        $listing = $this->listing();
        $listing->update(['commission_rate' => 5.0, 'deposit_rate' => 100.0]);

        $stay = $this->stay($listing->fresh() ?? $listing, $this->room($listing, 1000));
        $strategy = $this->settlements->for($stay);

        $this->assertSame(SettlementModel::Merchant, $strategy->model());
        $this->assertSame(2000.0, $strategy->collectFromGuest($stay));

        $balance = $strategy->balanceWithPartner($stay);
        $this->assertTrue($balance->isOwedToPartner());
        $this->assertSame(1900.0, $balance->amount);
        $this->assertSame(InvoiceIssuer::NamibWay, $strategy->guestInvoiceIssuer());
    }

    /**
     * The case the default exists for: at the floor, nothing has to move
     * between us and the partner at all — no payout run, no statement, no
     * reconciliation.
     */
    public function test_a_deposit_equal_to_the_commission_leaves_nothing_owed_in_either_direction(): void
    {
        $listing = $this->listing();
        $listing->update(['commission_rate' => 5.0, 'deposit_rate' => 5.0]);

        $stay = $this->stay($listing->fresh() ?? $listing, $this->room($listing, 1000));
        $strategy = $this->settlements->for($stay);

        $this->assertSame(SettlementModel::Split, $strategy->model());

        $balance = $strategy->balanceWithPartner($stay);

        $this->assertTrue($balance->isSettled());
        $this->assertFalse($balance->isOwedToPartner());
        $this->assertFalse($balance->isOwedToUs());
        $this->assertSame('Nothing to move in either direction.', $balance->describe());
    }

    public function test_a_deposit_above_the_commission_leaves_the_difference_owed_to_the_partner(): void
    {
        $listing = $this->listing();
        $listing->update(['commission_rate' => 5.0, 'deposit_rate' => 20.0]);

        $stay = $this->stay($listing->fresh() ?? $listing, $this->room($listing, 1000));

        // 20% of 2000 collected, 5% of 2000 earned.
        $this->assertSame(300.0, $this->settlements->for($stay)->balanceWithPartner($stay)->amount);
    }

    /*
    |--------------------------------------------------------------------------
    | Commission earned and reversed
    |--------------------------------------------------------------------------
    */

    public function test_a_confirmed_booking_has_earned_commission_and_a_provisional_hold_has_not(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);

        $confirmed = $this->stay($listing, $room, status: StayStatus::Confirmed);
        $held = $this->stay($listing, $room, '2026-10-01', '2026-10-03', status: StayStatus::Provisional);

        $this->assertNotNull($confirmed->commission_earned_at);
        $this->assertNull($held->commission_earned_at);

        // Confirming the hold is the moment the question becomes a sale.
        app(InventoryWriter::class)->transition($held, StayStatus::Confirmed);

        $this->assertNotNull($held->fresh()?->commission_earned_at);
    }

    public function test_cancelling_a_stay_reverses_the_earned_commission(): void
    {
        $listing = $this->listing();
        $stay = $this->stay($listing, $this->room($listing));

        $earned = $stay->commission_amount;
        $this->assertNotNull($stay->commission_earned_at);

        app(InventoryWriter::class)->cancel($stay, 'Guest called to cancel.', late: false);

        $stay->refresh();

        $this->assertNull($stay->commission_earned_at);
        // The amount stays: the record of what the booking would have earned
        // is not something a cancellation should destroy.
        $this->assertSame($earned, $stay->commission_amount);
    }

    /**
     * The guest was charged a penalty. Earning nothing on it would be us
     * absorbing the property's.
     */
    public function test_a_late_cancellation_keeps_the_commission(): void
    {
        $listing = $this->listing();
        $stay = $this->stay($listing, $this->room($listing));

        app(InventoryWriter::class)->cancel($stay, 'Cancelled the night before.', late: true);

        $stay->refresh();

        $this->assertSame(StayStatus::CancelledLate, $stay->status);
        $this->assertNotNull($stay->commission_earned_at);
    }

    public function test_a_later_transition_does_not_move_the_moment_it_was_earned(): void
    {
        $listing = $this->listing();
        $stay = $this->stay($listing, $this->room($listing), '2026-09-10', '2026-09-12');

        $earnedAt = $stay->commission_earned_at;

        Carbon::setTestNow(Carbon::parse('2026-09-10 14:00:00'));
        app(InventoryWriter::class)->transition($stay, StayStatus::DueIn);
        app(InventoryWriter::class)->transition($stay, StayStatus::InHouse);

        // What we earned in the morning must not become the afternoon's number
        // because the guest checked in.
        $this->assertEquals($earnedAt, $stay->fresh()?->commission_earned_at);
    }

    public function test_commission_excludes_tax_and_levy_on_a_stay_that_has_both(): void
    {
        $listing = $this->listing();
        $listing->update(['commission_rate' => 10.0]);

        $this->charge($listing, 'VAT', ChargeKind::Tax, 15.0, included: false);
        $this->charge($listing, 'Tourism levy', ChargeKind::Levy, 2.0, included: false);

        $stay = $this->stay($listing->fresh() ?? $listing, $this->room($listing, 1000));

        // Both charges are added on top, so the base is the stay itself.
        $this->assertSame(2000.0, $stay->commission_base);
        $this->assertSame(200.0, $stay->commission_amount);
        $this->assertGreaterThan(
            Money::cents(2000.0),
            Money::cents((float) $stay->total_amount),
            'The guest pays more than the commission base — that is the point of the test.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The zero unlock
    |--------------------------------------------------------------------------
    */

    public function test_a_partner_without_the_unlock_cannot_reach_zero(): void
    {
        $listing = $this->listing();
        $partner = $listing->partner;

        $this->assertNotNull($partner);

        $refusal = app(RateResolver::class)->refuseDeposit($partner, 0.0);

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('invoice you for commission', $refusal);
    }

    public function test_a_partner_with_the_unlock_may_set_zero_even_though_it_is_below_the_floor(): void
    {
        PaymentSettings::current()->update(['commission_rate' => 5.0, 'minimum_deposit_rate' => 15.0]);

        $listing = $this->listing(allowZeroDeposit: true);
        $partner = $listing->partner;

        $this->assertNotNull($partner);

        $this->assertNull(app(RateResolver::class)->refuseDeposit($partner, 0.0));
        // Still not a licence to pick any number below the floor.
        $this->assertNotNull(app(RateResolver::class)->refuseDeposit($partner, 2.0));
    }

    /*
    |--------------------------------------------------------------------------
    | Plumbing
    |--------------------------------------------------------------------------
    */

    private function listing(string $name = 'Test Property', bool $allowZeroDeposit = false): Listing
    {
        $partner = Partner::create([
            'name' => $name,
            'email' => fake()->unique()->safeEmail(),
            'allow_zero_deposit' => $allowZeroDeposit,
        ]);

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
            'base_rate' => $rate,
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
        StayStatus $status = StayStatus::Confirmed,
    ): Reservation {
        return app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, Carbon::parse($checkIn), Carbon::parse($checkOut))],
            guestName: 'Test Guest',
            source: ReservationSource::WalkIn,
            status: $status,
        ));
    }
}
