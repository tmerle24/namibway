<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The places a Namibian lodge actually stands in when it stands nowhere near
 * a town: national parks, private reserves, and the landmarks people plan a
 * trip around.
 *
 * `cities` was seeded as an administrative gazetteer — the ~105 proclaimed
 * municipalities, towns and settlements — which left no way to file Onguma,
 * the Etosha camps, a Sossusvlei lodge or a Damaraland camp. The workaround
 * was the nearest town (the demo seed still files Etosha lodges under Outjo,
 * ~100 km away), and that workaround is not private: `city` is the trip
 * plan's day location, the point Kaia measures driving hours between, and
 * the Explore filter, so a lodge filed in the wrong town is shown, routed
 * and searched in the wrong town. Without any city it is worse — such a
 * listing never enters a trip plan at all.
 *
 * Coordinates are deliberately left null: namibway:backfill-city-coordinates
 * geocodes them from the name, the same way every other row in this table got
 * its lat/lng, rather than having them typed in from memory here. Run it
 * after this migration (and then namibway:backfill-city-driving-hours, which
 * refuses to run while an in-scope place has no coordinates).
 *
 * deploy.sh only runs `migrate --force`, never `db:seed` — see
 * 2026_08_02_180300_create_cities_table.php for why this data is seeded in a
 * migration rather than solely in CitySeeder (which carries the same rows for
 * a local `migrate:fresh --seed`).
 */
return new class extends Migration
{
    public function up(): void
    {
        $regionIds = DB::table('regions')->pluck('id', 'name');
        $existing = DB::table('cities')->pluck('slug')->flip();
        $now = now();

        $rows = [];

        foreach (self::places() as [$name, $region, $type]) {
            $slug = Str::slug($name);

            // Idempotent: a place someone already added by hand in /admin keeps
            // whatever they entered rather than being duplicated or reset.
            if ($existing->has($slug) || ! isset($regionIds[$region])) {
                continue;
            }

            $rows[] = [
                'region_id' => $regionIds[$region],
                'name' => $name,
                'slug' => $slug,
                'type' => $type,
                'population' => null,
                'area_km2' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('cities')->insert($chunk);
        }
    }

    public function down(): void
    {
        // Only the untouched ones: a place a listing has since been filed in is
        // load-bearing, and cities.region_id is restrictOnDelete anyway.
        $slugs = array_map(fn (array $place): string => Str::slug($place[0]), self::places());
        $inUse = DB::table('listings')->whereNotNull('city_id')->distinct()->pluck('city_id')->all();

        DB::table('cities')
            ->whereIn('slug', $slugs)
            ->whereNotIn('id', $inUse)
            ->delete();
    }

    /**
     * A park or reserve is filed in the region its lodges are reached
     * through, not spread across every region its fence touches — Etosha
     * alone spans four. That matches the region on the Destination card of
     * the same name (see DestinationSeeder) so the two never disagree.
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    public static function places(): array
    {
        return [
            // Etosha and the reserves on its boundary — the case that started this.
            ['Etosha National Park', 'Kunene', 'national_park'],
            ['Onguma Nature Reserve', 'Oshikoto', 'nature_reserve'],
            ['Ongava Game Reserve', 'Kunene', 'nature_reserve'],

            // Sossusvlei and the Namib.
            ['Sossusvlei', 'Hardap', 'landmark'],
            ['Sesriem', 'Hardap', 'settlement'],
            ['Solitaire', 'Khomas', 'settlement'],
            ['NamibRand Nature Reserve', 'Hardap', 'nature_reserve'],

            // Damaraland, Kaokoveld and the coast.
            ['Twyfelfontein', 'Kunene', 'landmark'],
            ['Palmwag', 'Kunene', 'settlement'],
            ['Skeleton Coast National Park', 'Kunene', 'national_park'],
            ['Spitzkoppe', 'Erongo', 'landmark'],
            ['Cape Cross', 'Erongo', 'landmark'],
            ['Sandwich Harbour', 'Erongo', 'landmark'],

            // The south.
            ['Fish River Canyon', 'Karas', 'landmark'],
            ['Ai-Ais', 'Karas', 'settlement'],
            ['Kolmanskop', 'Karas', 'landmark'],
            ['Aus', 'Karas', 'village'],

            // Central and the north-east.
            ['Waterberg Plateau Park', 'Otjozondjupa', 'national_park'],
            ['Erindi Private Game Reserve', 'Erongo', 'nature_reserve'],
            ['Bwabwata National Park', 'Kavango East', 'national_park'],
            ['Mudumu National Park', 'Zambezi', 'national_park'],
            ['Nkasa Rupara National Park', 'Zambezi', 'national_park'],
        ];
    }
};
