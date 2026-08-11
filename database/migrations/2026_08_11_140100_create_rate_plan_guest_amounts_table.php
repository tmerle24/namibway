<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a room costs for one guest, for two, for three — per night.
 *
 * This is OTA's `BaseByGuestAmt`, which is the whole reason it carries a date:
 * in the standard the amounts hang off the rate for a date, not off the rate
 * plan as a whole, and they have to, because 1000 / 1300 / 1500 in low season
 * is 1400 / 1800 / 2100 in high season. Putting the guest counts anywhere else
 * would need a second season mechanism next to the one the calendar already
 * has.
 *
 * Sparse, like everything else in this calendar: a night with no rows at all
 * falls back to the rate plan's own rate for the night. What is *not* guessed
 * is a missing guest count — a night priced for one and two guests says
 * nothing about four, so a booking for four is refused with that sentence
 * rather than quietly sold at the two-guest price.
 *
 * Only the per-occupancy strategy reads this. Per-unit ignores it, and
 * per-person-sharing computes from the nightly rate and the guest categories.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_plan_guest_amounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('guests');
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->unique(['rate_plan_id', 'room_type_id', 'date', 'guests'], 'rate_plan_guest_amounts_night_unique');

            // How a stay reads it: one plan and one room type across a range.
            $table->index(['rate_plan_id', 'room_type_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_plan_guest_amounts');
    }
};
