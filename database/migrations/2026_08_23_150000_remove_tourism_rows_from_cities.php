<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 3, the last: the tourism rows leave `cities`.
 *
 * They were copied rather than moved in step 1 so that the readers could be
 * repointed (step 2) without a window in which something looked for a park in
 * a table that no longer had it. Nothing reads them now — the driving matrix
 * is place-to-place, Kaia resolves day locations against `places`, and every
 * listing that was filed in one carries `place_id`.
 *
 * `listings.city_id` and `attractions.city_id` are both `nullOnDelete`, so a
 * listing filed in a park loses its address here and keeps its place. That is
 * the correct outcome and not a loss: "Etosha National Park" was never an
 * address, and the row that claimed it was is exactly what this series exists
 * to undo. A real postal town can be entered later; nothing needs it in the
 * meantime, because nothing routes on the address.
 */
return new class extends Migration
{
    private const TOURISM_TYPES = ['national_park', 'nature_reserve', 'landmark'];

    public function up(): void
    {
        // Sever the transition link first: it points at rows about to go, and
        // its nullOnDelete would otherwise blank it a moment before the column
        // is dropped anyway.
        Schema::table('places', function (Blueprint $table) {
            $table->dropConstrainedForeignId('legacy_city_id');
        });

        DB::table('cities')->whereIn('type', self::TOURISM_TYPES)->delete();
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->foreignId('legacy_city_id')->nullable()->constrained('cities')->nullOnDelete();
        });

        // Rebuild the city rows from the places they became. Coordinates and
        // the area come back with them; population and area_km2 do not, and
        // never meant anything on a park in the first place.
        $places = DB::table('places')->whereIn('type', self::TOURISM_TYPES)->get();
        $now = now();

        foreach ($places as $place) {
            $cityId = DB::table('cities')->insertGetId([
                'name' => json_decode((string) $place->name, true, 512, JSON_THROW_ON_ERROR)['en'] ?? $place->slug,
                'slug' => $place->slug,
                'type' => $place->type,
                'region_id' => $place->region_id,
                'destination_id' => $place->destination_id,
                'place_id' => $place->id,
                'image' => $place->image,
                'lat' => $place->lat,
                'lng' => $place->lng,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('places')->where('id', $place->id)->update(['legacy_city_id' => $cityId]);
        }
    }
};
