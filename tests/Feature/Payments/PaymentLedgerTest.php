<?php

namespace Tests\Feature\Payments;

use App\Enums\FolioStatus;
use App\Enums\PaymentCollector;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReservationSource;
use App\Models\BookableUnit;
use App\Models\Listing;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use App\Services\Payments\DTOs\PaymentRequest;
use App\Services\Payments\Folio;
use App\Services\Payments\PaymentRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The acceptance criteria of PAYMENTS_BUILD.md slice 1: a payment moves the
 * balance, a refund moves it back, nothing is edited, and the arithmetic is
 * done in cents rather than in floats.
 */
class PaymentLedgerTest extends TestCase
{
    use RefreshDatabase;

    private PaymentRecorder $recorder;

    private Folio $folio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recorder = app(PaymentRecorder::class);
        $this->folio = app(Folio::class);
    }

    public function test_a_payment_moves_the_balance_and_a_refund_moves_it_back(): void
    {
        $stay = $this->stay(rate: 1000, nights: 3);

        $this->assertSame(FolioStatus::Unpaid, $stay->payment_status);
        $this->assertSame(0.0, $stay->paid_amount);

        $this->pay($stay, 1200);

        $stay->refresh();
        $this->assertSame(1200.0, $stay->paid_amount);
        $this->assertSame(FolioStatus::PartPaid, $stay->payment_status);
        $this->assertSame(1800.0, $this->folio->for($stay)->balance());

        // A refund is a negative payment on the same folio — not a deletion
        // and not a second table.
        $this->pay($stay, -500);

        $stay->refresh();
        $this->assertSame(700.0, $stay->paid_amount);
        $this->assertSame(FolioStatus::PartPaid, $stay->payment_status);
        $this->assertSame(2300.0, $this->folio->for($stay)->balance());
        $this->assertCount(2, $stay->payments()->get());
    }

    /**
     * Guardrail 3. Two amounts that sum to the total in decimal arithmetic do
     * not necessarily sum to it in binary floating point, and a stay that
     * reads unpaid over a rounding difference is found by a guest at
     * check-out.
     */
    public function test_two_payments_that_together_equal_the_total_leave_the_stay_paid(): void
    {
        $stay = $this->stay(rate: 1000, nights: 3);

        $this->pay($stay, 1999.99);
        $this->pay($stay, 1000.01);

        $stay->refresh();
        $this->assertSame(FolioStatus::Paid, $stay->payment_status);
        $this->assertSame(0.0, $this->folio->for($stay)->balance());
    }

    public function test_one_cent_short_leaves_the_stay_part_paid(): void
    {
        $stay = $this->stay(rate: 1000, nights: 3);

        $this->pay($stay, 2999.99);

        $stay->refresh();
        $this->assertSame(FolioStatus::PartPaid, $stay->payment_status);
        $this->assertSame(0.01, $this->folio->for($stay)->balance());
    }

    public function test_more_than_the_total_leaves_the_stay_overpaid(): void
    {
        $stay = $this->stay(rate: 1000, nights: 2);

        $this->pay($stay, 2500);

        $stay->refresh();
        $this->assertSame(FolioStatus::Overpaid, $stay->payment_status);
        $this->assertSame(-500.0, $this->folio->for($stay)->balance());
    }

    public function test_a_reversal_references_the_payment_it_reverses_and_neither_row_is_mutated(): void
    {
        $stay = $this->stay(rate: 1000, nights: 2);

        $payment = $this->pay($stay, 2000);
        $original = $payment->only(['amount', 'method', 'status', 'received_at']);

        $reversal = $this->recorder->reverse($payment, 'Entered against the wrong stay.');

        $payment->refresh();

        $this->assertSame($payment->id, $reversal->reverses_payment_id);
        $this->assertSame(-2000.0, $reversal->amount);
        $this->assertTrue($reversal->isReversal());

        // The original is untouched — that is the whole discipline.
        $this->assertSame($original['amount'], $payment->amount);
        $this->assertSame($original['status'], $payment->status);
        $this->assertSame(2, Payment::query()->where('reservation_id', $stay->id)->count());

        $stay->refresh();
        $this->assertSame(0.0, $stay->paid_amount);
        $this->assertSame(FolioStatus::Unpaid, $stay->payment_status);
    }

    /**
     * A reversal takes the original's status, so the pair nets to zero on the
     * certain-money view as well as on the balance.
     *
     * The reversal used to be written as Cleared whatever it undid, on the
     * reasoning that a correction is certain the moment it is made. That is
     * true of the correction and false of the money: reversing a transfer
     * still sitting at Recorded left nothing cleared on the debit side and
     * N$ 700 cleared on the credit side, and the stay drawer duly printed
     * "Paid N$ 2,519.90 (N$ -700.00 confirmed)".
     */
    public function test_reversing_an_uncleared_transfer_does_not_leave_a_negative_confirmed_figure(): void
    {
        $stay = $this->stay(rate: 1000, nights: 2);

        $eft = $this->pay($stay, 700, PaymentMethod::Eft);
        $this->assertSame(PaymentStatus::Recorded, $eft->status);

        $reversal = $this->recorder->reverse($eft, 'Entered against the wrong stay.');

        $this->assertSame(PaymentStatus::Recorded, $reversal->status);

        $statement = $this->folio->for($stay->fresh() ?? $stay);
        $this->assertSame(0.0, $statement->paid());
        $this->assertSame(0.0, $statement->cleared());
    }

    /** And the other way: undoing money that had settled is itself settled. */
    public function test_reversing_a_cleared_payment_is_itself_cleared(): void
    {
        $stay = $this->stay(rate: 1000, nights: 2);

        $cash = $this->pay($stay, 700, PaymentMethod::Cash);
        $this->assertSame(PaymentStatus::Cleared, $cash->status);

        $this->assertSame(PaymentStatus::Cleared, $this->recorder->reverse($cash)->status);
        $this->assertSame(0.0, $this->folio->for($stay->fresh() ?? $stay)->cleared());
    }

    public function test_a_payment_cannot_be_reversed_twice(): void
    {
        $stay = $this->stay(rate: 1000, nights: 1);
        $payment = $this->pay($stay, 1000);

        $this->recorder->reverse($payment);

        $this->expectException(InvalidArgumentException::class);
        $this->recorder->reverse($payment);
    }

    /**
     * Guardrail 4: what was owed, what the guest handed over, and the rate
     * used are three facts. Converting at display time produces a ledger that
     * cannot be reconciled.
     */
    public function test_a_guest_paying_euro_against_a_nad_folio_stores_all_three_facts_and_still_balances(): void
    {
        $stay = $this->stay(rate: 1000, nights: 2);

        $payment = $this->recorder->record(new PaymentRequest(
            reservation: $stay,
            amount: 2000.0,
            method: PaymentMethod::Card,
            collectedBy: PaymentCollector::Partner,
            receivedAmount: 100.0,
            receivedCurrency: 'eur',
            exchangeRate: 20.0,
        ));

        $this->assertSame(2000.0, $payment->amount);
        $this->assertSame('NAD', $payment->currency);
        $this->assertSame(100.0, $payment->received_amount);
        $this->assertSame('EUR', $payment->received_currency);
        $this->assertSame(20.0, $payment->exchange_rate);

        $stay->refresh();
        $this->assertSame(FolioStatus::Paid, $stay->payment_status);
        $this->assertSame(0.0, $this->folio->for($stay)->balance());
    }

    public function test_a_foreign_payment_missing_one_of_the_three_facts_is_refused(): void
    {
        $stay = $this->stay(rate: 1000, nights: 1);

        $this->expectException(InvalidArgumentException::class);

        new PaymentRequest(
            reservation: $stay,
            amount: 1000.0,
            method: PaymentMethod::Card,
            collectedBy: PaymentCollector::Partner,
            receivedAmount: 50.0,
            receivedCurrency: 'EUR',
            // No rate — two of three cannot be reconciled later.
        );
    }

    /**
     * The case a system without a folio gets wrong: a cancelled stay with a
     * forfeited deposit still has a balance, and both sides need to see it.
     */
    public function test_a_cancelled_stay_keeps_its_folio_and_its_balance(): void
    {
        $stay = $this->stay(rate: 1000, nights: 3);
        $this->pay($stay, 900);

        app(InventoryWriter::class)->cancel($stay, 'Guest called to cancel.');

        $stay->refresh();
        $statement = $this->folio->for($stay);

        $this->assertSame(900.0, $statement->paid());
        $this->assertSame(2100.0, $statement->balance());
        $this->assertSame(FolioStatus::PartPaid, $stay->payment_status);
        $this->assertCount(1, $statement->payments);
    }

    public function test_a_failed_payment_does_not_count_towards_the_balance(): void
    {
        $stay = $this->stay(rate: 1000, nights: 2);

        $transfer = $this->recorder->record(new PaymentRequest(
            reservation: $stay,
            amount: 2000.0,
            method: PaymentMethod::Eft,
            collectedBy: PaymentCollector::NamibWay,
        ));

        // An EFT is somebody's word until the bank agrees, but it still counts
        // — a desk chasing money needs the stay to read as settled once the
        // guest says they have sent it.
        $this->assertSame(PaymentStatus::Recorded, $transfer->status);
        $this->assertSame(FolioStatus::Paid, $stay->fresh()?->payment_status);

        $this->recorder->markFailed($transfer);

        $stay->refresh();
        $this->assertSame(0.0, $stay->paid_amount);
        $this->assertSame(FolioStatus::Unpaid, $stay->payment_status);

        // The row stays. A payment that was expected and never came is
        // precisely what somebody goes looking for later.
        $this->assertCount(1, $stay->payments()->get());
    }

    public function test_cash_clears_immediately_and_a_transfer_does_not(): void
    {
        $stay = $this->stay(rate: 1000, nights: 2);

        $cash = $this->pay($stay, 500, PaymentMethod::Cash);
        $eft = $this->pay($stay, 500, PaymentMethod::Eft);

        $this->assertSame(PaymentStatus::Cleared, $cash->status);
        $this->assertSame(PaymentStatus::Recorded, $eft->status);

        $statement = $this->folio->for($stay->fresh() ?? $stay);
        $this->assertSame(1000.0, $statement->paid());
        $this->assertSame(500.0, $statement->cleared());

        $this->recorder->markCleared($eft);

        $statement = $this->folio->for($stay->fresh() ?? $stay);
        $this->assertSame(1000.0, $statement->cleared());
    }

    public function test_a_cleared_payment_cannot_go_back_to_recorded_or_failed(): void
    {
        $stay = $this->stay(rate: 1000, nights: 1);
        $cash = $this->pay($stay, 1000, PaymentMethod::Cash);

        $this->expectException(InvalidArgumentException::class);
        $this->recorder->markFailed($cash);
    }

    public function test_a_payment_of_nothing_is_refused(): void
    {
        $stay = $this->stay(rate: 1000, nights: 1);

        $this->expectException(InvalidArgumentException::class);
        $this->pay($stay, 0);
    }

    public function test_the_unpaid_list_holds_everything_that_is_not_square(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);

        $settled = $this->stayFor($listing, $room, '2026-09-01', '2026-09-03');
        $owing = $this->stayFor($listing, $room, '2026-09-05', '2026-09-07');

        $this->pay($settled, 2000);
        $this->pay($owing, 100);

        $outstanding = $this->folio->outstandingFor($listing);

        $this->assertSame([$owing->id], $outstanding->pluck('id')->all());
    }

    /*
    |--------------------------------------------------------------------------
    | Plumbing
    |--------------------------------------------------------------------------
    */

    private function listing(): Listing
    {
        return Listing::factory()->create(['is_published' => true]);
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

    private function stay(float $rate, int $nights): Reservation
    {
        $listing = $this->listing();
        $room = $this->room($listing, $rate);

        return $this->stayFor(
            $listing,
            $room,
            '2026-09-10',
            now()->parse('2026-09-10')->addDays($nights)->toDateString(),
        );
    }

    private function stayFor(Listing $listing, BookableUnit $room, string $checkIn, string $checkOut): Reservation
    {
        return app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, now()->parse($checkIn), now()->parse($checkOut))],
            guestName: 'Test Guest',
            source: ReservationSource::WalkIn,
        ));
    }

    private function pay(Reservation $stay, float $amount, PaymentMethod $method = PaymentMethod::Cash): Payment
    {
        return $this->recorder->record(new PaymentRequest(
            reservation: $stay,
            amount: $amount,
            method: $method,
            collectedBy: PaymentCollector::Partner,
        ));
    }
}
