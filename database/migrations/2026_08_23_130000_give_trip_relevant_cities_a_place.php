<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Step 2a of separating the address from the tourism location.
 *
 * Everything Kaia routes on has to be a place, otherwise the driving matrix
 * and every day location become a polymorphic "city or place" pair and the
 * cost of the split lands in the hottest code in the product. So the towns a
 * traveler actually plans around get a place row of their own, and
 * `cities.place_id` links the two identities: the city is Windhoek's address,
 * the place is Windhoek as a stop on a trip.
 *
 * Which cities qualify is exactly the driving matrix's own rule
 * (the pre-2026-08-23 BackfillCityDrivingHours::inScope): a type that plausibly hosts a listing,
 * plus — regardless of type — anywhere that actually hosts a published one.
 * That second half is what keeps Sesriem, Solitaire, Palmwag and Aus in; they
 * are officially settlements and are where a Sossusvlei or Namib traveler
 * sleeps. The ~65 remaining village rows get no place and never needed one.
 *
 * The tourism cities copied in step 1 are linked to their existing copy rather
 * than given a second one — `legacy_city_id` is what says which is which.
 */
return new class extends Migration
{
    /** Settlement types large enough to plausibly host a listing. */
    private const IN_SCOPE_TYPES = ['municipality', 'town', 'community'];

    private const TOURISM_TYPES = ['national_park', 'nature_reserve', 'landmark'];

    public function up(): void
    {
        // The tourism rows already have their place from step 1.
        DB::statement('
            UPDATE cities SET place_id = places.id
            FROM places
            WHERE places.legacy_city_id = cities.id
        ');

        $now = now();

        $cities = DB::table('cities')
            ->whereNull('place_id')
            ->whereNotIn('type', self::TOURISM_TYPES)
            ->where(fn ($query) => $query
                ->whereIn('type', self::IN_SCOPE_TYPES)
                ->orWhereExists(fn ($exists) => $exists
                    ->from('listings')
                    ->whereColumn('listings.city_id', 'cities.id')
                    ->where('listings.is_published', true)))
            ->get();

        foreach ($cities as $city) {
            // A town's place carries the same name and coordinates; it is the
            // same dot on the map seen as a stop rather than as an address.
            // The slug is suffixed because `places` and `cities` are different
            // tables with their own unique slugs and a reader landing on
            // /places/windhoek should not wonder which of the two it got.
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
                'legacy_city_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('cities')->where('id', $city->id)->update(['place_id' => $placeId]);
        }

        // A listing with no place of its own takes its address city's place.
        // An explicit place_id — set in step 1 for the listings filed in a
        // tourism "city" — always wins and is left alone.
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
        DB::table('cities')->update(['place_id' => null]);
        DB::table('listings')->update(['place_id' => null]);
        DB::table('places')->where('type', 'town')->delete();
    }
};
