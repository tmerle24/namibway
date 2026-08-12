<?php

use App\Enums\OperatingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking system or PMS, chosen when the account is set up.
 *
 * NamibWay is both, and which one a partner gets is a choice at setup rather
 * than a fork in the product — see PAYMENTS.md § 2b.
 *
 * The default is `full` and not `booking_only`, deliberately: every partner
 * that exists today already has the arrivals board and the check-in buttons,
 * and a migration that silently took a working screen away from them would be
 * a change of product delivered as a schema change. New accounts are given a
 * mode when they are created; existing ones keep what they have.
 *
 * What this column may *not* do is change what is stored. It is read by the
 * partner panel's navigation and by nothing in `app/Services`, and
 * `OperatingModeTest` asserts both halves — including the one that matters
 * most, that a booking-only property still has a folio, payments and an
 * invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('operating_mode')->default(OperatingMode::Full->value)->after('booking_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('operating_mode');
        });
    }
};
