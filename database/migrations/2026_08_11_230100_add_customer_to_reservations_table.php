<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which customer a stay belongs to.
 *
 * The frozen `guest_name` / `guest_email` / `guest_phone` columns stay exactly
 * where they are, and they are not a duplicate: they are what was typed when
 * this booking was made, and they must not change when somebody later corrects
 * a customer's surname. The link says whose stay it is; the strings say what
 * the booking said.
 *
 * Nullable, because a reservation created before this migration has no
 * customer and inventing one for it would be a guess. Everything written after
 * it has one — InventoryWriter resolves it on every booking, so the customer
 * view has no holes.
 *
 * `nullOnDelete` rather than cascade: deleting a customer must never delete
 * the stays they paid for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('listing_id')
                ->constrained()->nullOnDelete();

            // "Everything this person has stayed", which is the first thing
            // anybody opens a customer record to see.
            $table->index(['customer_id', 'check_in']);
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropIndex(['customer_id', 'check_in']);
            $table->dropColumn('customer_id');
        });
    }
};
