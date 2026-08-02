<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Region;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Local `migrate:fresh --seed` convenience — the same ~100 rows are seeded
 * for real (including production) directly inside
 * 2026_08_02_180300_create_cities_table.php, since deploy.sh only runs
 * `migrate --force`, never `db:seed`. Kept idempotent (firstOrCreate) so
 * running this standalone after the migration already seeded the table is a
 * no-op rather than a duplicate/unique-constraint error.
 */
class CitySeeder extends Seeder
{
    public function run(): void
    {
        $regionIds = Region::pluck('id', 'name');

        // [name, region, type, population, area_km2]
        $cities = [
            ['Windhoek', 'Khomas', 'municipality', 486169, 5133.4],
            ['Rundu', 'Kavango East', 'town', 118625, 164.1],
            ['Walvis Bay', 'Erongo', 'municipality', 102704, 1121.1],
            ['Swakopmund', 'Erongo', 'municipality', 75921, 196.3],
            ['Otjiwarongo', 'Otjozondjupa', 'community', 49022, 139.9],
            ['Katima Mulilo', 'Zambezi', 'town', 46401, 33.4],
            ['Okahandja', 'Otjozondjupa', 'community', 45159, 164.2],
            ['Rehoboth', 'Hardap', 'town', 40788, 649.0],
            ['Gobabis', 'Omaheke', 'community', 33404, 377.4],
            ['Helao Nafidi', 'Ohangwena', 'town', 28508, 86.5],
            ['Keetmanshoop', 'Karas', 'community', 27862, 586.9],
            ['Grootfontein', 'Otjozondjupa', 'community', 26839, 70.1],
            ['Oshakati', 'Oshana', 'town', 26463, 60.5],
            ['Tsumeb', 'Oshikoto', 'community', 17504, 18.0],
            ['Eenhana', 'Ohangwena', 'town', 16588, 39.0],
            ['Lüderitz', 'Karas', 'town', 16125, 15.3],
            ['Mariental', 'Hardap', 'community', 15475, 39.0],
            ['Outjo', 'Kunene', 'community', 15063, 104.6],
            ['Ondangwa', 'Oshana', 'town', 14359, 49.6],
            ['Outapi', 'Omusati', 'town', 13671, 10.8],
            ['Opuwo', 'Kunene', 'town', 12335, 10.4],
            ['Ongwediva', 'Oshana', 'town', 11912, 44.1],
            ['Otavi', 'Oshikoto', 'town', 10756, 43.3],
            ['Omaruru', 'Erongo', 'community', 10670, 206.6],
            ['Nkurenkuru', 'Kavango West', 'town', 10261, 49.3],
            ['Khorixas', 'Kunene', 'town', 9371, 76.0],
            ['Henties Bay', 'Erongo', 'community', 7569, 133.5],
            ['Oranjemund', 'Karas', 'town', 7441, 6.4],
            ['Okakarara', 'Otjozondjupa', 'town', 7123, 21.6],
            ['Karibib', 'Erongo', 'community', 6938, 103.6],
            ['Karasburg', 'Karas', 'community', 6621, 39.9],
            ['Ruacana', 'Omusati', 'town', 5939, 51.4],
            ['Arandis', 'Erongo', 'town', 5726, 33.4],
            ['Oshikuku', 'Omusati', 'town', 5499, 19.2],
            ['Aranos', 'Hardap', 'town', 5493, 66.7],
            ['Usakos', 'Erongo', 'community', 5094, 60.8],
            ['Okahao', 'Omusati', 'town', 4519, 6.3],
            ['Omuthiya', 'Oshikoto', 'town', 3664, 132.1],
            ['Oniipa', 'Oshikoto', 'town', 1999, 14.5],
            ['Rosh Pinah', 'Karas', 'private_town', null, null],

            ['Aroab', 'Karas', 'village', 2651, null],
            ['Berseba', 'Karas', 'village', 992, null],
            ['Bethanie', 'Karas', 'village', 2372, null],
            ['Bukalo', 'Zambezi', 'village', 1935, null],
            ['Divundu', 'Kavango East', 'village', 2028, null],
            ['Gibeon', 'Hardap', 'village', 4210, null],
            ['Gochas', 'Hardap', 'village', 1868, null],
            ['Kalkrand', 'Hardap', 'village', 1602, null],
            ['Kamanjab', 'Kunene', 'village', 3915, null],
            ['Koës', 'Karas', 'village', 2264, null],
            ['Leonardville', 'Omaheke', 'village', 2099, null],
            ['Maltahöhe', 'Hardap', 'village', 3464, null],
            ['Okongo', 'Ohangwena', 'village', 3557, null],
            ['Onandjaba', 'Omusati', 'village', null, null],
            ['Otjinene', 'Omaheke', 'village', 6876, null],
            ['Stampriet', 'Hardap', 'village', 3388, null],
            ['Tsandi', 'Omusati', 'village', 2595, null],
            ['Tses', 'Karas', 'village', 2053, null],
            ['Uis', 'Erongo', 'village', null, null],
            ['Witvlei', 'Omaheke', 'village', 2633, null],

            ['Aminuis', 'Omaheke', 'settlement', null, null],
            ['Ariamsvlei', 'Karas', 'settlement', null, null],
            ['Buitepos', 'Omaheke', 'settlement', 900, null],
            ['Coblenz', 'Otjozondjupa', 'settlement', 1000, null],
            ['Corridor 13', 'Omaheke', 'settlement', 1181, null],
            ['Dordabis', 'Khomas', 'settlement', null, null],
            ['Eheke', 'Oshana', 'settlement', 1115, null],
            ['Epukiro Post 3', 'Omaheke', 'settlement', null, null],
            ['Fransfontein', 'Kunene', 'settlement', 533, null],
            ['Gam', 'Otjozondjupa', 'settlement', null, null],
            ['Groot Aub', 'Khomas', 'settlement', 750, null],
            ['Hoachanas', 'Hardap', 'settlement', 2115, null],
            ['Kalkfeld', 'Otjozondjupa', 'settlement', null, null],
            ['Katwitwi', 'Kavango West', 'settlement', null, null],
            ['Klein Aub', 'Hardap', 'settlement', 610, null],
            ['Kombat', 'Otjozondjupa', 'settlement', null, null],
            ['Kosis', 'Karas', 'settlement', null, null],
            ['Kriess', 'Hardap', 'settlement', null, null],
            ['Luhonono', 'Zambezi', 'settlement', 800, null],
            ['Ndiyona', 'Kavango East', 'settlement', null, null],
            ['Noordoewer', 'Karas', 'settlement', null, null],
            ['Ogongo', 'Omusati', 'settlement', null, null],
            ['Okangwati', 'Kunene', 'settlement', null, null],
            ['Okalongo', 'Omusati', 'settlement', null, null],
            ['Okamatapati', 'Otjozondjupa', 'settlement', null, null],
            ['Okandjatu', 'Otjozondjupa', 'settlement', null, null],
            ['Okandjira', 'Otjozondjupa', 'settlement', null, null],
            ['Okatjoruu', 'Otjozondjupa', 'settlement', null, null],
            ['Okombahe', 'Erongo', 'settlement', null, null],
            ['Omatjette', 'Erongo', 'settlement', null, null],
            ['Omungwelume', 'Ohangwena', 'settlement', 960, null],
            ['Onayena', 'Oshikoto', 'settlement', null, null],
            ['Onesi', 'Omusati', 'settlement', 2000, null],
            ['Ongenga', 'Ohangwena', 'settlement', 500, null],
            ['Oshigambo', 'Oshikoto', 'settlement', null, null],
            ['Oshivelo', 'Oshikoto', 'settlement', null, null],
            ['Otjimbingwe', 'Erongo', 'settlement', null, null],
            ['Schlip', 'Hardap', 'settlement', 1227, null],
            ['Sesfontein', 'Kunene', 'settlement', null, null],
            ['Summerdown', 'Omaheke', 'settlement', 678, null],
            ['Talismanus', 'Omaheke', 'settlement', null, null],
            ['Tsintsabis', 'Oshikoto', 'settlement', null, null],
            ['Tsumkwe', 'Otjozondjupa', 'settlement', 500, null],
            ['Uukwangula', 'Oshana', 'settlement', 50, null],
            ['Warmbad', 'Karas', 'settlement', 1200, null],
        ];

        foreach ($cities as [$name, $region, $type, $population, $area]) {
            City::firstOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'region_id' => $regionIds[$region],
                    'name' => $name,
                    'type' => $type,
                    'population' => $population,
                    'area_km2' => $area,
                ]
            );
        }
    }
}
