<?php

namespace App\Services\Payments;

use App\Enums\InvoiceIssuer as Issuer;
use App\Enums\InvoiceKind;
use App\Models\Invoice;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Reservation;
use App\Services\Payments\DTOs\InvoiceLine;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only thing that issues an invoice.
 *
 * Two guarantees live here and nowhere else, and both are the kind that cannot
 * be added later:
 *
 * **Numbering is gapless per series and year.** The number comes from a
 * counter row locked with `SELECT … FOR UPDATE` inside the issuing
 * transaction. A second issuer blocks rather than reading a stale value, so two
 * check-outs in the same second cannot take the same number; and a rollback
 * returns the counter with everything else, so a failed issue leaves no hole.
 * Neither `max(number) + 1` nor an auto-increment can offer both — see the
 * invoice_sequences migration for why each fails.
 *
 * **The document is frozen.** The lines are built once, from the stay as it
 * stands, and written into the invoice. Nothing reads back through to
 * `reservation_nights` or `reservation_charges` afterwards, which is what lets
 * a property rename a rate plan or change a VAT rate without rewriting history.
 */
class InvoiceIssuer
{
    /**
     * Raise the guest's invoice for a stay.
     *
     * The issuer is asked for rather than derived, because which business
     * invoices the guest is a property of the settlement model and that model
     * does not exist yet (slice 4). Defaulting to the property is the honest
     * answer today: every stay in the system was sold by a property, and we
     * have collected nothing.
     */
    public function issueForStay(
        Reservation $reservation,
        Issuer $issuer = Issuer::Partner,
        ?int $issuedBy = null,
    ): Invoice {
        if ($reservation->total_amount === null) {
            throw new InvalidArgumentException(
                'That stay has no price yet, so there is nothing to invoice. Price it first.'
            );
        }

        $lines = $this->linesForStay($reservation);

        return $this->issue(
            issuer: $issuer,
            issuerName: $this->issuerName($issuer, $this->partnerOf($reservation)),
            kind: InvoiceKind::Stay,
            series: $this->seriesFor($issuer, $this->partnerOf($reservation)),
            currency: $reservation->currency,
            lines: $lines,
            billToName: $reservation->guest_name,
            billToEmail: $reservation->guest_email,
            reservation: $reservation,
            partner: $this->partnerOf($reservation),
            issuedBy: $issuedBy,
        );
    }

    /**
     * Correct an invoice by crediting it in full.
     *
     * Its own document with its own number — the original is never touched.
     * Every line is negated rather than a single "credit" line written,
     * because a credit note has to be readable beside the invoice it corrects:
     * an accountant checking that the VAT was reversed needs to see the VAT
     * line, not a lump sum.
     */
    public function creditNoteFor(Invoice $invoice, ?string $reason = null, ?int $issuedBy = null): Invoice
    {
        if ($invoice->kind === InvoiceKind::CreditNote) {
            throw new InvalidArgumentException(
                'A credit note cannot itself be credited. Issue a fresh invoice for what is actually owed.'
            );
        }

        if ($invoice->creditNote()->exists()) {
            throw new InvalidArgumentException("Invoice [{$invoice->number}] has already been credited.");
        }

        $lines = array_map(
            fn (InvoiceLine $line): InvoiceLine => $line->negated(),
            $this->readLines($invoice),
        );

        if ($reason !== null && $reason !== '') {
            $lines[] = new InvoiceLine(description: $reason, amount: 0.0);
        }

        return $this->issue(
            issuer: $invoice->issuer,
            // The correcting document is issued by whoever issued the original,
            // under the name that was on it — a credit note that named a
            // renamed business would not obviously belong to the invoice it
            // corrects.
            issuerName: $invoice->issuer_name,
            kind: InvoiceKind::CreditNote,
            series: $invoice->series,
            currency: $invoice->currency,
            lines: $lines,
            billToName: $invoice->bill_to_name,
            billToEmail: $invoice->bill_to_email,
            reservation: $invoice->reservation,
            partner: $invoice->partner,
            corrects: $invoice,
            issuedBy: $issuedBy,
        );
    }

