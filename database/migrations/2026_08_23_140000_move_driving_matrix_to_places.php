<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 2b: the driving matrix is between places, not cities.
 *
 * A matrix is a set of pairs, and a pair only works if both ends are the same
 * kind of thing. Once parks and reserves left `cities`, keeping the matrix
 * city-keyed would have meant a polymorphic city-or-place pair on both ends —
 * four columns, every lookup doubled, in the code that runs on every generated
 * plan. Giving every trip-relevant town a place row (step 2a) makes the pair
 * uniform instead, which is the whole reason `cities.place_id` exists.
 *
 * Existing rows are carried over through that link, so no OSRM call is
 * repeated — the matrix cost real requests to somebody else's server and is
 * exactly the kind of paid work this codebase does not throw away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_driving_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('place_a_id')->constrained('places')->cascadeOnDelete();
            $table->foreignId('place_b_id')->constrained('places')->cascadeOnDelete();
            $table->decimal('hours', 5, 2);
            $table->timestamps();

            $table->unique(['place_a_id', 'place_b_id']);
        });

        // Both ends resolved through cities.place_id. A pair whose either end
        // has no place is dropped: it is a village outside the matrix's scope,
        // and step 2a already decided those get no row.
        DB::statement('
            INSERT INTO place_driving_hours (place_a_id, place_b_id, hours, created_at, updated_at)
            SELECT LEAST(a.place_id, b.place_id), GREATEST(a.place_id, b.place_id), MIN(h.hours), NOW(), NOW()
            FROM city_driving_hours h
            JOIN cities a ON a.id = h.city_a_id
            JOIN cities b ON b.id = h.city_b_id
            WHERE a.place_id IS NOT NULL
              AND b.place_id IS NOT NULL
              AND a.place_id <> b.place_id
            GROUP BY LEAST(a.place_id, b.place_id), GREATEST(a.place_id, b.place_id)
        ');

        Schema::dropIfExists('city_driving_hours');
    }

    public function down(): void
    {
        Schema::create('city_driving_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_a_id')->constrained('cities')->cascadeOnDelete();
            $table->foreignId('city_b_id')->constrained('cities')->cascadeOnDelete();
            $table->decimal('hours', 5, 2);
            $table->timestamps();

            $table->unique(['city_a_id', 'city_b_id']);
        });

        DB::statement('
            INSERT INTO city_driving_hours (city_a_id, city_b_id, hours, created_at, updated_at)
            SELECT LEAST(a.id, b.id), GREATEST(a.id, b.id), MIN(h.hours), NOW(), NOW()
            FROM place_driving_hours h
            JOIN cities a ON a.place_id = h.place_a_id
            JOIN cities b ON b.place_id = h.place_b_id
            WHERE a.id <> b.id
            GROUP BY LEAST(a.id, b.id), GREATEST(a.id, b.id)
        ');

        Schema::dropIfExists('place_driving_hours');
    }
};
