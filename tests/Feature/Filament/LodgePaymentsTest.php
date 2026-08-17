<?php

namespace Tests\Feature\Filament;

use App\Enums\FolioStatus;
use App\Enums\ListingType;
use App\Enums\PaymentCollector;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReservationSource;
use App\Filament\Partner\Pages\ArrivalsDepartures;
use App\Filament\Partner\Pages\UnpaidStays;
use App\Models\BookableUnit;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use App\Services\Payments\DTOs\PaymentRequest;
use App\Services\Payments\PaymentRecorder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Taking money from the lodge screens.
 *
 * The scoping tests matter here the way they do everywhere in this panel, and
 * more: reading another partner's guest list is bad, and putting a payment on
 * their booking is worse.
 */
class LodgePaymentsTest extends TestCase
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

    public function test_a_payment_recorded_from_the_board_moves_the_balance(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $room = $this->bookableUnit($listing);
        $stay = $this->stay($listing, $room);

        $this->asPartner($user);

        Livewire::test(ArrivalsDepartures::class)
            ->call('showReservation', $stay->id)
            ->callAction('recordPayment', [
                'amount' => 1500,
                'method' => PaymentMethod::Cash->value,
                'received_at' => '2026-09-10',
            ])
            ->assertHasNoActionErrors();

        $stay->refresh();

        $this->assertSame(1500.0, $stay->paid_amount);
        $this->assertSame(FolioStatus::PartPaid, $stay->payment_status);

        $payment = $stay->payments()->sole();
        $this->assertSame(PaymentCollector::Partner, $payment->collected_by);
        $this->assertSame(PaymentStatus::Cleared, $payment->status);
        $this->assertSame($user->id, $payment->recorded_by);
    }

    /**
     * The sign is the button's job. A desk that has to type a minus to give
     * money back will one day type it by accident on a payment.
     */
    public function test_a_refund_recorded_from_the_board_is_a_negative_line(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $room = $this->bookableUnit($listing);
        $stay = $this->stay($listing, $room);

        $this->pay($stay, 3000);

        $this->asPartner($user);

        Livewire::test(ArrivalsDepartures::class)
            ->call('showReservation', $stay->id)
            ->callAction('recordRefund', [
                'amount' => 500,
                'method' => PaymentMethod::Eft->value,
                'received_at' => '2026-09-10',
            ])
            ->assertHasNoActionErrors();

        $stay->refresh();

        $this->assertSame(2500.0, $stay->paid_amount);
        $this->assertSame(-500.0, $stay->payments()->orderByDesc('id')->first()?->amount);
    }

    public function test_a_payment_can_be_corrected_from_the_drawer(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $room = $this->bookableUnit($listing);
        $stay = $this->stay($listing, $room);

        $payment = $this->pay($stay, 3000);

        $this->asPartner($user);

        Livewire::test(ArrivalsDepartures::class)
            ->call('showReservation', $stay->id)
            ->call('selectPayment', $payment->id)
            ->assertSet('selectedPaymentId', $payment->id)
            ->callAction('reversePayment', ['reason' => 'Wrong stay.'])
            ->assertHasNoActionErrors();

        $stay->refresh();

        $this->assertSame(0.0, $stay->paid_amount);
        $this->assertSame(FolioStatus::Unpaid, $stay->payment_status);
        // Both rows stay. That is the whole discipline.
        $this->assertSame(2, $stay->payments()->count());
    }

    public function test_a_payment_belonging_to_another_partner_cannot_be_selected(): void
    {
        [$mine, $myListing] = $this->partnerWithProperty('Mine');
        $myStay = $this->stay($myListing, $this->bookableUnit($myListing));

        [, $theirListing] = $this->partnerWithProperty('Theirs');
        $theirStay = $this->stay($theirListing, $this->bookableUnit($theirListing));
        $theirPayment = $this->pay($theirStay, 1000);

        $this->asPartner($mine);

        Livewire::test(ArrivalsDepartures::class)
            ->call('showReservation', $myStay->id)
            ->call('selectPayment', $theirPayment->id)
            ->assertSet('selectedPaymentId', null);
    }

    public function test_the_unpaid_list_shows_only_this_partners_outstanding_stays(): void
    {
        [$mine, $myListing] = $this->partnerWithProperty('Mine');
        $room = $this->bookableUnit($myListing);

        $owing = $this->stay($myListing, $room, '2026-09-20', '2026-09-22');
        $settled = $this->stay($myListing, $room, '2026-09-25', '2026-09-27');
        $this->pay($settled, (float) $settled->total_amount);

        [, $theirListing] = $this->partnerWithProperty('Theirs');
        $theirs = $this->stay($theirListing, $this->bookableUnit($theirListing));

        $this->asPartner($mine);

        Livewire::test(UnpaidStays::class)
            ->assertSee($owing->reference)
            ->assertDontSee($settled->reference)
            ->assertDontSee($theirs->reference);
    }

    public function test_the_board_shows_what_a_stay_still_owes(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $room = $this->bookableUnit($listing);
        $stay = $this->stay($listing, $room, '2026-09-10', '2026-09-12');

        $this->pay($stay, 1000);

        $this->asPartner($user);

        // 2 nights at 1500 is 3000; 1000 in leaves 2000 to collect at the desk.
        Livewire::test(ArrivalsDepartures::class)->assertSee('N$ 2,000.00');
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

    private function bookableUnit(Listing $listing): BookableUnit
    {
        return BookableUnit::factory()->create([
            'listing_id' => $listing->id,
            'total_units' => 3,
            'base_rate' => 1500.00,
            'currency' => 'NAD',
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

    private function pay(Reservation $stay, float $amount): Payment
    {
        return app(PaymentRecorder::class)->record(new PaymentRequest(
            reservation: $stay,
            amount: $amount,
            method: PaymentMethod::Cash,
            collectedBy: PaymentCollector::Partner,
        ));
    }
}
