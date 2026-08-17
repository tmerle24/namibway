<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `base_rate` is the ARI fallback: the rate that applies on any night where
 * neither the rate-plan calendar nor a calendar-day override says anything
 * different. "Per night" was always an inaccuracy — the same column has always
 * held the per-seat price for a departure and will hold the day rate for a
 * vehicle — so it is gone.
 *
 * The rate plan always wins when it has something to say; `base_rate` is never
 * the answer on a property that has entered its seasons. The name reflects the
 * role, not the frequency.
 *
 * See BOOKING_BEYOND_ROOMS.md §7.2 for the ARI deviation note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookable_units', function (Blueprint $table): void {
            $table->renameColumn('rate_per_night', 'base_rate');
        });
    }

    public function down(): void
    {
        Schema::table('bookable_units', function (Blueprint $table): void {
            $table->renameColumn('base_rate', 'rate_per_night');
        });
    }
};
