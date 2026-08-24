<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A first corpus of places to fill up and buy food.
 *
 * Written town by town rather than forecourt by forecourt, and that is the
 * design rather than a shortcut: the rule these rows feed measures gaps in
 * hundreds of kilometres (App\Services\Routing\SupplyStopFinder), so "there is
 * fuel in Kamanjab" is the fact that matters and which of the two pumps in
 * Kamanjab it is at is not. Each row therefore takes its name and its
 * coordinates from the town or place it belongs to, which also means nothing
 * here is a coordinate somebody typed from memory — the exceptions are the two
 * Etosha camps, which are inside a park rather than in a town and carry their
 * own.
 *
 * Coverage matters more than detail here, and in a specific direction: a
 * missing row does not make the plan quieter, it makes a gap look longer than
 * it is. That errs the safe way — a traveller fills up sooner than they had to
 * — but it is why this list runs down the roads people actually drive rather
 * than stopping at the big towns, and why the copy never says there is nothing
 * ahead, only how far it is to the next one we know of.
 *
 * `opening_hours` is set only where round-the-clock fuel is a standing fact
 * about the town; everywhere else it is null, which means "nobody has checked"
 * and not "closed". Same for `fuel_types` at the remote stops and for
 * `verified_at`, which is null throughout: nothing here has been confirmed by
 * a human on the ground, and the admin table says so in as many words.
 *
 * Published, for the same reason the attraction corpus ships published — an
 * empty trip plan teaches nobody anything through the build phase — and
 * idempotent on the slug, so a rerun changes nothing and a row somebody has
 * since corrected is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        $places = DB::table('places')->get()->keyBy('slug');
        $cities = DB::table('cities')->get()->keyBy('slug');
        $now = now();

        foreach (self::points() as [$locator, $name, $services, $fuelTypes, $hours, $coordinates, $note]) {
            $slug = Str::slug($name);

            if (DB::table('supply_points')->where('slug', $slug)->exists()) {
                continue;
            }

            // Places first, then cities — the same precedence
            // App\Services\Routing\RoutePointResolver uses, so a row lands
            // wherever the trip plan would look for that name.
            $place = $places->get($locator);
            $city = $place === null ? $cities->get($locator) : null;

            if ($place === null && $city === null) {
                continue;
            }

            $anchor = $place ?? $city;

            DB::table('supply_points')->insert([
                'name' => $name,
                'slug' => $slug,
                'services' => json_encode($services, JSON_THROW_ON_ERROR),
                'fuel_types' => json_encode($fuelTypes, JSON_THROW_ON_ERROR),
                'opening_hours' => $hours,
                'city_id' => $city?->id ?? $place?->city_id,
                'place_id' => $place?->id,
                'lat' => $coordinates[0] ?? $anchor?->lat,
                'lng' => $coordinates[1] ?? $anchor?->lng,
                'note' => $note === null ? null : json_encode(['en' => $note], JSON_THROW_ON_ERROR),
                'verified_at' => null,
                'is_published' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('supply_points')
            ->whereIn('slug', array_map(fn (array $row): string => Str::slug($row[1]), self::points()))
            ->delete();
    }

    /**
     * [place-or-city slug, name, services, fuel types, opening hours, [lat, lng] or [], note]
     *
     * @return array<int, array{0: string, 1: string, 2: array<int, string>, 3: array<int, string>, 4: string|null, 5: array<int, float>, 6: string|null}>
     */
    private static function points(): array
    {
        $fuel = ['fuel'];
        $shop = ['fuel', 'groceries'];
        $pumps = ['petrol', 'diesel'];
        $unknownPumps = [];

        return [
            // The B1 spine, north to south.
            ['windhoek', 'Windhoek', ['fuel', 'groceries', 'atm', 'gas', 'tyre_repair'], $pumps, '24/7', [], 'Everything, at any hour — and the last of it for a long way in three of the four directions out of town.'],
            ['okahandja', 'Okahandja', $shop, $pumps, null, [], null],
            ['otjiwarongo', 'Otjiwarongo', ['fuel', 'groceries', 'atm', 'tyre_repair'], $pumps, null, [], 'The last full-size supermarket before Etosha, Waterberg and the north-west.'],
            ['otavi', 'Otavi', $shop, $pumps, null, [], null],
            ['tsumeb', 'Tsumeb', ['fuel', 'groceries', 'atm'], $pumps, null, [], null],
            ['grootfontein', 'Grootfontein', ['fuel', 'groceries', 'atm'], $pumps, null, [], 'Stock up here for Tsumkwe and the Khaudum — there is nothing dependable east of it.'],
            ['oshivelo', 'Oshivelo', $fuel, $pumps, null, [], 'At the veterinary fence on the B1.'],
            ['omuthiya', 'Omuthiya', $shop, $pumps, null, [], null],
            ['rehoboth', 'Rehoboth', $shop, $pumps, null, [], null],
            ['kalkrand', 'Kalkrand', $fuel, $pumps, null, [], null],
            ['mariental', 'Mariental', ['fuel', 'groceries', 'atm'], $pumps, null, [], null],
            ['keetmanshoop', 'Keetmanshoop', ['fuel', 'groceries', 'atm'], $pumps, null, [], 'The junction town of the south — the last of everything before the Fish River Canyon and the Lüderitz road.'],
            ['karasburg', 'Karasburg', $shop, $pumps, null, [], null],
            ['noordoewer', 'Noordoewer', $fuel, $pumps, null, [], 'At the South African border.'],
            ['ariamsvlei', 'Ariamsvlei', $fuel, $pumps, null, [], null],

            // The coast and the roads to it.
            ['karibib', 'Karibib', $shop, $pumps, null, [], null],
            ['usakos', 'Usakos', $shop, $pumps, null, [], null],
            ['omaruru', 'Omaruru', $shop, $pumps, null, [], null],
            ['arandis', 'Arandis', $fuel, $pumps, null, [], null],
            ['swakopmund', 'Swakopmund', ['fuel', 'groceries', 'atm', 'gas', 'tyre_repair'], $pumps, '24/7', [], null],
            ['walvis-bay', 'Walvis Bay', ['fuel', 'groceries', 'atm', 'gas', 'tyre_repair'], $pumps, '24/7', [], null],
            ['henties-bay', 'Henties Bay', $shop, $pumps, null, [], 'The last of both before the Skeleton Coast gates.'],

            // The north-west: Damaraland, Kaokoland, the Kunene.
            ['uis', 'Uis', $fuel, $pumps, null, [], 'Fuel and a general dealer — enough for a night, not enough for a week.'],
            ['khorixas', 'Khorixas', $shop, $pumps, null, [], null],
            ['kamanjab', 'Kamanjab', $shop, $pumps, null, [], 'The last shop worth calling one before Opuwo or the Hoanib.'],
            ['palmwag', 'Palmwag', $fuel, $unknownPumps, null, [], 'A lodge forecourt rather than a town. Do not plan a tank around it.'],
            ['sesfontein', 'Sesfontein', $fuel, $unknownPumps, null, [], 'Sometimes dry. Carry enough to reach Opuwo or Palmwag without it.'],
            ['opuwo', 'Opuwo', ['fuel', 'groceries', 'atm'], $pumps, null, [], 'The last supermarket and the last cash machine before the Kaokoveld.'],
            ['ruacana', 'Ruacana', $fuel, $pumps, null, [], null],

            // Etosha. Inside a park rather than in a town, so these two carry
            // their own coordinates.
            ['etosha-national-park', 'Okaukuejo', $fuel, $unknownPumps, null, [-19.1817, 15.9119], 'Inside the park, at the camp. Subject to what the tanker last brought.'],
            ['etosha-national-park', 'Namutoni', $fuel, $unknownPumps, null, [-18.8064, 16.9339], 'Inside the park, at the camp. Subject to what the tanker last brought.'],
            ['outjo', 'Outjo', $shop, $pumps, null, [], 'The usual last stop for anybody entering Etosha from the south.'],

            // The Namib: Sossusvlei, the Naukluft and the roads south of them.
            ['solitaire', 'Solitaire', $fuel, $pumps, null, [], 'One forecourt, one shop and a bakery, and no fuel for a long way in any direction — which is why everybody stops.'],
            ['sesriem', 'Sesriem', $fuel, $pumps, null, [], 'At the park gate, for the dune run and back.'],
            ['maltahohe', 'Maltahöhe', $fuel, $pumps, null, [], null],
            ['bethanie', 'Bethanie', $fuel, $pumps, null, [], null],
            ['aus', 'Aus', $fuel, $pumps, null, [], 'The last fuel before Lüderitz, and the first coming back.'],
            ['luderitz', 'Lüderitz', $shop, $pumps, null, [], null],
            ['rosh-pinah', 'Rosh Pinah', $shop, $pumps, null, [], null],
            ['oranjemund', 'Oranjemund', $shop, $pumps, null, [], null],

            // The east and the Kalahari.
            ['gobabis', 'Gobabis', ['fuel', 'groceries', 'atm'], $pumps, null, [], null],
            ['witvlei', 'Witvlei', $fuel, $pumps, null, [], null],
            ['buitepos', 'Buitepos', $fuel, $pumps, null, [], 'At the Botswana border.'],
            ['aranos', 'Aranos', $shop, $pumps, null, [], null],
            ['stampriet', 'Stampriet', $fuel, $pumps, null, [], null],
            ['okakarara', 'Okakarara', $fuel, $pumps, null, [], null],
            ['kalkfeld', 'Kalkfeld', $fuel, $pumps, null, [], null],
            ['tsumkwe', 'Tsumkwe', $fuel, $unknownPumps, null, [], 'The only fuel in the far east, and not to be relied on. Carry jerrycans for the Khaudum.'],

            // The north and the Zambezi.
            ['ondangwa', 'Ondangwa', ['fuel', 'groceries', 'atm'], $pumps, null, [], null],
            ['oshakati', 'Oshakati', ['fuel', 'groceries', 'atm'], $pumps, null, [], null],
            ['ongwediva', 'Ongwediva', $shop, $pumps, null, [], null],
            ['outapi', 'Outapi', $shop, $pumps, null, [], null],
            ['eenhana', 'Eenhana', $shop, $pumps, null, [], null],
            ['nkurenkuru', 'Nkurenkuru', $fuel, $pumps, null, [], null],
            ['rundu', 'Rundu', ['fuel', 'groceries', 'atm'], $pumps, null, [], 'The last supermarket before the Caprivi strip.'],
            ['divundu', 'Divundu', $fuel, $pumps, null, [], 'The turn-off for Mahango and Botswana.'],
            ['katima-mulilo', 'Katima Mulilo', ['fuel', 'groceries', 'atm'], $pumps, null, [], null],
        ];
    }
};