    /**
     * The write itself: take a number, freeze the lines, insert. One
     * transaction, because a number taken without a document is the gap this
     * whole design exists to prevent.
     *
     * @param  array<int, InvoiceLine>  $lines
     */
    private function issue(
        Issuer $issuer,
        string $issuerName,
        InvoiceKind $kind,
        string $series,
        string $currency,
        array $lines,
        string $billToName,
        ?string $billToEmail,
        ?Reservation $reservation = null,
        ?Partner $partner = null,
        ?Invoice $corrects = null,
        ?int $issuedBy = null,
        ?string $billToAddress = null,
    ): Invoice {
        if ($lines === []) {
            throw new InvalidArgumentException('An invoice with no lines is not a document.');
        }

        $issuedAt = Carbon::now();

        return DB::transaction(function () use (
            $issuer, $issuerName, $kind, $series, $currency, $lines, $billToName, $billToEmail,
            $reservation, $partner, $corrects, $issuedBy, $billToAddress, $issuedAt,
        ): Invoice {
            $year = (int) $issuedAt->year;
            $number = $this->takeNumber($series, $year);

            return MoneyWriteGuard::allow(fn (): Invoice => Invoice::create([
                'number' => $this->formatNumber($series, $year, $number),
                'series' => $series,
                'year' => $year,
                'sequence_number' => $number,
                'issued_at' => $issuedAt,
                'issuer' => $issuer,
                'issuer_name' => $issuerName,
                'kind' => $kind,
                'reservation_id' => $reservation?->id,
                'partner_id' => $partner?->id,
                'corrects_invoice_id' => $corrects?->id,
                'currency' => strtoupper($currency),
                'subtotal' => $this->subtotalOf($lines),
                'tax_amount' => $this->taxOf($lines),
                'total' => $this->totalOf($lines),
                'lines' => array_map(fn (InvoiceLine $line): array => $line->toArray(), $lines),
                'bill_to_name' => $billToName,
                'bill_to_email' => $billToEmail,
                'bill_to_address' => $billToAddress,
                'issued_by' => $issuedBy,
            ]));
        });
    }

