<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What this room was sold as, frozen onto the stay.
 *
 * The board basis is already on the rate plan, and a stay already names its
 * plan — so this looks like duplication until you ask what happens next. A
 * lodge renames "Standard rate" to "B&B" in March and changes its board basis
 * in June; `reservation_units.rate_plan_id` is `nullOnDelete`, so a plan that
 * is removed takes its board with it. Either way a stay sold as DBB would
 * later read as something else, and a guest who booked dinner would arrive to
 * find nobody expecting them.
 *
 * So this is rule 4 — the price is frozen at booking — applied to the other
 * half of what was sold. `rate_plan_id` stays as the link to the live product;
 * these two are the record of what the product *was* on the day.
 *
 * Null means the plan did not say, which is a real answer and not a gap: a
 * property that never states board sells rooms, and claiming breakfast on its
 * behalf would be an invention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_units', function (Blueprint $table) {
            $table->string('board_basis', 30)->nullable()->after('rate_plan_id');
            $table->string('rate_plan_name')->nullable()->after('board_basis');
        });
    }

    public function down(): void
    {
        Schema::table('reservation_units', function (Blueprint $table) {
            $table->dropColumn(['board_basis', 'rate_plan_name']);
        });
    }
};
