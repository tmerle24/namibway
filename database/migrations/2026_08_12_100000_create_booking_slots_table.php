<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A departure: a time of day a bookable unit runs, with a length.
 *
 * ## Why a slot is a row and not a number
 *
 * A quad tour at 09:00 and one at 14:00 are two separate pools of seats on the
 * same day, so the calendar needs something to key them by. An index —
 * `slot_1`, `slot_2` — would be enough for that and useless for everything
 * else: a column on a day view has to know where on the hour axis it sits and
 * how tall it is, and "09:00 for three hours" beside "12:00 for three hours"
 * has to be expressible without anything guessing.
 *
 * ## What this is deliberately not
 *
 * **Not a booking's time.** This is the timetable the operator publishes, one
 * row per departure they run, reused every day it runs. A stay on a particular
 * date references it; it does not copy it.
 *
 * **Not a 15-minute grid.** The resolution a property draws its day at is a
 * property of the *screen*, configurable per operator, and it never reaches a
 * table that counts anything. One departure is one row with one counter, moved
 * by the same conditional UPDATE that resolves two people racing for the last
 * room. Ninety-six rows a day would replace that counter with an overlap query
 * nobody can reason about under concurrency — see BOOKING_SYSTEM.md, "Time
 * inside a day", and BOOKING_BEYOND_ROOMS.md §4, which forbids replacing it.
 *
 * **Not for accommodation.** A property selling by the night has no slots, and
 * every row it already has stays exactly as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_slots', function (Blueprint $table) {
            $table->id();

            // The sellable unit this departure belongs to. Still called
            // room_types today; renaming it to something vertical-neutral is
            // its own step (BOOKING_BEYOND_ROOMS.md §3.2).
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();

            // "Morning departure", "Sunset drive". Optional: a property with
            // one departure a day has nothing to call it that the time does
            // not already say.
            $table->string('label')->nullable();

            $table->time('starts_at');

            // How long it runs, which is what makes the column a height rather
            // than a line. Minutes rather than an end time: a departure that
            // ends after midnight is a duration, not a second date.
            $table->unsignedSmallInteger('duration_minutes');

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);

            $table->timestamps();

            // One departure per unit per start time. Two at 09:00 is a
            // capacity question — units_total on the day row — not a second
            // timetable entry.
            $table->unique(['room_type_id', 'starts_at']);

            $table->index(['room_type_id', 'is_active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_slots');
    }
};
