<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What has been paid against a stay, stored as a result rather than summed on
 * every read.
 *
 * Same rule as the price, and for the same reason: the arrivals board, the
 * unpaid list and the stay drawer must not each recompute the balance and
 * disagree about it. PaymentRecorder rewrites both columns on every write and
 * is the only thing that may.
 *
 * `paid_amount` counts `recorded` and `cleared` payments and excludes `failed`
 * ones, reversals included — a reversal is a negative row, so the sum handles
 * it without a special case.
 *
 * Not nullable, and defaulting to zero: "nothing has been paid" is a fact
 * about every stay from the moment it exists, so there is no state in which
 * the answer is unknown. That is different from `total_amount`, which is null
 * for a stay nobody has priced yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->decimal('paid_amount', 12, 2)->default(0)->after('charges_amount');
            $table->string('payment_status')->default('unpaid')->after('paid_amount');
        });

        Schema::table('reservations', function (Blueprint $table) {
            // The unpaid list is "this property, still owing, arriving soon",
            // and it is opened every morning.
            $table->index(['listing_id', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex(['listing_id', 'payment_status']);
            $table->dropColumn(['paid_amount', 'payment_status']);
        });
    }
};
