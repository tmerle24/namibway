<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The calendar gains a departure, beside the date rather than instead of it.
 *
 * A row is now keyed `(unit, date, slot)`, with **slot null for everything
 * sold by the day** — which is every row that exists today and every row a
 * lodge will ever write. The counters, the conditional UPDATE that moves them,
 * and every query that reads a night are untouched: a nullable column that
 * accommodation never sets costs it nothing.
 *
 * ## The uniqueness rule, and why it is two indexes
 *
 * "One row per unit per night" has to keep holding for accommodation, and
 * "one row per unit per date per departure" has to start holding for tours.
 * A single unique index over the three columns would give neither: SQL treats
 * NULLs as distinct, so a lodge could end up with two rows for the same night
 * and two counters for the same rooms — the exact failure the counter exists
 * to prevent, arriving silently.
 *
 * Postgres 15 could express it as UNIQUE NULLS NOT DISTINCT, but two partial
 * indexes say the same thing in a way SQLite understands as well, and they
 * read as the two rules they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_type_calendar_days', function (Blueprint $table) {
            $table->foreignId('slot_id')->nullable()->after('date')
                ->constrained('booking_slots')->cascadeOnDelete();
        });

        Schema::table('room_type_calendar_days', function (Blueprint $table) {
            $table->dropUnique(['room_type_id', 'date']);
        });

        // Written as SQL because a partial index is not something the schema
        // builder expresses, and both Postgres and SQLite take this syntax
        // unchanged.

        // Sold by the day: one row per unit per date, as before.
        DB::statement(
            'CREATE UNIQUE INDEX calendar_days_unique_by_day ON room_type_calendar_days '
            .'(room_type_id, date) WHERE slot_id IS NULL'
        );

        // Sold by departure: one row per unit per date per departure.
        DB::statement(
            'CREATE UNIQUE INDEX calendar_days_unique_by_slot ON room_type_calendar_days '
            .'(room_type_id, date, slot_id) WHERE slot_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS calendar_days_unique_by_day');
        DB::statement('DROP INDEX IF EXISTS calendar_days_unique_by_slot');

        Schema::table('room_type_calendar_days', function (Blueprint $table) {
            $table->dropConstrainedForeignId('slot_id');
            $table->unique(['room_type_id', 'date']);
        });
    }
};
