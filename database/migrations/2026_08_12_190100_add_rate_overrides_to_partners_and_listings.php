<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a partner or a single property departs from the platform's rates.
 *
 * Both nullable, and **null means inherit** — not zero, and not "unset so use
 * a default somewhere else". That is the same rule the availability calendar
 * already uses for its sparse overrides (`CalendarSnapshot`), chosen again for
 * the same reasons: nothing has to be written to say "unchanged", the common
 * case lives in one place, and changing the platform rate moves everyone who
 * has not negotiated separately.
 *
 * Both levels exist because both cases are real. A partner with twenty camps
 * wants one number; the one lodge in that group we negotiated separately needs
 * its own. Listing-level is the exception, not the workflow.
 *
 * Zero is a meaningful value on `deposit_rate` and is deliberately reachable
 * only through the platform's own gate — see slice 4's `allow_zero_deposit`.
 * A property that collects the whole balance itself and lets us invoice for
 * commission is a real arrangement; it is just not one a partner may choose
 * unilaterally, because it moves collection risk onto us.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->decimal('commission_rate', 6, 3)->nullable()->after('booking_enabled');
            $table->decimal('deposit_rate', 6, 3)->nullable()->after('commission_rate');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->decimal('commission_rate', 6, 3)->nullable();
            $table->decimal('deposit_rate', 6, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['commission_rate', 'deposit_rate']);
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['commission_rate', 'deposit_rate']);
        });
    }
};