    /**
     * The next number in a series, taken under a row lock.
     *
     * `lockForUpdate()` is the whole mechanism. The row is created first if it
     * does not exist — `insertOrIgnore` rather than a check, so two issuers
     * starting a new year at the same moment do not both insert — and then
     * locked, read and advanced. A concurrent issuer waits at the lock until
     * this transaction commits or rolls back, and either way sees a truthful
     * counter.
     *
     * Written through the query builder rather than Eloquent because
     * `lockForUpdate()` is the point of the call; the InvoiceSequence model
     * exists to give the table a name and the write guard something to hold.
     */
    private function takeNumber(string $series, int $year): int
    {
        DB::table('invoice_sequences')->insertOrIgnore([
            'series' => $series,
            'year' => $year,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('invoice_sequences')
            ->where('series', $series)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw new InvalidArgumentException("Could not take a number in series [{$series}] for {$year}.");
        }

        $number = (int) $row->next_number;

        DB::table('invoice_sequences')
            ->where('id', $row->id)
            ->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return $number;
    }

    /**
     * NW-2026-000042.
     *
     * Six digits because a busy property issues a few thousand a year and a
     * number that changes width halfway through a year sorts wrongly in every
     * spreadsheet it is ever pasted into.
     */
    private function formatNumber(string $series, int $year, int $number): string
    {
        return $series.'-'.$year.'-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Which run of numbers this document belongs to.
     *
     * Ours is one series; each property is its own, because a property's
     * invoices are its own legal record and must not have our numbering
     * interleaved into them. Derived from the partner id rather than chosen,
     * which is a deliberate simplification: a property that already uses its
     * own prefix in its books will want to set one, and that is a setting to
     * add when a real partner asks — changing an existing series later means
     * renumbering, so it is worth not guessing at a format now.
     */
    private function seriesFor(Issuer $issuer, ?Partner $partner): string
    {
        if ($issuer === Issuer::NamibWay || $partner === null) {
            return 'NW';
        }

        return 'P'.$partner->id;
    }

    /**
     * Whose name goes at the top, resolved once and then frozen onto the
     * document. See the invoices migration: an invoice states who issued it on
     * the day.
     */
    private function issuerName(Issuer $issuer, ?Partner $partner): string
    {
        if ($issuer === Issuer::NamibWay || $partner === null) {
            return $issuer === Issuer::NamibWay ? 'NamibWay' : $issuer->label();
        }

        return $partner->name;
    }

    /**
     * The stay, then everything added on top, in the order a guest reads them.
     *
     * Charges come from `reservation_charges`, which is already frozen at
     * booking time — so this is a copy of a copy, and deliberately so: that
     * table can still be added to at check-out, and this document cannot.
     *
     * An included charge is written as a zero-effect line rather than left out.
     * "VAT 15% included" is exactly the sentence a guest asks about, and an
     * invoice that simply does not mention the VAT inside its own total is an
     * invoice that gets disputed.
     *
     * @return array<int, InvoiceLine>
     */
    private function linesForStay(Reservation $reservation): array
    {
        $nights = $reservation->nights();

        $lines = [new InvoiceLine(
            description: $this->stayDescription($reservation),
            amount: $reservation->stayAmount() + (float) ($reservation->discount_amount ?? 0.0),
            quantity: $nights > 0 ? $nights : null,
        )];

        // Named, not folded into the stay line. A guest who used a code wants
        // to see what it took off, and a desk explaining the bill needs both
        // numbers — the same reason the booking preview shows them separately.
        $discount = (float) ($reservation->discount_amount ?? 0.0);

        if (Money::cents($discount) > 0) {
            $lines[] = new InvoiceLine(
                description: $reservation->promotion_code === null
                    ? 'Discount'
                    : 'Discount ('.$reservation->promotion_code.')',
                amount: -$discount,
            );
        }

        foreach ($reservation->charges()->get() as $charge) {
            $lines[] = new InvoiceLine(
                description: $charge->label(),
                amount: $charge->amount,
                kind: $charge->kind,
                isIncluded: $charge->is_included,
            );
        }

        return $lines;
    }

    private function stayDescription(Reservation $reservation): string
    {
        $nights = $reservation->nights();
        $property = $reservation->listing;

        $where = $property instanceof Listing ? $property->name.' — ' : '';

        return $where
            .$reservation->check_in->isoFormat('D MMM YYYY')
            .' to '.$reservation->check_out->isoFormat('D MMM YYYY')
            .' ('.$nights.' '.($nights === 1 ? 'night' : 'nights').')';
    }

    /**
     * @return array<int, InvoiceLine>
     */
    private function readLines(Invoice $invoice): array
    {
        return array_map(
            fn (array $row): InvoiceLine => InvoiceLine::fromArray($row),
            $invoice->lineItems(),
        );
    }

    /**
     * What the document comes to. Included charges are excluded from the sum
     * because they are already inside the line above them — adding them would
     * charge the guest for the VAT twice.
     *
     * @param  array<int, InvoiceLine>  $lines
     */
    private function totalOf(array $lines): float
    {
        $cents = 0;

        foreach ($lines as $line) {
            if ($line->addsToTotal()) {
                $cents += Money::cents($line->amount);
            }
        }

        return Money::fromCents($cents);
    }

    /**
     * The revenue authority's share, named on its own so a VAT return does not
     * mean re-reading the lines. Included VAT counts here even though it does
     * not add to the total — it was still collected.
     *
     * @param  array<int, InvoiceLine>  $lines
     */
    private function taxOf(array $lines): float
    {
        $cents = 0;

        foreach ($lines as $line) {
            if ($line->isTax()) {
                $cents += Money::cents($line->amount);
            }
        }

        return Money::fromCents($cents);
    }

    /**
     * Everything that is not the revenue authority's. A levy is somebody
     * else's money too, but it is not tax and a return that treated it as tax
     * would be wrong — ChargeKind draws that line and this respects it.
     *
     * @param  array<int, InvoiceLine>  $lines
     */
    private function subtotalOf(array $lines): float
    {
        return Money::fromCents(Money::cents($this->totalOf($lines)) - Money::cents($this->taxOf($lines)));
    }

    private function partnerOf(Reservation $reservation): ?Partner
    {
        return $reservation->listing?->partner;
    }
}
