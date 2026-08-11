<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What somebody travelling alone pays on top, for the per-person-sharing
 * strategy.
 *
 * Two columns because the market states it both ways: a flat amount per night
 * ("single supplement N$ 450") and a percentage of the per-person rate ("+50%
 * single"). Converting one into the other at entry time would need the season
 * to be known, and a percentage entered against low season would be wrong all
 * summer. So both are stored and the amount wins where somebody has set both —
 * see PerPersonSharingPricer, which is the only reader.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->decimal('single_supplement_amount', 10, 2)->nullable()->after('cancellation_days');
            $table->decimal('single_supplement_percent', 5, 2)->nullable()->after('single_supplement_amount');
        });
    }

    public function down(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn(['single_supplement_amount', 'single_supplement_percent']);
        });
    }
};
