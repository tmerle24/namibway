<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rate and restrictions per rate plan, per room type, per night.
 *
 * ## Why this is a separate table from room_type_calendar_days
 *
 * **A room is sold once, however many rate plans it is offered under.** So
 * inventory — units_total, units_sold, units_blocked — stays on
 * room_type_calendar_days, keyed by room type alone, and the conditional
 * UPDATE that resolves two people racing for the last room is untouched.
 * Rates and restrictions differ per plan and move here.
 *
 * Getting that split wrong is expensive in both directions: counters per rate
 * plan would oversell the property, and a rate shared across plans would make
 * rate plans pointless.
 *
 * Sparse, like the calendar it sits beside: a night with no row, or a null
 * override, means "follow the room type's own rate". The same rule, in the
 * same place — see App\Services\Inventory\DTOs\CalendarSnapshot.
 *
 * Restrictions live here rather than with inventory because they are
 * commercial, not physical: a non-refundable plan may want a three-night
 * minimum on a weekend the flexible plan sells one night of.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_plan_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            $table->decimal('rate', 10, 2)->nullable();
            $table->unsignedSmallInteger('min_stay')->nullable();
            $table->boolean('closed_to_arrival')->default(false);
            $table->boolean('closed_to_departure')->default(false);

            $table->timestamps();

            $table->unique(['rate_plan_id', 'room_type_id', 'date']);

            // How the grid reads it: one plan, many room types, a date range.
            $table->index(['rate_plan_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_plan_days');
    }
};
