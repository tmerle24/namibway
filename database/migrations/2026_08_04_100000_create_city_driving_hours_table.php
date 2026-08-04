<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real city-to-city driving durations, computed by OSRM (see
 * App\Services\Routing\OsrmDrivingTimeService and the
 * namibway:backfill-city-driving-hours command) — the city-level counterpart
 * to ItineraryService::DRIVING_HOURS, which stays region-level. Pairs are
 * stored once with city_a_id < city_b_id (the backfill command enforces this
 * on write), mirroring that constant's alphabetical-key convention so lookup
 * doesn't care about argument order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_driving_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_a_id')->constrained('cities')->cascadeOnDelete();
            $table->foreignId('city_b_id')->constrained('cities')->cascadeOnDelete();
            $table->decimal('hours', 5, 2);
            $table->timestamps();

            $table->unique(['city_a_id', 'city_b_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_driving_hours');
    }
};
