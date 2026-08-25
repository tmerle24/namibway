<?php

namespace App\Console\Commands;

use App\Enums\SupplyService;
use App\Models\SupplyPoint;
use App\Support\Geo;
use App\Support\OpeningHours;
use Illuminate\Console\Command;

/**
 * Fills in when a supply point is open, from scripts/scrape_osm_supply_hours.py.
 *
 * Our rows are towns — "there is fuel in Kamanjab" — and a town has several
 * forecourts with different hours. So this does not merge them into a string
 * no sign anywhere says. It picks **one real element** and stores its hours
 * verbatim, together with the element it came from, and the one it picks is
 * the most generous: the traveller will drive to whichever is still open, so
 * what the row should say is the best they can do in that town.
 *
 * Three rules keep this honest, and each of them is a way of saying no:
 *
 * - **It only fills blanks.** A value somebody typed is a decision, and a
 *   machine reading somebody else's map does not get to overrule it.
 *   `--overwrite` exists for the day OSM is better than an old guess, and it
 *   has to be asked for.
 * - **It refuses what it cannot read.** Every candidate goes through
 *   App\Support\OpeningHours, which understands a documented subset and
 *   rejects the rest. An element whose hours we cannot parse is counted and
 *   reported, never stored half-understood — the traveller drives on this.
 * - **It does not touch `verified_at`.** Reading a third party's map is not
 *   somebody confirming the pumps still work. The row stays "nobody has
 *   checked", which is true.
 *
 * What it prints at the end is as much the point as what it writes: the rows
 * still without hours, in route order of usefulness, are the call list — those
 * are the places OSM does not know and a person has to phone.
 *
 * Source: OpenStreetMap contributors, ODbL.
 */
class ImportSupplyHours extends Command
{
    protected $signature = 'namibway:import-supply-hours
        {--file=data/scraped/osm_supply_hours.json : Scraper output to read}
        {--radius=15 : How far from the row an element may sit, in kilometres}
        {--only= : Comma-separated supply point slugs}
        {--limit=0 : Max supply points to process (0 = all)}
        {--overwrite : Also replace hours that are already recorded}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Fill supply-point opening hours from the OpenStreetMap extract';

    /**
     * Which kinds of element may answer for which service.
     *
     * A fuel row takes its hours from a filling station, because that is what
     * the row is mostly about. A row that only sells groceries takes them from
     * a supermarket or a general dealer — outside the towns those are the same
     * shop — and never from a convenience kiosk, which is not somewhere you
     * stock up for three nights.
     */
    private const KINDS_FOR_FUEL = ['fuel'];

    private const KINDS_FOR_GROCERIES = ['supermarket', 'general'];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $radius = (float) $this->option('radius');
        $overwrite = (bool) $this->option('overwrite');

        if ($dry) {
            $this->warn('DRY RUN — no changes will be written');
        }

        $elements = $this->elements();

        // Null is a broken or missing file, which is a mistake worth failing
        // on. An empty list is not: an extract where nothing carries readable
        // hours is a real answer about a real place, and the run should end by
        // printing the rows that still need a phone call.
        if ($elements === null) {
            return self::FAILURE;
        }

        $this->info(count($elements).' element(s) with readable hours in the extract.');

        $query = SupplyPoint::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderBy('name');

        $only = $this->option('only');

        if (is_string($only) && $only !== '') {
            $query->whereIn('slug', array_map(trim(...), explode(',', $only)));
        }

        $limit = (int) $this->option('limit');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $filled = 0;
        $kept = 0;
        $stillEmpty = [];

        foreach ($query->get() as $point) {
            if (filled($point->opening_hours) && ! $overwrite) {
                $kept++;

                continue;
            }

            $best = $this->bestFor($point, $elements, $radius);

            if ($best === null) {
                $stillEmpty[] = $point->name;

                continue;
            }

            $this->line(sprintf(
                '%-22s %-34s %s',
                $point->name,
                $best['opening_hours'],
                $best['osm'],
            ));

            if (! $dry) {
                $point->update([
                    'opening_hours' => $best['opening_hours'],
                    'opening_hours_source' => 'osm:'.$best['osm'],
                ]);
            }

            $filled++;
        }

