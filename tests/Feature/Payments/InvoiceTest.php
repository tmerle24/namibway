<?php

namespace Tests\Feature\Payments;

use App\Enums\ChargeBasis;
use App\Enums\ChargeKind;
use App\Enums\InvoiceIssuer as Issuer;
use App\Enums\InvoiceKind;
use App\Enums\ReservationSource;
use App\Exceptions\Payments\DirectMoneyWriteException;
use App\Exceptions\Payments\IssuedInvoiceException;
use App\Models\BookableUnit;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Reservation;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use App\Services\Payments\InvoiceDocument;
use App\Services\Payments\InvoiceIssuer;
use App\Services\Payments\MoneyWriteGuard;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * The acceptance criteria of PAYMENTS_BUILD.md slice 2. An invoice is a legal
 * document, so what is tested here is not "does it render" but "can it be
 * changed, can it be duplicated, and does it still say what it said".
 *
 * The concurrency half lives in InvoiceNumberingConcurrencyTest, because it
 * needs real forked processes.
 */
class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceIssuer $issuer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->issuer = app(InvoiceIssuer::class);
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_numbers_are_sequential_and_unique_within_a_series_and_year(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);

        $first = $this->issuer->issueForStay($this->stay($listing, $room, '2026-09-10', '2026-09-12'));
        $second = $this->issuer->issueForStay($this->stay($listing, $room, '2026-09-12', '2026-09-14'));
        $third = $this->issuer->issueForStay($this->stay($listing, $room, '2026-09-14', '2026-09-16'));

        $this->assertSame([1, 2, 3], [
            $first->sequence_number,
            $second->sequence_number,
            $third->sequence_number,
        ]);

        $this->assertSame(3, Invoice::query()->distinct()->count('number'));
        $this->assertStringEndsWith('-2026-000001', $first->number);
    }

    /**
     * A property's invoices are its own legal record and must not have another
     * property's numbering interleaved into them.
     */
    public function test_each_issuing_entity_counts_on_its_own(): void
    {
        $mine = $this->listing('Etosha Camp');
        $theirs = $this->listing('Sossus Lodge');

        $a = $this->issuer->issueForStay($this->stay($mine, $this->room($mine)));
        $b = $this->issuer->issueForStay($this->stay($theirs, $this->room($theirs)));

        $this->assertSame(1, $a->sequence_number);
        $this->assertSame(1, $b->sequence_number);
        $this->assertNotSame($a->series, $b->series);
        $this->assertNotSame($a->number, $b->number);
    }

    /**
     * The reason the counter is a locked row rather than an auto-increment: a
     * failed issue must not burn a number. A gap is what an auditor asks about
     * first, because the innocent and the fraudulent explanation look the same.
     */
    public function test_a_rolled_back_issue_leaves_no_gap(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);

        $first = $this->issuer->issueForStay($this->stay($listing, $room, '2026-09-10', '2026-09-12'));

        try {
            DB::transaction(function () use ($listing, $room): void {
                $this->issuer->issueForStay($this->stay($listing, $room, '2026-09-12', '2026-09-14'));

                throw new RuntimeException('Something went wrong after the number was taken.');
            });
        } catch (RuntimeException) {
            // Expected — the point is what the counter looks like afterwards.
        }

        $next = $this->issuer->issueForStay($this->stay($listing, $room, '2026-09-14', '2026-09-16'));

        $this->assertSame(1, $first->sequence_number);
        $this->assertSame(2, $next->sequence_number, 'A rolled-back issue must return its number.');
        $this->assertSame(2, Invoice::count());
    }

    /**
     * Two different promises, tested separately because they protect against
     * two different people. The write guard stops code that never meant to be
     * writing money at all; immutability stops the writer *itself*, which is
     * the one that would otherwise have a good reason.
     */
    public function test_an_issued_invoice_cannot_be_modified_even_from_inside_the_write_path(): void
    {
        $listing = $this->listing();
        $invoice = $this->issuer->issueForStay($this->stay($listing, $this->room($listing)));

        $this->expectException(IssuedInvoiceException::class);

        MoneyWriteGuard::allow(function () use ($invoice): void {
            $invoice->total = 1.0;
            $invoice->save();
        });
    }

    public function test_an_issued_invoice_cannot_be_deleted_even_from_inside_the_write_path(): void
    {
        $listing = $this->listing();
        $invoice = $this->issuer->issueForStay($this->stay($listing, $this->room($listing)));

        $this->expectException(IssuedInvoiceException::class);

        MoneyWriteGuard::allow(fn () => $invoice->delete());
    }

    public function test_an_invoice_cannot_be_written_from_outside_the_write_path_at_all(): void
    {
        $listing = $this->listing();
        $invoice = $this->issuer->issueForStay($this->stay($listing, $this->room($listing)));

        $this->expectException(DirectMoneyWriteException::class);

        $invoice->total = 1.0;
        $invoice->save();
    }

    public function test_a_credit_note_references_its_invoice_and_the_pair_nets_to_the_corrected_amount(): void
    {
        $listing = $this->listing();
        $invoice = $this->issuer->issueForStay($this->stay($listing, $this->room($listing)));

        $credit = $this->issuer->creditNoteFor($invoice, 'Booked against the wrong property.');

        $this->assertSame(InvoiceKind::CreditNote, $credit->kind);
        $this->assertSame($invoice->id, $credit->corrects_invoice_id);
        $this->assertSame($invoice->series, $credit->series);
        $this->assertSame($invoice->sequence_number + 1, $credit->sequence_number);

        // The pair nets to nothing — the invoice is fully taken back.
        $this->assertSame(
            0,
            Money::cents($invoice->total) + Money::cents($credit->total),
        );
        $this->assertSame(0.0, $invoice->fresh()?->netTotal());
        $this->assertTrue($invoice->fresh()?->isCancelled());

        // The tax is reversed too, and visibly: an accountant checking the VAT
        // was credited needs the line, not a lump sum.
        $this->assertSame(-Money::cents($invoice->tax_amount), Money::cents($credit->tax_amount));
    }

    public function test_an_invoice_cannot_be_credited_twice(): void
    {
        $listing = $this->listing();
        $invoice = $this->issuer->issueForStay($this->stay($listing, $this->room($listing)));

        $this->issuer->creditNoteFor($invoice);

        $this->expectException(InvalidArgumentException::class);
        $this->issuer->creditNoteFor($invoice);
    }

    public function test_a_credit_note_cannot_itself_be_credited(): void
    {
        $listing = $this->listing();
        $invoice = $this->issuer->issueForStay($this->stay($listing, $this->room($listing)));
        $credit = $this->issuer->creditNoteFor($invoice);

        $this->expectException(InvalidArgumentException::class);
        $this->issuer->creditNoteFor($credit);
    }

    /**
     * The whole reason `lines` is a frozen snapshot rather than a view.
     */
    public function test_the_snapshot_survives_the_charge_it_came_from_being_changed(): void
    {
        $listing = $this->listing();
        $this->vatCharge($listing, 15.0);
        $room = $this->room($listing);

        $invoice = $this->issuer->issueForStay($this->stay($listing, $room, '2026-09-10', '2026-09-12'));

        // Read back from the database on both sides. Postgres normalises jsonb
        // rather than storing the bytes it was given, so an in-memory array
        // and a stored one differ in key order while saying the same thing —
        // comparing those two would test the driver, not the freeze.
        $invoice->refresh();
        $before = $invoice->lineItems();
        $taxBefore = $invoice->tax_amount;

        // The property changes its VAT and renames it. Nothing about the
        // issued document may move.
        Charge::query()->where('listing_id', $listing->id)->update([
            'rate' => 20.0,
            'name' => 'Value Added Tax (new)',
        ]);

        $invoice->refresh();

        $this->assertSame($before, $invoice->lineItems());
        $this->assertSame($taxBefore, $invoice->tax_amount);
        $this->assertStringContainsString('15', json_encode($invoice->lineItems()) ?: '');
    }

    public function test_vat_is_a_line_of_its_own_and_the_total_adds_up(): void
    {
        $listing = $this->listing();
        $this->vatCharge($listing, 15.0);
        $room = $this->room($listing, 1000.0);

        $stay = $this->stay($listing, $room, '2026-09-10', '2026-09-12');
        $invoice = $this->issuer->issueForStay($stay);

        $taxLines = array_filter(
            $invoice->lineItems(),
            fn (array $line): bool => ($line['kind'] ?? null) === ChargeKind::Tax->value,
        );

        $this->assertCount(1, $taxLines, 'VAT is its own line, not folded into the stay.');
        $this->assertSame(Money::cents((float) $stay->total_amount), Money::cents($invoice->total));
        $this->assertSame(
            Money::cents($invoice->total),
            Money::cents($invoice->subtotal) + Money::cents($invoice->tax_amount),
        );
        $this->assertGreaterThan(0, Money::cents($invoice->tax_amount));
    }

    public function test_a_stay_with_no_price_cannot_be_invoiced(): void
    {
        $listing = $this->listing();
        $stay = $this->stay($listing, $this->room($listing));

        app(InventoryWriter::class)->recordFolio($stay, 0.0, $stay->payment_status);
        DB::table('reservations')->where('id', $stay->id)->update(['total_amount' => null]);

        $this->expectException(InvalidArgumentException::class);
        $this->issuer->issueForStay($stay->fresh() ?? $stay);
    }

    public function test_the_pdf_renders_from_the_snapshot(): void
    {
        $listing = $this->listing();
        $this->vatCharge($listing, 15.0);
        $invoice = $this->issuer->issueForStay($this->stay($listing, $this->room($listing)));

        $pdf = app(InvoiceDocument::class)->render($invoice);
        $output = $pdf->output();

        $this->assertStringStartsWith('%PDF', $output);
        $this->assertSame(strtolower($invoice->number).'.pdf', app(InvoiceDocument::class)->filename($invoice));
    }

    public function test_the_issuer_name_is_frozen_onto_the_document(): void
    {
        $listing = $this->listing('Etosha Camp');
        $invoice = $this->issuer->issueForStay($this->stay($listing, $this->room($listing)));

        $this->assertSame('Etosha Camp', $invoice->issuer_name);

        $listing->partner?->update(['name' => 'Renamed Holdings']);

        $this->assertSame('Etosha Camp', $invoice->fresh()?->issuer_name);
    }

    public function test_we_can_issue_under_our_own_name_and_series(): void
    {
        $listing = $this->listing();
        $invoice = $this->issuer->issueForStay($this->stay($listing, $this->room($listing)), Issuer::NamibWay);

        $this->assertSame('NW', $invoice->series);
        $this->assertSame('NamibWay', $invoice->issuer_name);
        $this->assertSame(Issuer::NamibWay, $invoice->issuer);
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

    private function vatCharge(Listing $listing, float $rate): Charge
    {
        return Charge::create([
            'listing_id' => $listing->id,
            'name' => 'VAT',
            'kind' => ChargeKind::Tax,
            'basis' => ChargeBasis::Percentage,
            'rate' => $rate,
            'is_included' => false,
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
            guestEmail: 'guest@example.com',
            source: ReservationSource::WalkIn,
        ));
    }
}
