<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invoice is a legal document, not a PDF export.
 *
 * That sentence is the whole design. Everything below follows from taking it
 * literally rather than treating an invoice as a rendering of a reservation:
 *
 * ## It is frozen at the moment it is issued
 *
 * `lines` holds the document as it was raised — every line, its description,
 * its amount, the tax and levy named separately. Not a view over
 * `reservation_nights` and `reservation_charges`, because those are allowed to
 * change afterwards and an issued invoice is not. A rate plan renamed in March
 * must not alter what February's invoice says, and a VAT rate that goes to 16 %
 * must not silently restate last year's tax.
 *
 * The same rule the price already follows (BOOKING_SYSTEM.md, "stored as a
 * result"), applied one level up: the invoice is a result of the stay, and the
 * stay is a result of the calendar.
 *
 * ## It is never edited
 *
 * There is no correction workflow that touches an issued row. A mistake is a
 * **credit note** — its own invoice, with its own number, pointing at the one
 * it corrects through `corrects_invoice_id` — and the pair nets to the right
 * amount. The Invoice model refuses updates outright, so this is not a
 * convention somebody can forget.
 *
 * ## Who issues it is a property of the document
 *
 * Under the agency and split models the property invoices the guest; under the
 * merchant model we do; and our commission is a separate document addressed to
 * the partner. So the issuer is a column, not an assumption — see PAYMENTS.md
 * §4. The `bill_to_*` columns are frozen for the same reason as the
 * reservation's `guest_*` strings: an invoice says who it was addressed to on
 * the day, and a customer who later corrects their name does not rewrite it.
 *
 * ## No stored PDF
 *
 * Deliberately no `pdf_path`. The frozen `lines` snapshot *is* the document;
 * the PDF is a derivative rendered from it on demand, exactly as thumbnails are
 * derivatives of an original (CLAUDE.md, media storage). Two reasons beyond
 * tidiness: an invoice carries a named guest and what they paid, and our media
 * bucket is public with no per-object visibility — the same trap the Documents
 * store was built to avoid. And a stored file is a second copy that can drift
 * from the row, which is the one thing an immutable document must not do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // The number as a human reads it: NW-2026-000042. Stored whole
            // because it is quoted in emails and bank references, and taking it
            // apart to display it would mean the format lives in two places.
            $table->string('number')->unique();

            // The same number as the machine counts it. Kept alongside so
            // "is this series gapless?" is a query rather than string parsing.
            $table->string('series', 16);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence_number');

            $table->timestamp('issued_at');
            $table->string('issuer');

            // Whose name is at the top, frozen. Not read back off the partner
            // row: an invoice states who issued it on the day, and a property
            // that renames itself next season does not get to restate last
            // season's paperwork. Same rule as bill_to_name below, and the
            // same rule the reservation's guest_* strings already follow.
            $table->string('issuer_name');

            $table->string('kind');

            // A stay invoice has a reservation; a commission invoice has a
            // partner and no reservation. Both nullable, neither optional in
            // practice — InvoiceIssuer refuses a document that has neither.
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();

            // A credit note points at what it corrects. Null for everything
            // else, and unique so one invoice cannot be credited twice — the
            // same discipline as reservations.inquiry_id, and for the same
            // reason: the constraint is the mechanism, not a PHP check.
            $table->foreignId('corrects_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->string('currency', 3);

            // Signed, so a credit note is the negative of what it corrects and
            // the pair sums to the corrected amount without a special case —
            // the same shape a refund has in `payments`.
            $table->decimal('subtotal', 12, 2);
            $table->decimal('tax_amount', 12, 2);
            $table->decimal('total', 12, 2);

            // The document itself. jsonb rather than json: Postgres is the
            // production database, and a column nobody can query is a column
            // that gets copied into another one later.
            $table->jsonb('lines');

            $table->string('bill_to_name');
            $table->string('bill_to_email')->nullable();
            $table->text('bill_to_address')->nullable();

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Gaplessness is a property of (series, year) and this is what
            // makes a duplicate impossible even if the locking were wrong.
            $table->unique(['series', 'year', 'sequence_number']);
            $table->unique('corrects_invoice_id');

            $table->index(['reservation_id', 'issued_at']);
            $table->index(['partner_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
