<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What this stay was actually charged, frozen at the moment it was booked.
 *
 * Rule 4 of the five (BOOKING_SYSTEM.md §2) covers a charge as much as a rate:
 * the price is a **result**, not something recomputed on read. A VAT rate that
 * changes in March must not alter what February's invoices say, and a charge
 * the property later deletes must still be readable on the stays that paid it.
 *
 * So everything needed to reprint the invoice is copied here — the name, the
 * kind, how it was worked out and from what — and `charge_id` is a link for
 * reporting rather than the source of any number on this row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('kind');
            $table->string('basis');
            $table->decimal('rate', 10, 4);

            // What it came to, and what it was worked out from. Both, because
            // "15% of what?" is the first question anybody asks of an invoice
            // line, and recomputing the base later would need the whole
            // pricing chain back.
            $table->decimal('base_amount', 10, 2);
            $table->decimal('amount', 10, 2);

            // An included charge is part of the total rather than added to it.
            // Kept as a column so a reader never has to infer it from whether
            // the numbers happen to add up.
            $table->boolean('is_included')->default(false);

            $table->string('currency', 3);
            $table->unsignedSmallInteger('sort')->default(0);

            $table->timestamps();

            $table->index(['reservation_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_charges');
    }
};
