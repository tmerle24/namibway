<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of the two things a restaurant sells it is willing to be asked for
 * online.
 *
 * Both were derived until now, and both derivations were wrong in the same
 * way — they conflated *having* something with *selling it over the internet*:
 *
 * - a table was offered by every restaurant that accepted inquiries at all,
 *   with no way to say "walk-ins only, call us";
 * - and entering a menu silently switched ordering on, so a restaurant that
 *   wanted its card *shown* was made to take orders it may not be set up to
 *   fulfil.
 *
 * A menu has two jobs — telling somebody what there is, and being orderable —
 * and only the second one is a commercial commitment. These columns separate
 * them.
 *
 * Both default to true, which is exactly what the derived behaviour did for a
 * restaurant with a menu, so nothing that is live changes on the day this runs.
 * `accepts_inquiries` still governs everything above these: switched off, the
 * property is asked for nothing at all, whatever these say.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->boolean('accepts_table_reservations')->default(true)->after('accepts_inquiries');
            $table->boolean('accepts_orders')->default(true)->after('accepts_table_reservations');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['accepts_table_reservations', 'accepts_orders']);
        });
    }
};
