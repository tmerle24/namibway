<?php

namespace Tests\Feature\Filament;

use App\Enums\ListingType;
use App\Enums\OperatingMode;
use App\Enums\PaymentCollector;
use App\Enums\PaymentMethod;
use App\Enums\ReservationSource;
use App\Enums\StayStatus;
use App\Filament\Partner\Pages\ArrivalsDepartures;
use App\Filament\Partner\Pages\OccupancyCalendar;
use App\Filament\Partner\Pages\UnpaidStays;
use App\Models\BookableUnit;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use App\Services\Payments\DTOs\PaymentRequest;
use App\Services\Payments\Folio;
use App\Services\Payments\InvoiceIssuer;
use App\Services\Payments\PaymentRecorder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PAYMENTS.md § 2b: NamibWay is both a booking system and a PMS, and which one
 * a partner gets is a choice at setup rather than a fork in the product.
 *
 * The load-bearing test is `a booking_only partner still has a folio, payments
 * and an invoice`. If the mode ever changes what is *stored*, upgrading a
 * partner becomes a migration and two partners on one server stop being
 * comparable — the "two systems" problem this workstream exists to avoid,
 * reintroduced from the inside.
 */
class OperatingModeTest extends TestCase
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

    public function test_a_booking_only_partner_sees_no_desk_navigation(): void
    {
        [$user] = $this->partnerWithProperty(OperatingMode::BookingOnly);

        $this->asPartner($user);

        $this->assertFalse(ArrivalsDepartures::canAccess());

        // Everything that is not the desk stays.
        $this->assertTrue(OccupancyCalendar::canAccess());
        $this->assertTrue(UnpaidStays::canAccess());
    }

    public function test_a_full_partner_sees_the_desk(): void
    {
        [$user] = $this->partnerWithProperty(OperatingMode::Full);

        $this->asPartner($user);

        $this->assertTrue(ArrivalsDepartures::canAccess());
    }

    public function test_a_booking_only_partner_is_offered_no_check_in_buttons(): void
    {
        [$user, $listing] = $this->partnerWithProperty(OperatingMode::BookingOnly);
        $stay = $this->stay($listing);

        $this->asPartner($user);

        $page = Livewire::test(OccupancyCalendar::class)->call('showReservation', $stay->id);

        $this->assertSame([], $page->instance()->availableTransitions());
    }

    /**
     * A hidden button is a hidden button. The rule is on the action.
     */
    public function test_a_booking_only_partner_cannot_move_a_stay_by_calling_the_action(): void
    {
        [$user, $listing] = $this->partnerWithProperty(OperatingMode::BookingOnly);
        $stay = $this->stay($listing);

        $this->asPartner($user);

        Livewire::test(OccupancyCalendar::class)
            ->call('showReservation', $stay->id)
            ->call('transitionStay', StayStatus::DueIn->value);

        $this->assertSame(StayStatus::Confirmed, $stay->fresh()?->status);
    }

    public function test_a_full_partner_can_move_a_stay_through_its_day(): void
    {
        [$user, $listing] = $this->partnerWithProperty(OperatingMode::Full);
        $stay = $this->stay($listing);

        $this->asPartner($user);

        $page = Livewire::test(OccupancyCalendar::class)->call('showReservation', $stay->id);

        $this->assertNotSame([], $page->instance()->availableTransitions());

        $page->call('transitionStay', StayStatus::DueIn->value);

        $this->assertSame(StayStatus::DueIn, $stay->fresh()?->status);
    }

    /**
     * The whole slice, in one test. The mode hides screens; it never changes
     * what is recorded.
     */
    public function test_a_booking_only_partner_still_has_a_folio_payments_and_an_invoice(): void
    {
        [, $listing] = $this->partnerWithProperty(OperatingMode::BookingOnly);
        $stay = $this->stay($listing);

        // A folio, with everything on it.
        $this->assertNotNull($stay->total_amount);
        $this->assertNotNull($stay->commission_amount);
        $this->assertNotNull($stay->deposit_amount);

        // Payments.
        app(PaymentRecorder::class)->record(new PaymentRequest(
            reservation: $stay,
            amount: 500.0,
            method: PaymentMethod::Cash,
            collectedBy: PaymentCollector::Partner,
        ));

        $statement = app(Folio::class)->for($stay->fresh() ?? $stay);

        $this->assertSame(500.0, $statement->paid());
        $this->assertNotNull($statement->balance());

        // An invoice.
        $invoice = app(InvoiceIssuer::class)->issueForStay($stay->fresh() ?? $stay);

        $this->assertNotNull($invoice->number);
        $this->assertSame(1, $invoice->sequence_number);
    }

    /**
     * The guard, asserted the way the inventory architecture test asserts its
     * rule. `operating_mode` is a panel concern; the moment a service reads it,
     * the mode has started deciding what is stored.
     */
    public function test_nothing_under_app_services_reads_the_operating_mode(): void
    {
        $offences = [];

        foreach (File::allFiles(app_path('Services')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = File::get($file->getRealPath());

            foreach (['operating_mode', 'OperatingMode', 'runsFrontDesk'] as $needle) {
                if (str_contains($contents, $needle)) {
                    $offences[] = str_replace(base_path().'/', '', $file->getRealPath())." reads [{$needle}].";
                }
            }
        }

        $this->assertSame(
            [],
            $offences,
            "The operating mode decides what a partner *sees* and never what is recorded.\n"
            .'A service reading it means upgrading a partner becomes a migration:'
            ."\n - ".implode("\n - ", $offences)
        );
    }

    public function test_existing_partners_keep_the_desk_they_already_had(): void
    {
        // The default is `full`, deliberately: every partner that exists today
        // already has the arrivals board, and a migration that silently took a
        // working screen away would be a product change delivered as a schema
        // change.
        $partner = Partner::create(['name' => 'Legacy Lodge', 'email' => 'legacy@example.com']);

        $this->assertSame(OperatingMode::Full, $partner->fresh()?->operating_mode);
        $this->assertTrue($partner->runsFrontDesk());
    }

    /*
    |--------------------------------------------------------------------------
    | Plumbing
    |--------------------------------------------------------------------------
    */

    private function asPartner(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
    }

    /**
     * @return array{0: User, 1: Listing}
     */
    private function partnerWithProperty(OperatingMode $mode): array
    {
        $partner = Partner::create([
            'name' => 'Etosha Camp',
            'email' => fake()->unique()->safeEmail(),
            'operating_mode' => $mode,
        ]);

        $user = User::factory()->create(['partner_id' => $partner->id]);

        $listing = Listing::factory()->create([
            'partner_id' => $partner->id,
            'type' => ListingType::Accommodation,
            'name' => 'Etosha Camp',
        ]);

        return [$user, $listing];
    }

    private function stay(Listing $listing): Reservation
    {
        $room = BookableUnit::factory()->create([
            'listing_id' => $listing->id,
            'total_units' => 3,
            'base_rate' => 1500.00,
            'currency' => 'NAD',
            'is_active' => true,
        ]);

        return app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-12'))],
            guestName: 'Test Guest',
            guestEmail: 'guest@example.com',
            source: ReservationSource::WalkIn,
        ));
    }
}
