<?php

namespace App\Console\Commands;

use App\Enums\PlaceType;
use App\Models\Listing;
use App\Models\Place;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Files listings at the tourism place they actually sit in, rather than at the
 * town their post goes to.
 *
 * This is the last mile of the 2026-08-23 city/place split. The split gave a
 * listing two homes; the migrations only filled the second one where the old
 * data already said "this lodge is in a park". Everything else inherited its
 * address city — so a camp on Onguma still resolved to Outjo, and the trip plan
 * printed a stage called "Outjo (Kunene)", which tells a traveller nothing and
 * is 100 km from where they will be.
 *
 * **Two signals, and the name is the stronger one.** A place's short name
 * (Place::shortName) matched against a listing's name is nearly conclusive:
 * "Onguma Bush Camp" is on Onguma no matter what its postal address says. The
 * distance to the place's coordinates is the second signal, and on its own it
 * is unreliable for a big park — Etosha is 300 km wide and its geocoded point
 * sits at one end, so a camp at Namutoni is nearer to Tsumeb than to "Etosha".
 * That is exactly why a name match is allowed to win over a shorter distance,
 * and why a distance match alone is held to a tight radius.
 *
 * **What it will not do.** It never overwrites a place that is already a
 * tourism place: that value was either set by hand or derived from data that
 * already knew, and second-guessing it is how the city backfill of 2026-08-05
 * destroyed correct rows. It only fills a blank or replaces a *town* place,
 * and it is a dry run unless told otherwise.
 */
class BackfillListingPlaces extends Command
{
    /** How near a listing must be to take a place on distance alone. */
    private const MAX_DISTANCE_KM = 25.0;

    /**
     * How far a name match may disagree with the coordinates before it is
     * reported instead of applied. Generous, because a park's single point is
     * not its middle in any useful sense.
     */
    private const NAME_MATCH_SANITY_KM = 250.0;

    protected $signature = 'namibway:backfill-listing-places
                            {--apply : Write the changes instead of only listing them}';

    protected $description = 'File listings at the tourism place they sit in rather than at their postal town';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $this->warn('DRY RUN — nothing is written. Re-run with --apply once the list below looks right.');
        }

        /** @var Collection<int, Place> $places */
        $places = Place::query()->where('type', '!=', PlaceType::Town->value)->get();

        if ($places->isEmpty()) {
            $this->info('No tourism places to file anything against.');

            return self::SUCCESS;
        }

        $listings = Listing::query()
            ->with(['place', 'city'])
            ->get();

        $changes = [];
        $unsure = [];

        foreach ($listings as $listing) {
            // A tourism place already on the row is a decision, not a gap.
            if ($listing->place !== null && $listing->place->type !== PlaceType::Town) {
                continue;
            }

            $match = $this->match($listing, $places);

            if ($match === null) {
                continue;
            }

            [$place, $why, $km] = $match;

            if ($why === 'name' && $km !== null && $km > self::NAME_MATCH_SANITY_KM) {
                $unsure[] = [
                    $listing->name,
                    $place->name,
                    sprintf('%.0f km apart — name says one thing, coordinates another', $km),
                ];

                continue;
            }

            $changes[] = [
                $listing->name,
                $listing->place === null ? '—' : $listing->place->name,
                $place->name,
                $why === 'name' ? 'name' : sprintf('%.1f km', (float) $km),
            ];

            if ($apply) {
                $listing->place()->associate($place);
                $listing->saveQuietly();
            }
        }

        if ($changes !== []) {
            $this->newLine();
            $this->table(['Listing', 'Was', 'Now', 'Matched on'], $changes);
        } else {
            $this->info('Nothing to move — every listing is already filed where it belongs.');
        }

        if ($unsure !== []) {
            $this->newLine();
            $this->warn('Left alone, because the two signals disagree — set the place by hand under the listing:');
            $this->table(['Listing', 'Suggested', 'Why not'], $unsure);
        }

        $this->newLine();
        $this->table(
            ['Listings checked', $apply ? 'Moved' : 'Would move', 'Needs a human'],
            [[$listings->count(), count($changes), count($unsure)]],
        );

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Place>  $places
     * @return array{0: Place, 1: string, 2: float|null}|null
     */
    private function match(Listing $listing, Collection $places): ?array
    {
        $name = mb_strtolower($listing->getTranslation('name', 'en', useFallbackLocale: true));

        $nearest = null;
        $nearestKm = null;

        foreach ($places as $place) {
            $km = $this->distanceTo($listing, $place);

            // A place whose own name a listing carries. The longest match wins,
            // so "Onguma Nature Reserve" beats a hypothetical "Onguma" and a
            // camp is never filed at the vaguer of two places.
            $term = mb_strtolower((string) ($place->shortName() ?? $place->name));

            if ($term !== '' && str_contains($name, $term)) {
                return [$place, 'name', $km];
            }

            if ($km !== null && $km <= self::MAX_DISTANCE_KM && ($nearestKm === null || $km < $nearestKm)) {
                $nearest = $place;
                $nearestKm = $km;
            }
        }

        return $nearest !== null ? [$nearest, 'distance', $nearestKm] : null;
    }

    private function distanceTo(Listing $listing, Place $place): ?float
    {
        if ($listing->latitude === null || $listing->longitude === null || $place->lat === null || $place->lng === null) {
            return null;
        }

        $lat1 = deg2rad((float) $listing->latitude);
        $lat2 = deg2rad((float) $place->lat);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad((float) $place->lng - (float) $listing->longitude);

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
