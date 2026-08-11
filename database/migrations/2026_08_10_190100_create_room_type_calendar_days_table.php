<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ARI calendar: one row per room type per night, carrying inventory,
 * rate and stay restrictions. This is the standard shape every channel
 * manager speaks (see CLAUDE.md, "Standards"), which is what makes a future
 * connector a mapping rather than a translation.
 *
 * Two properties are load-bearing and easy to break later:
 *
 * 1. **Rows are sparse.** A night with no row means "use the room type's
 *    defaults" — `units_total` and `rate` are *overrides*, null meaning
 *    "follow RoomType". They are deliberately not copied down at booking
 *    time: a lodge that later raises total_units from 3 to 5 must see 5
 *    everywhere it never explicitly said otherwise.
 *
 * 2. **`units_sold` / `units_blocked` are counters, not derived values.**
 *    They are the availability answer, changed only by
 *    App\Services\Inventory\InventoryWriter via a conditional UPDATE, which
 *    is what makes two simultaneous bookings for the last unit resolve in
 *    the database instead of in PHP.
 *
 * A season is a range written onto these rows, not a season entity resolved
 * at read time — so pricing a stay that crosses a season boundary needs no
 * special case, it is just per-night lookup.
 *
 * There is no listing_id: it is reachable through the room type, and a copy
 * here would be a second thing that can be wrong. There is no currency
 * either — a nightly rate in a different currency from its own room type is
 * not a feature, so currency stays on RoomType.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_type_calendar_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            $table->unsignedSmallInteger('units_total')->nullable();
            $table->unsignedSmallInteger('units_sold')->default(0);
            $table->unsignedSmallInteger('units_blocked')->default(0);

            $table->decimal('rate', 10, 2)->nullable();
            $table->unsignedSmallInteger('min_stay')->nullable();
            $table->boolean('closed_to_arrival')->default(false);
            $table->boolean('closed_to_departure')->default(false);

            $table->timestamps();

            $table->unique(['room_type_id', 'date']);
        });

        // Postgres has no unsigned integers, so Laravel's unsignedSmallInteger
        // is a plain smallint here and a negative counter would be accepted.
        // The writer already refuses to release more than it holds; this is
        // the same rule stated where it cannot be bypassed. The capacity half
        // can only be checked when an explicit override exists — with a null
        // units_total the limit lives on RoomType, which a CHECK cannot reach,
        // and the conditional UPDATE carries it instead.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE room_type_calendar_days ADD CONSTRAINT room_type_calendar_days_units_non_negative CHECK (units_sold >= 0 AND units_blocked >= 0)');
            DB::statement('ALTER TABLE room_type_calendar_days ADD CONSTRAINT room_type_calendar_days_within_override CHECK (units_total IS NULL OR units_sold + units_blocked <= units_total)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('room_type_calendar_days');
    }
};
