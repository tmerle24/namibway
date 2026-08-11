<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The charges added on top of the stay, summed.
 *
 * `total_amount` already includes them — it is what the guest pays, and a
 * total that excluded a park permit the guest is about to be asked for would
 * be a lie on every screen. This column is what makes the total legible:
 * quoted − discount + charges = total, with each part named.
 *
 * Only *added* charges are counted. An included one is already inside the
 * stay price by definition, so adding it here would double it.
 *
 * Null and zero mean different things and both are used: null is a property
 * that charges nothing at all, zero is one whose charges are all already
 * inside its rates. A reader who cannot tell those apart cannot tell a lodge
 * that has not set up VAT from one that quotes inclusive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->decimal('charges_amount', 10, 2)->nullable()->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('charges_amount');
        });
    }
};
