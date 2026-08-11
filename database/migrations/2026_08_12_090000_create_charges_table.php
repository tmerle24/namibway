<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a property adds to a price: VAT, the tourism levy, a conservancy fee.
 *
 * ## Why this is not part of a rate plan
 *
 * A rate plan is a product — what the guest buys and what it includes. A tax
 * is not a product, and a product that knows "15% VAT plus 2% levy" is a
 * product that has to be edited when a rate changes. So charges sit where
 * promotions sit: they apply *to* a finished price and never reach back into
 * how it was reached.
 *
 *     rate → price → discount → charges → total
 *
 * ## Included or added, per charge rather than per property
 *
 * BOOKING_SYSTEM.md reserved this decision as "per property, because Southern
 * African lodges quote both ways". Building it showed that per property is not
 * expressive enough: the same lodge normally quotes a rate with VAT already in
 * it *and* adds a park permit on top. So `is_included` is a question about the
 * charge, which subsumes the property-level statement — a property whose rates
 * include everything simply marks everything included.
 *
 * An included charge does not change what the guest pays. It is extracted from
 * the price so the invoice can show it, which is exactly what a VAT-inclusive
 * rate means.
 *
 * ## Dates
 *
 * A charge carries its own window because rates change by law on a date, and
 * because the amount is frozen onto the reservation: raising VAT next March
 * must leave every stay booked before it exactly as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('kind');
            $table->string('basis');
            $table->string('base')->default('stay_only');

            // A percentage (15.00 means 15%) or an amount in the property's
            // own currency, depending on `basis`. One column, because it is
            // one number on the screen and the basis says what it means.
            $table->decimal('rate', 10, 4);

            // Whether the rates the lodge enters already contain this.
            $table->boolean('is_included')->default(false);

            // Per-person charges: whether children are counted. A park permit
            // usually charges them less or not at all, and "which guests" is a
            // cheaper question than a second charge per age band.
            $table->boolean('charges_children')->default(true);

            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();

            $table->boolean('is_active')->default(true);

            // The order they appear on an invoice, and the order
            // ChargeBase::StayPlusPreceding reads: a levy before the VAT that
            // is charged on top of it.
            $table->unsignedSmallInteger('sort')->default(0);

            $table->timestamps();

            $table->index(['listing_id', 'is_active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charges');
    }
};
