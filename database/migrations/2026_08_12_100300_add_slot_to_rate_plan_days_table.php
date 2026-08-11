<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A price per departure.
 *
 * A sunset drive costing more than the morning one is the ordinary case, and
 * until now the rate was keyed by (plan, unit, date) alone — so both departures
 * on a Tuesday had to cost the same. The slot goes here for the same reason it
 * went on the calendar day: it is another axis the price varies along, not a
 * different kind of price.
 *
 * **Null still means the whole day**, and that is what makes this safe for the
 * properties that pay for all of this. Every rate a lodge has ever entered is a
 * null-slot row, every rate it will enter stays one, and the lookup falls back
 * to it: a departure with no rate of its own is priced at the day's rate, which
 * is priced at the unit's rate. Three steps, each one already there.
 *
 * The uniqueness rule is the calendar's, for the same reason — SQL treats NULLs
 * as distinct, so one index over four columns would let a plan hold two rates
 * for the same night and no way to say which applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_plan_days', function (Blueprint $table) {
            $table->foreignId('slot_id')->nullable()->after('room_type_id')
                ->constrained('booking_slots')->cascadeOnDelete();
        });

        Schema::table('rate_plan_days', function (Blueprint $table) {
            $table->dropUnique(['rate_plan_id', 'room_type_id', 'date']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX rate_plan_days_unique_by_day ON rate_plan_days '
            .'(rate_plan_id, room_type_id, date) WHERE slot_id IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX rate_plan_days_unique_by_slot ON rate_plan_days '
            .'(rate_plan_id, room_type_id, date, slot_id) WHERE slot_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS rate_plan_days_unique_by_day');
        DB::statement('DROP INDEX IF EXISTS rate_plan_days_unique_by_slot');

        Schema::table('rate_plan_days', function (Blueprint $table) {
            $table->dropConstrainedForeignId('slot_id');
            $table->unique(['rate_plan_id', 'room_type_id', 'date']);
        });
    }
};
