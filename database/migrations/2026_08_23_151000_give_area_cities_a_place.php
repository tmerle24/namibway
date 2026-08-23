<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A city that belongs to a tourism area is somewhere travelers go, whether or
 * not we happen to have a listing there yet.
 *
 * Step 2a decided which cities earn a place from the old driving-matrix rule:
 * the larger settlement types, plus anywhere already hosting a published
 * listing. That rule has a hole on a fresh database and on a thin catalog —
 * Sesriem, Solitaire and Ai-Ais are officially settlements with no listing of
 * their own, and they are exactly where a Sossusvlei or Fish River traveler
 * sleeps. Before the split they were kept in the matrix by their listings; a
 * catalog that has not been filled in yet would have dropped them.
 *
 * Belonging to an area is the stronger signal and does not depend on the
 * catalog: somebody curated that city into Sossusvlei because that is what it
 * is for. So a city with a `destination_id` gets a place too.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $cities = DB::table('cities')
            ->whereNull('place_id')
            ->whereNotNull('destination_id')
            ->get();

        foreach ($cities as $city) {
            $placeId = DB::table('places')->insertGetId([
                'name' => json_encode(['en' => $city->name], JSON_THROW_ON_ERROR),
                'slug' => $city->slug,
                'type' => 'town',
                'region_id' => $city->region_id,
                'destination_id' => $city->destination_id,
                'city_id' => $city->id,
                'image' => $city->image,
                'lat' => $city->lat,
                'lng' => $city->lng,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('cities')->where('id', $city->id)->update(['place_id' => $placeId]);
        }

        DB::statement('
            UPDATE listings SET place_id = cities.place_id
            FROM cities
            WHERE cities.id = listings.city_id
              AND listings.place_id IS NULL
              AND cities.place_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        // Nothing: which cities have a place is not worth unwinding, and the
        // step-2a migration's own down() clears the column wholesale.
    }
};
