<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What we earned on this stay, and what was asked of the guest up front —
 * written down when the booking is taken, never recomputed.
 *
 * The rule this follows is already in force twice in this schema: the price is
 * stored as a result, and the taxes are frozen onto `reservation_charges`. The
 * reason is the same each time and it is worth stating plainly: changing a
 * platform rate next season must not silently rewrite what we earned last
 * season. A commission recomputed from today's setting is not a record of
 * anything — it is a report that quietly changes what it says about the past.
 *
 * Four columns rather than two, because a rate without its amount is only half
 * the answer. "5 % of what?" is the first question anybody asks, and the base
 * a commission was taken on is the stay before tax and levy (PAYMENTS.md § 3)
 * — a number nobody can reconstruct later once the charges have moved.
 *
 * Nullable throughout: every stay taken before this existed has no answer, and
 * a zero would be a lie about a booking we earned nothing recorded on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->decimal('commission_rate', 6, 3)->nullable()->after('payment_status');

            // The base it was worked out from: the stay, before tax and levy.
            // Charging commission on the government's VAT and the NTB's levy
            // is indefensible in front of an operator, and this column is what
            // lets a partner check that we did not.
            $table->decimal('commission_base', 12, 2)->nullable()->after('commission_rate');
            $table->decimal('commission_amount', 12, 2)->nullable()->after('commission_base');

            $table->decimal('deposit_rate', 6, 3)->nullable()->after('commission_amount');

            // The deposit is a share of the whole folio, tax included — it is
            // a commitment signal and a cash-flow arrangement, not a share of
            // revenue. Different base from the commission on purpose.
            $table->decimal('deposit_amount', 12, 2)->nullable()->after('deposit_rate');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn([
                'commission_rate',
                'commission_base',
                'commission_amount',
                'deposit_rate',
                'deposit_amount',
            ]);
        });
    }
};
