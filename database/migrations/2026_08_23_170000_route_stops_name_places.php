<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A route stop names a place, not a region.
 *
 * The classic routes were seeded with region names — "Kunene", "Erongo",
 * "Hardap" — because when they were written a region was the finest thing a
 * plan could point at. Kunene is 115,000 km² and reaches from the Skeleton
 * Coast to the Angolan border, so "visit Kunene" leaves the model free to pick
 * any town in it, and the plan zigzags: a real generated trip ran Windhoek →
 * Otjiwarongo → the coast → Etosha → Khorixas with three legs over eight
 * hours, which is not a route anybody would drive.
 *
 * Places exist now and are exactly the right granularity — Sossusvlei,
 * Swakopmund, Twyfelfontein, Etosha National Park are what every published
 * Namibia itinerary is written in. So a stop gets a `place_id`, and the four
 * self-drive route guides the seed below is built from all describe the same
 * anticlockwise loop:
 *
 *   Windhoek → Kalahari → (south: Quiver Tree Forest, Fish River Canyon,
 *   Lüderitz) → Sossusvlei → Swakopmund → Erongo/Cape Cross → Twyfelfontein →
 *   Etosha (west to east) → Waterberg/Okonjima → Windhoek
 *
 * `region` stays and stays populated: it is what the model is told a leg
 * covers when the stop's place is one we do not hold a row for, and dropping a
 * column that three other things read would be a second change wearing the
 * first one's clothes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_template_stops', function (Blueprint $table) {
            $table->foreignId('place_id')->nullable()->after('route_template_id')->constrained()->nullOnDelete();
        });

        // Existing stops keep working: a region name that happens to match a
        // place gets linked, the rest stay region-only until reseeded.
        DB::statement("
            UPDATE route_template_stops SET place_id = places.id
            FROM places
            WHERE lower(places.name->>'en') = lower(route_template_stops.region)
        ");
    }

    public function down(): void
    {
        Schema::table('route_template_stops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('place_id');
        });
    }
};
