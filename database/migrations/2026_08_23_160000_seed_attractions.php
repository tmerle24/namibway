<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A first corpus of things to go and look at.
 *
 * Published, and marked `ai_generated` so it stays obvious what it is. The
 * names, the categories and the rough geography are reliable; the coordinates
 * are written from knowledge rather than read off a map, and two kilometres out
 * is a wrong turn on a gravel road — so every one of them still wants checking
 * against a map before this is in front of a paying traveller. It ships
 * published anyway because through the build phase the only people using this
 * database are the team, and an empty trip plan teaches nobody anything.
 *
 * `entry_fee` is null throughout and that is not laziness: a fee we invented is
 * worse than no fee at all, and they change.
 *
 * `requires_4x4` and `requires_permit` are set only where the answer is a
 * standing fact about the site rather than a season or an operator's policy —
 * Sandwich Harbour cannot be reached in a saloon car, Kolmanskop cannot be
 * entered without a permit. Everywhere else they stay null, which means "not
 * established" and not "no".
 *
 * Idempotent on the slug, so a rerun changes nothing and a row somebody has
 * since corrected is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        $places = DB::table('places')->pluck('id', 'slug');
        $cities = DB::table('cities')->pluck('id', 'slug');
        $now = now();

        foreach (self::attractions() as $row) {
            [$name, $type, $placeSlug, $citySlug, $lat, $lng, $minutes, $needs4x4, $needsPermit, $summary] = $row;

            $slug = Str::slug($name);

            if (DB::table('attractions')->where('slug', $slug)->exists()) {
                continue;
            }

            DB::table('attractions')->insert([
                'name' => json_encode(['en' => $name], JSON_THROW_ON_ERROR),
                'slug' => $slug,
                'type' => $type,
                'summary' => json_encode(['en' => $summary], JSON_THROW_ON_ERROR),
                'place_id' => $placeSlug !== null ? ($places[$placeSlug] ?? null) : null,
                'city_id' => $citySlug !== null ? ($cities[$citySlug] ?? null) : null,
                'lat' => $lat,
                'lng' => $lng,
                'visit_minutes' => $minutes,
                'entry_fee_currency' => 'NAD',
                'requires_4x4' => $needs4x4,
                'requires_permit' => $needsPermit,
                'description_source' => 'ai_generated',
                'is_published' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('attractions')
            ->whereIn('slug', array_map(fn (array $row): string => Str::slug($row[0]), self::attractions()))
            ->delete();
    }

    /**
     * [name, type, place slug, city slug, lat, lng, visit minutes, 4x4, permit, one line]
     *
     * @return array<int, array{0: string, 1: string, 2: string|null, 3: string|null, 4: float, 5: float, 6: int|null, 7: bool|null, 8: bool|null, 9: string}>
     */
    private static function attractions(): array
    {
        return [
            // --- The north-east and the Otavi triangle ------------------------
            ['Hoba Meteorite', 'geology', null, 'grootfontein', -19.5925, 17.9333, 45, null, null, 'The largest known meteorite on Earth, still lying where it fell, in a farmyard outside Grootfontein.'],
            ['Lake Otjikoto', 'natural_feature', null, 'tsumeb', -19.1922, 17.5486, 60, null, null, 'A collapsed dolomite sinkhole where the retreating German army dumped its weapons in 1915; some are still down there.'],
            ['Lake Guinas', 'natural_feature', null, 'tsumeb', -19.1167, 17.4167, 45, null, null, 'The deeper and quieter of the two Tsumeb sinkhole lakes, with its own endemic cichlid.'],
            ['Tsumeb Museum', 'museum', null, 'tsumeb', -19.2400, 17.7167, 90, null, null, 'The mine that made the town, plus the Otjikoto weapons recovered from the lake.'],
            ['Fort Namutoni', 'history', 'etosha-national-park', null, -18.8067, 16.9333, 60, null, null, 'A whitewashed German fort inside Etosha, besieged in 1904 and rebuilt as a rest camp.'],
            ['Okaukuejo Waterhole', 'wildlife', 'etosha-national-park', null, -19.1817, 15.9200, 120, null, null, 'Floodlit through the night, and the reason people sit up until two in the morning at Etosha.'],

            // --- Damaraland and the Erongo -----------------------------------
            ['Twyfelfontein Rock Engravings', 'rock_art', 'twyfelfontein', null, -20.5936, 14.3717, 120, null, true, "Namibia's first World Heritage Site: thousands of engravings on sandstone, seen with a guide."],
            ['Organ Pipes', 'geology', 'twyfelfontein', null, -20.5667, 14.4000, 30, null, null, 'A gorge wall of five-metre dolerite columns, twenty minutes off the Twyfelfontein road.'],
            ['Burnt Mountain', 'geology', 'twyfelfontein', null, -20.5833, 14.4000, 30, null, null, 'A scorched-looking ridge of black and red shale, worth the light at either end of the day.'],
            ['Petrified Forest', 'palaeontology', null, 'khorixas', -20.4667, 14.5667, 60, null, true, 'Fossilised tree trunks washed here 280 million years ago, walked with a guide.'],
            ['Brandberg White Lady', 'rock_art', null, 'uis', -21.1167, 14.5833, 240, null, true, "A famous painted figure in the Tsisab Ravine under Namibia's highest mountain; a guided walk of two hours each way."],
            ['Spitzkoppe Rock Arch', 'geology', 'spitzkoppe', null, -21.8283, 15.1944, 180, null, null, 'The granite inselberg that ends up on the postcards, with a natural arch you can walk to.'],
            ['Phillipp\'s Cave', 'rock_art', null, 'omaruru', -21.5333, 15.7333, 120, null, null, 'The white elephant painting in the Erongo mountains, reached on a walk from the farm below.'],
            ['Vingerklip', 'geology', null, 'khorixas', -20.4000, 15.0333, 60, null, null, 'A 35-metre limestone finger left standing when the valley around it eroded away.'],

            // --- The coast ----------------------------------------------------
            ['Cape Cross Seal Colony', 'wildlife', 'cape-cross', null, -21.7700, 13.9500, 90, null, null, 'Tens of thousands of Cape fur seals, and the cross Diogo Cão planted in 1486.'],
            ['Zeila Shipwreck', 'history', null, 'henties-bay', -22.0333, 14.2667, 20, null, null, 'A stranded fishing trawler rusting in the surf, right beside the salt road.'],
            ['Sandwich Harbour', 'natural_feature', 'sandwich-harbour', null, -23.3833, 14.4667, 300, true, true, 'Where the dunes fall straight into the Atlantic. Tide-dependent and only ever with a guide.'],
            ['Dune 7', 'natural_feature', null, 'walvis-bay', -23.0833, 14.6500, 45, null, null, 'The big one beside the road out of Walvis Bay, climbed in half an hour and regretted for a day.'],
            ['Walvis Bay Lagoon', 'wildlife', null, 'walvis-bay', -22.9500, 14.4833, 60, null, null, 'A Ramsar wetland holding flamingos, pelicans and waders in the tens of thousands.'],
            ['Moon Landscape', 'geology', null, 'swakopmund', -22.8167, 14.9333, 60, null, null, 'The eroded Swakop valley, closest thing to a lunar surface on the way inland.'],
            ['Welwitschia Plains', 'natural_feature', null, 'swakopmund', -22.8000, 15.0833, 90, null, true, 'Where the thousand-year-old plants grow, on a self-drive route that needs a park permit.'],
            ['Ugab River Gate', 'landmark', 'skeleton-coast-national-park', null, -21.1667, 13.6333, 20, null, true, 'The skull-and-crossbones gate into the Skeleton Coast, and the photograph everybody stops for.'],

            // --- Sossusvlei and the Namib ------------------------------------
            ['Deadvlei', 'natural_feature', 'sossusvlei', null, -24.7592, 15.2924, 180, true, true, 'The white clay pan with 900-year-old dead camelthorns, an hour on foot from the 2WD car park.'],
            ['Big Daddy', 'natural_feature', 'sossusvlei', null, -24.7333, 15.3000, 180, true, null, 'The 325-metre dune above Deadvlei; up the ridge, down the face, and it is best done at dawn.'],
            ['Dune 45', 'natural_feature', 'sossusvlei', null, -24.7300, 15.4700, 60, null, null, 'The photogenic one 45 km inside the gate, on tar, and the standard sunrise climb.'],
            ['Sesriem Canyon', 'geology', 'sesriem', null, -24.4933, 15.8000, 60, null, null, 'A narrow slot the Tsauchab cut into a million-year-old conglomerate, walkable end to end.'],
            ['Spreetshoogte Pass', 'viewpoint', null, 'solitaire', -23.7333, 16.0333, 30, null, null, "Namibia's steepest pass, dropping off the escarpment onto the Namib in a few kilometres."],
            ['Tropic of Capricorn Sign', 'landmark', null, 'solitaire', -23.4333, 15.9333, 10, null, null, 'A signpost in the gravel on the C14, and a photograph nobody drives past.'],
            ['Kuiseb Canyon Viewpoint', 'viewpoint', null, 'walvis-bay', -23.3000, 15.8000, 30, null, null, 'The canyon two geologists hid in through the Second World War, off the Gamsberg road.'],
            ['Duwisib Castle', 'history', null, 'maltahohe', -25.3167, 16.2333, 60, null, null, 'A German baron built a Bavarian castle here in 1909, and then left for a war he did not come back from.'],

            // --- The south -----------------------------------------------------
            ['Fish River Canyon Viewpoint', 'viewpoint', 'fish-river-canyon', null, -27.5833, 17.6167, 90, null, true, 'The main lookout over the second-largest canyon on earth; best in the last hour of light.'],
            ['Ai-Ais Hot Springs', 'natural_feature', 'ai-ais', null, -27.9167, 17.4833, 120, null, null, 'Sulphurous water at 60°C at the southern end of the canyon, where the hikers come out.'],
            ['Kolmanskop Ghost Town', 'history', 'kolmanskop', null, -26.7000, 15.2333, 120, null, true, 'A diamond town the desert took back, room by room. Permit only, and the morning light is the point.'],
            ['Felsenkirche', 'history', null, 'luderitz', -26.6483, 15.1594, 30, null, null, 'The rock church above Lüderitz, built in 1911 and open for a couple of hours a day.'],
            ['Diaz Point', 'history', null, 'luderitz', -26.6333, 15.0833, 45, null, null, 'A replica of the cross Bartolomeu Dias left in 1488, on a headland with seals and a lot of wind.'],
            ['Garub Wild Horses', 'wildlife', null, 'aus', -26.6167, 16.2333, 60, null, null, 'The desert horses of the Namib at their waterhole, watched from a hide beside the B4.'],
            ['Quiver Tree Forest', 'natural_feature', null, 'keetmanshoop', -26.4667, 18.2333, 60, null, null, 'A few hundred aloes on a farm outside Keetmanshoop, and the classic Namibian night-sky photograph.'],
            ["Giant's Playground", 'geology', null, 'keetmanshoop', -26.4333, 18.2500, 60, null, null, 'Dolerite blocks stacked as if somebody put them there, next door to the quiver trees.'],
            ['Mesosaurus Fossil Site', 'palaeontology', null, 'keetmanshoop', -26.3167, 18.4167, 90, null, null, '270-million-year-old freshwater reptiles in the shale, shown by the farmer who found them.'],

            // --- The centre ----------------------------------------------------
            ['Christuskirche', 'history', null, 'windhoek', -22.5744, 17.0847, 30, null, null, 'The 1907 Lutheran church on its island in the middle of Windhoek traffic.'],
            ['Independence Memorial Museum', 'museum', null, 'windhoek', -22.5731, 17.0847, 90, null, null, 'The liberation struggle told from the top down, with the best view over the city from the café.'],
            ["Heroes' Acre", 'history', null, 'windhoek', -22.6500, 17.0667, 60, null, null, 'The national memorial on the Rehoboth road, an obelisk and a marble frieze above the plain.'],
            ['Waterberg Plateau', 'viewpoint', 'waterberg-plateau-park', null, -20.5167, 17.2333, 240, null, true, 'A sandstone table rising 200 m off the plain, with a stiff walk to the top and the 1904 battlefield below.'],
            ['Otjihaenamaparero Dinosaur Tracks', 'palaeontology', null, 'kalkfeld', -21.0333, 16.2500, 60, null, null, 'Three-toed prints left in wet sand 190 million years ago, on a working farm near Kalkfeld.'],

            // --- The far north -------------------------------------------------
            ['Epupa Falls', 'natural_feature', null, 'opuwo', -17.0000, 13.2500, 120, true, null, 'The Kunene breaking into a kilometre of channels under baobabs, on the Angolan border.'],
            ['Ruacana Falls', 'natural_feature', null, 'ruacana', -17.3833, 14.2167, 60, null, null, 'A 120-metre fall that only runs when the Kunene is high — spectacular in flood, dry the rest of the year.'],
            ['Popa Falls', 'natural_feature', null, 'divundu', -18.1167, 21.5833, 60, null, null, 'Not a fall but a four-metre cascade across the whole Okavango, and the reason people stop at Divundu.'],
        ];
    }
};