        $this->newLine();
        $this->info("Filled {$filled}, left {$kept} already-recorded row(s) alone.");

        if ($stillEmpty !== []) {
            // Not a failure — this is the useful half of the output. OSM does
            // not know these, so a person has to.
            $this->newLine();
            $this->warn('Still without hours ('.count($stillEmpty).') — somebody has to phone these:');

            foreach ($stillEmpty as $name) {
                $this->line("  {$name}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * The extract, reduced to what can actually be used: an element with
     * coordinates and hours this codebase is willing to read.
     *
     * Null where the file itself is the problem — missing, or not one of
     * these — as opposed to an extract that simply has nothing usable in it.
     *
     * @return array<int, array{osm: string, kind: string, lat: float, lng: float, opening_hours: string, minutes: int}>|null
     */
    private function elements(): ?array
    {
        $path = (string) $this->option('file');

        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        if (! is_file($path)) {
            $this->error("No extract at {$path}");

            return null;
        }

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $raw = is_array($payload) ? ($payload['elements'] ?? null) : null;

        if (! is_array($raw)) {
            $this->error('That file is not a supply-hours extract.');

            return null;
        }

        $unreadable = 0;
        $usable = [];

        foreach ($raw as $element) {
            if (! is_array($element)) {
                continue;
            }

            $hours = $element['opening_hours'] ?? null;
            $lat = $element['lat'] ?? null;
            $lng = $element['lng'] ?? null;

            if (! is_string($hours) || $hours === '' || ! is_numeric($lat) || ! is_numeric($lng)) {
                continue;
            }

            $parsed = OpeningHours::parse($hours);

            if ($parsed === null) {
                // Real OSM carries plenty of this — "Mo-Fr 08:00-17:00 || by
                // appointment", month ranges, sunset. Counted out loud rather
                // than half-read into a column somebody plans a tank around.
                $unreadable++;

                continue;
            }

            $usable[] = [
                'osm' => is_string($element['osm'] ?? null) ? $element['osm'] : 'unknown',
                'kind' => is_string($element['kind'] ?? null) ? $element['kind'] : 'general',
                'lat' => (float) $lat,
                'lng' => (float) $lng,
                'opening_hours' => $hours,
                'minutes' => $parsed->weeklyMinutes(),
            ];
        }

        if ($unreadable > 0) {
            $this->warn("Skipped {$unreadable} element(s) whose hours this cannot read in full.");
        }

        return $usable;
    }

    /**
     * The most generous element near this row that answers for what it sells,
     * with the nearest winning a tie.
     *
     * @param  array<int, array{osm: string, kind: string, lat: float, lng: float, opening_hours: string, minutes: int}>  $elements
     * @return array{osm: string, kind: string, lat: float, lng: float, opening_hours: string, minutes: int}|null
     */
    private function bestFor(SupplyPoint $point, array $elements, float $radius): ?array
    {
        $kinds = $this->kindsFor($point);

        if ($kinds === [] || $point->lat === null || $point->lng === null) {
            return null;
        }

        $best = null;
        $bestDistance = INF;

        foreach ($elements as $element) {
            if (! in_array($element['kind'], $kinds, true)) {
                continue;
            }

            $distance = Geo::distanceKm($point->lat, $point->lng, $element['lat'], $element['lng']);

            if ($distance > $radius) {
                continue;
            }

            if ($best === null
                || $element['minutes'] > $best['minutes']
                || ($element['minutes'] === $best['minutes'] && $distance < $bestDistance)) {
                $best = $element;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * @return array<int, string>
     */
    private function kindsFor(SupplyPoint $point): array
    {
        if ($point->provides(SupplyService::Fuel)) {
            return self::KINDS_FOR_FUEL;
        }

        if ($point->provides(SupplyService::Groceries)) {
            return self::KINDS_FOR_GROCERIES;
        }

        return [];
    }
}
