<?php

namespace App\Services\Kaia;

use App\Connectors\ConnectorFactory;
use App\Connectors\ResConnect\DTOs\AvailabilityRequest;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Listing;
use App\Models\RouteTemplate;
use App\Models\RouteTemplateStop;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ItineraryService
{
    /**
     * Hard ceiling on live connector calls per generate() invocation — this
     * runs inline on the traveler's interactive chat request, not in a queued
     * job like ProcessInquiry, so a pathological plan (many distinct stays,
     * several connector-backed partners) can't turn one chat turn into a
     * multi-minute wait. Once the cap is hit, remaining stays are left
     * unchecked (assumed available) rather than blocking the response.
     */
    private const MAX_AVAILABILITY_CHECKS_PER_GENERATE = 6;

    private int $availabilityChecksUsed = 0;

    /**
     * Ordered so adjacent tiers can be included alongside an exact match —
     * a "budget" trip still sees "mid-range" candidates, just not "premium".
     *
     * @var array<int, string>
     */
    private const BUDGET_TIERS = ['budget', 'mid-range', 'premium'];

    /**
     * Cap per type regardless of catalog size — keeps the AI's input bounded
     * (cost + latency) even once the catalog grows to hundreds of listings.
     */
    private const MAX_CANDIDATES_PER_TYPE = 20;

    /**
     * Real one-way driving durations (hours) between Namibia's 14 political
     * regions, verified via OSRM against a representative town/waypoint per
     * region (Windhoek, Otjiwarongo, Okaukuejo/Etosha, Swakopmund, Sesriem,
     * Fish River Canyon area, Eenhana, Outapi, Oshakati, Tsumeb, Rundu,
     * Nkurenkuru, Katima Mulilo, Gobabis) — not left to the model's memory
     * (see CLAUDE.md: Claude is not the source of truth for Namibian driving
     * distances). Keyed alphabetically ("A|B") so lookup doesn't care about
     * direction. The original 6-region loop's pairs are hand-verified; the 8
     * later-added northern/eastern regions are OSRM-only (no current partner
     * content there — see routingGuidance(), which still only recommends the
     * original loop).
     *
     * Kaia's day-by-day `location` field and its hard driving-time safety
     * check now operate at City granularity (see the city_driving_hours DB
     * table, populated by namibway:backfill-city-driving-hours via OSRM's
     * Table API — regenerating a 100+-city equivalent of this constant by
     * hand isn't practical). This table is kept as the fallback for city
     * pairs the DB matrix doesn't cover (small settlements excluded from the
     * backfill) and to drive the macro "which regions are reachable on a
     * trip this long" prompt guidance (routingGuidance()/
     * mediumLoopGuidance()/drivingSafetyGuidance()), which stays region-level
     * since a city-level equivalent is too large to put in the prompt without
     * blowing Kaia's latency budget.
     *
     * @var array<string, float>
     */
    private const DRIVING_HOURS = [
        'Erongo|Hardap' => 4.3,
        'Erongo|Karas' => 10.2,
        'Erongo|Kavango East' => 9.1,
        'Erongo|Kavango West' => 8.7,
        'Erongo|Khomas' => 3.9,
        'Erongo|Kunene' => 5.9,
        'Erongo|Ohangwena' => 9.4,
        'Erongo|Omaheke' => 6.1,
        'Erongo|Omusati' => 8.5,
        'Erongo|Oshana' => 9.0,
        'Erongo|Oshikoto' => 6.1,
        'Erongo|Otjozondjupa' => 4.1,
        'Erongo|Zambezi' => 14.5,
        'Hardap|Karas' => 6.7,
        'Hardap|Kavango East' => 11.6,
        'Hardap|Kavango West' => 11.2,
        'Hardap|Khomas' => 4.1,
        'Hardap|Kunene' => 8.8,
        'Hardap|Ohangwena' => 11.9,
        'Hardap|Omaheke' => 6.0,
        'Hardap|Omusati' => 12.6,
        'Hardap|Oshana' => 11.7,
        'Hardap|Oshikoto' => 8.6,
        'Hardap|Otjozondjupa' => 6.6,
        'Hardap|Zambezi' => 16.9,
        'Karas|Kavango East' => 14.8,
        'Karas|Kavango West' => 14.5,
        'Karas|Khomas' => 7.3,
        'Karas|Kunene' => 12.1,
        'Karas|Ohangwena' => 15.2,
        'Karas|Omaheke' => 8.1,
        'Karas|Omusati' => 15.9,
        'Karas|Oshana' => 15.0,
        'Karas|Oshikoto' => 11.9,
        'Karas|Otjozondjupa' => 9.9,
        'Karas|Zambezi' => 20.2,
        'Kavango East|Kavango West' => 1.6,
        'Kavango East|Khomas' => 7.6,
        'Kavango East|Kunene' => 6.7,
        'Kavango East|Ohangwena' => 4.2,
        'Kavango East|Omaheke' => 7.0,
        'Kavango East|Omusati' => 5.8,
        'Kavango East|Oshana' => 5.2,
        'Kavango East|Oshikoto' => 3.5,
        'Kavango East|Otjozondjupa' => 5.0,
        'Kavango East|Zambezi' => 5.4,
        'Kavango West|Khomas' => 7.3,
        'Kavango West|Kunene' => 6.3,
        'Kavango West|Ohangwena' => 2.9,
        'Kavango West|Omaheke' => 7.4,
        'Kavango West|Omusati' => 4.4,
        'Kavango West|Oshana' => 3.8,
        'Kavango West|Oshikoto' => 2.7,
        'Kavango West|Otjozondjupa' => 4.6,
        'Kavango West|Zambezi' => 7.0,
        'Khomas|Kunene' => 4.8,
        'Khomas|Ohangwena' => 7.9,
        'Khomas|Omaheke' => 2.2,
        'Khomas|Omusati' => 8.7,
        'Khomas|Oshana' => 7.7,
        'Khomas|Oshikoto' => 4.7,
        'Khomas|Otjozondjupa' => 2.7,
        'Khomas|Zambezi' => 13.0,
        'Kunene|Ohangwena' => 5.6,
        'Kunene|Omaheke' => 6.7,
        'Kunene|Omusati' => 5.9,
        'Kunene|Oshana' => 5.4,
        'Kunene|Oshikoto' => 3.7,
        'Kunene|Otjozondjupa' => 2.2,
        'Kunene|Zambezi' => 12.1,
        'Ohangwena|Omaheke' => 8.1,
        'Ohangwena|Omusati' => 1.6,
        'Ohangwena|Oshana' => 1.1,
        'Ohangwena|Oshikoto' => 3.5,
        'Ohangwena|Otjozondjupa' => 5.3,
        'Ohangwena|Zambezi' => 9.6,
        'Omaheke|Omusati' => 8.9,
        'Omaheke|Oshana' => 7.9,
        'Omaheke|Oshikoto' => 4.9,
        'Omaheke|Otjozondjupa' => 4.6,
        'Omaheke|Zambezi' => 12.5,
        'Omusati|Oshana' => 0.9,
        'Omusati|Oshikoto' => 4.3,
        'Omusati|Otjozondjupa' => 6.0,
        'Omusati|Zambezi' => 11.2,
        'Oshana|Oshikoto' => 3.3,
        'Oshana|Otjozondjupa' => 5.1,
        'Oshana|Zambezi' => 10.6,
        'Oshikoto|Otjozondjupa' => 2.0,
        'Oshikoto|Zambezi' => 8.9,
        'Otjozondjupa|Zambezi' => 10.4,
    ];

    /**
     * Absolute hard cap the traveler asked us to enforce ("no more than 6
     * hours") — a plan with a single-day leg longer than this is treated as
     * unsafe and triggers a corrective retry rather than shipping as-is.
     */
    private const MAX_DRIVING_HOURS = 6.0;

    /** Ideal target quoted in the prompt guidance; not itself enforced. */
    private const TARGET_DRIVING_HOURS = 5.0;

    /**
     * Lazily-loaded, instance-lifetime caches — this service is resolved
     * fresh per request (no singleton binding), so caching here never leaks
     * stale data across requests while still avoiding N queries across the
     * ~30 day-pairs a single itinerary validation can touch.
     *
     * @var array<string, float>|null
     */
    private ?array $cityHoursCache = null;

    /** @var Collection<string, City>|null keyed by lowercased city name */
    private ?Collection $cityIndex = null;

    /** @var Collection<string, City>|null keyed by lowercased political region name */
    private ?Collection $regionHubCache = null;

    public function __construct(private readonly AnthropicClient $client) {}

    /**
     * Single-shot generation: the whole catalog is fetched deterministically
     * (a plain DB query, milliseconds) and handed to Claude already in
     * context, with the tool call forced — no search_listings back-and-forth.
     * The old multi-round tool-use loop cost 2-3 full network+generation
     * round-trips (10-60s total); this collapses it to exactly one call.
     *
     * Retries once with a fresh conversation on failure — Claude occasionally
     * malforms the propose_itinerary call, and a clean retry reliably
     * self-corrects rather than surfacing a one-off glitch to the traveler.
     *
     * @param  array<string, mixed>  $tripParams
     * @return array{trip_summary: string, variants: array<int, array<string, mixed>>, start_location: string, end_location: string, trip_params: array<string, mixed>}
     */
    public function generate(array $tripParams): array
    {
        $plan = $this->generateWithFreshRetry($tripParams);
        $violations = $this->validateDrivingTimes($plan);

        if ($violations !== []) {
            Log::warning('Kaia itinerary violated the driving-time safety limit, retrying with corrective feedback', [
                'violations' => $violations,
            ]);

            $plan = $this->generateWithFreshRetry($tripParams, $this->correctionMessage($violations));
            $violations = $this->validateDrivingTimes($plan);

            if ($violations !== []) {
                Log::error('Kaia itinerary still violates the driving-time safety limit after retrying', [
                    'violations' => $violations,
                ]);

                throw new RuntimeException(
                    'Could not generate a safe itinerary within the driving-time limits: '.implode('; ', $violations)
                );
            }
        }

        return $this->applyAvailabilityChecks($plan, $tripParams);
    }

    /**
     * @param  array<string, mixed>  $tripParams
     * @return array{trip_summary: string, variants: array<int, array<string, mixed>>, start_location: string, end_location: string, trip_params: array<string, mixed>}
     */
    private function generateWithFreshRetry(array $tripParams, ?string $correction = null): array
    {
        try {
            return $this->attempt($tripParams, $correction);
        } catch (RuntimeException $e) {
            Log::warning('Kaia itinerary attempt failed, retrying with a fresh conversation', [
                'reason' => $e->getMessage(),
            ]);

            return $this->attempt($tripParams, $correction);
        }
    }

    /**
     * @param  array<string, mixed>  $tripParams
     * @return array{trip_summary: string, variants: array<int, array<string, mixed>>, start_location: string, end_location: string, trip_params: array<string, mixed>}
     */
    private function attempt(array $tripParams, ?string $correction = null): array
    {
        $listings = $this->candidateListings($tripParams);
        $routeTemplates = $this->matchingRouteTemplates($tripParams);

        $userContent = 'Trip parameters: '.json_encode($tripParams)
            .PHP_EOL.PHP_EOL.'NamibWay catalog (only use listings from here): '
            .json_encode($this->toAiCatalog($listings))
            .PHP_EOL.PHP_EOL.'Candidate classic routes for this trip (see ROUTE guidance below for how '
            .'to use these): '.json_encode($routeTemplates);

        if ($correction !== null) {
            $userContent .= PHP_EOL.PHP_EOL.'IMPORTANT CORRECTION (from a previous attempt): '.$correction;
        }

        $messages = [
            [
                'role' => 'user',
                'content' => $userContent,
            ],
        ];

        $response = $this->client->send(
            config('kaia.itinerary_model'),
            $this->systemPrompt($tripParams),
            $messages,
            [$this->proposeItineraryTool()],
            config('kaia.itinerary_max_tokens'),
            ['type' => 'tool', 'name' => 'propose_itinerary'],
        );

        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'tool_use' && $block['name'] === 'propose_itinerary') {
                $plan = $this->validatePlan($block['input']);
                $plan = $this->resolveReferences($plan, $listings);

                // Echoed from the trip params (already resolved/defaulted by
                // stringParam) rather than trusted from Claude's tool call —
                // the traveler's start/end is a known fact, not something to
                // re-derive from the model.
                $plan['start_location'] = $this->stringParam($tripParams, 'start_location', 'Windhoek');
                $plan['end_location'] = $this->stringParam($tripParams, 'end_location', $plan['start_location']);

                // Same reasoning as start/end above: dates, party size and
                // travel style were already collected as ground truth during
                // the interview — echo them straight through instead of
                // making the frontend parse them back out of trip_summary's
                // free-text prose.
                $plan['trip_params'] = $this->extractTripParams($tripParams);

                return $plan;
            }
        }

        Log::warning('Kaia did not return a propose_itinerary tool call', ['response' => $response]);

        throw new RuntimeException('Kaia did not produce an itinerary.');
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{trip_summary: string, variants: array<int, array<string, mixed>>}
     */
    private function validatePlan(array $plan): array
    {
        /** @var array<int, array<string, mixed>> $variants */
        $variants = $plan['variants'] ?? [];

        $hasUsableVariants = $variants !== [];

        foreach ($variants as $variant) {
            if (($variant['days'] ?? []) === []) {
                $hasUsableVariants = false;

                break;
            }
        }

        if (! $hasUsableVariants) {
            Log::warning('Kaia produced an unusable itinerary plan', ['plan' => $plan]);

            throw new RuntimeException('Kaia produced an itinerary with no usable variants.');
        }

        return [
            'trip_summary' => (string) ($plan['trip_summary'] ?? ''),
            'variants' => $variants,
        ];
    }

    /**
     * Claude only ever returns plain listing names (keeps its job simple:
     * "which of these names fits here" rather than juggling IDs). This walks
     * the validated plan and turns each name back into a structured
     * {id, slug, name, type, price_from, price_currency} reference using the
     * same candidate listings we already fetched — no extra DB round-trip.
     * That reference is what lets the frontend link a day's accommodation
     * straight to its real detail page, show a real per-item price instead
     * of a single AI-guessed trip total, and later, what a "remove"/"swap
     * this for an alternative"/"save as draft" UI needs to act on instead of
     * a bare display string. If a name can't be matched (shouldn't happen —
     * Claude was only ever shown these names), the display text is kept but
     * the link/price is simply omitted rather than losing the content.
     *
     * @param  array{trip_summary: string, variants: array<int, array<string, mixed>>}  $plan
     * @param  Collection<int, Listing>  $listings
     * @return array{trip_summary: string, variants: array<int, array<string, mixed>>}
     */
    private function resolveReferences(array $plan, Collection $listings): array
    {
        /** @var array<string, array{id: int, slug: string, name: string, type: string, price_from: ?string, price_currency: string, duration_minutes: int|null, lat: float|null, lng: float|null, image: string|null, gallery: array<int, string>, city: string|null, short_description: string|null, rating: float|null, rating_count: int|null}> $index */
        $index = [];

        foreach ($listings as $listing) {
            $name = $listing->getTranslation('name', 'en', useFallbackLocale: true);
            $index[$listing->type->value.'|'.mb_strtolower($name)] = [
                'id' => $listing->id,
                'slug' => $listing->slug,
                'name' => $name,
                'type' => $listing->type->value,
                'price_from' => $listing->price_from,
                'price_currency' => $listing->price_currency,
                'duration_minutes' => $listing->duration_minutes,
                'lat' => $listing->latitude ? (float) $listing->latitude : null,
                'lng' => $listing->longitude ? (float) $listing->longitude : null,
                'image' => $listing->image ? Controller::resolveMediaUrl($listing->image) : null,
                'gallery' => $this->resolveGallery($listing),
                'city' => $listing->city?->name,
                'region' => $listing->region,
                // The stay card in the trip plan shows a line of "why this one"
                // detail under the name; without these it can only ever show
                // the bare listing name (see ItineraryStayCard.vue).
                'short_description' => $listing->getTranslation('short_description', 'en', useFallbackLocale: true) ?: null,
                'rating' => $listing->rating !== null ? (float) $listing->rating : null,
                'rating_count' => $listing->rating_count,
            ];
        }

        $resolve = function (?string $name, string $type) use ($index): ?array {
            if ($name === null || $name === '') {
                return null;
            }

            return $index[$type.'|'.mb_strtolower($name)] ?? ['id' => null, 'slug' => null, 'name' => $name, 'type' => $type, 'price_from' => null, 'price_currency' => 'NAD', 'duration_minutes' => null, 'lat' => null, 'lng' => null, 'image' => null, 'gallery' => [], 'city' => null, 'region' => null, 'short_description' => null, 'rating' => null, 'rating_count' => null];
        };

        $plan['variants'] = array_map(function (array $variant) use ($resolve, $listings) {
            $variant['vehicle'] = $resolve($variant['vehicle'] ?? null, 'vehicle');

            $variant['days'] = array_map(function (array $day) use ($resolve) {
                $day['accommodation'] = $resolve($day['accommodation'] ?? null, 'accommodation');
                $day['activity'] = $resolve($day['activity'] ?? null, 'activity');
                if ($day['activity'] !== null) {
                    $day['activity']['time'] = $day['activity_time'] ?? null;
                }
                $day['restaurant'] = $resolve($day['restaurant'] ?? null, 'restaurant');
                if ($day['restaurant'] !== null) {
                    $day['restaurant']['time'] = $day['restaurant_time'] ?? null;
                }

                return $day;
            }, $variant['days']);

            $variant['days'] = $this->backfillAccommodation($variant['days'], $listings);
            $variant['days'] = $this->normalizeDayLocations($variant['days']);
            $variant['days'] = $this->addDateRanges($variant['days']);

            return $variant;
        }, $plan['variants']);

        return $plan;
    }

    /**
     * Claude computes each day's check-in "date" from the travel_period, but
     * a single date per day is ambiguous about which night it covers ("28
     * Aug" — arriving that day, or already there?). Add the checkout date
     * too (next day's date, or +1 day for the final day) so the UI/PDF can
     * show an unambiguous "28 Aug – 29 Aug" range instead of one date.
     *
     * @param  array<int, array<string, mixed>>  $days
     * @return array<int, array<string, mixed>>
     */
    private function addDateRanges(array $days): array
    {
        $parsed = array_map(function (array $day) {
            $date = $day['date'] ?? null;

            if (! is_string($date) || $date === '') {
                return null;
            }

            try {
                return Carbon::createFromFormat('j M Y', $date);
            } catch (Throwable) {
                return null;
            }
        }, $days);

        foreach ($days as $i => &$day) {
            $from = $parsed[$i] ?? null;

            if ($from === null) {
                continue;
            }

            $to = $parsed[$i + 1] ?? $from->copy()->addDay();
            $day['date_to'] = $to->format('j M Y');
        }
        unset($day);

        return $days;
    }

    /**
     * The prompt asks Claude to fill in every day's accommodation, but that's
     * a request, not a guarantee — and a blank day falls back to a coarse
     * region-centroid on the trip map, collapsing distinct legs into one
     * marker. Backfill deterministically instead of trusting the model:
     * first from a neighboring day at the same location (keeps the
     * "same lodge for several nights" pattern), then as a last resort any
     * published accommodation in that city, so every day gets a real point.
     *
     * @param  array<int, array<string, mixed>>  $days
     * @param  Collection<int, Listing>  $listings
     * @return array<int, array<string, mixed>>
     */
    private function backfillAccommodation(array $days, Collection $listings): array
    {
        for ($i = 1; $i < count($days); $i++) {
            if ($days[$i]['accommodation'] === null
                && mb_strtolower((string) $days[$i]['location']) === mb_strtolower((string) $days[$i - 1]['location'])) {
                $days[$i]['accommodation'] = $days[$i - 1]['accommodation'];
            }
        }

        for ($i = count($days) - 2; $i >= 0; $i--) {
            if ($days[$i]['accommodation'] === null
                && mb_strtolower((string) $days[$i]['location']) === mb_strtolower((string) $days[$i + 1]['location'])) {
                $days[$i]['accommodation'] = $days[$i + 1]['accommodation'];
            }
        }

        $accommodationsByCity = $listings
            ->filter(fn (Listing $listing) => $listing->type->value === 'accommodation')
            ->groupBy(fn (Listing $listing) => mb_strtolower((string) $listing->city?->name));

        foreach ($days as &$day) {
            if ($day['accommodation'] !== null) {
                continue;
            }

            $fallback = $accommodationsByCity->get(mb_strtolower((string) $day['location']))?->first();

            if ($fallback === null) {
                continue;
            }

            $day['accommodation'] = [
                'id' => $fallback->id,
                'slug' => $fallback->slug,
                'name' => $fallback->getTranslation('name', 'en', useFallbackLocale: true),
                'type' => 'accommodation',
                'price_from' => $fallback->price_from,
                'price_currency' => $fallback->price_currency,
                'duration_minutes' => $fallback->duration_minutes,
                'lat' => $fallback->latitude ? (float) $fallback->latitude : null,
                'lng' => $fallback->longitude ? (float) $fallback->longitude : null,
                'image' => $fallback->image ? Controller::resolveMediaUrl($fallback->image) : null,
                'gallery' => $this->resolveGallery($fallback),
                'city' => $fallback->city?->name,
                'region' => $fallback->region,
                'short_description' => $fallback->getTranslation('short_description', 'en', useFallbackLocale: true) ?: null,
                'rating' => $fallback->rating !== null ? (float) $fallback->rating : null,
                'rating_count' => $fallback->rating_count,
            ];
        }

        unset($day);

        return $days;
    }

    /**
     * A day's `location` is what the whole trip plan calls the stage: the
     * stage card's heading, the map popup, the driving-time check. The AI
     * contract says it must be the accommodation's exact city (see
     * systemPrompt()), but a prompt instruction is a request, not a
     * guarantee — the model regularly falls back to the political region
     * ("Khomas", "Otjozondjupa") it was given as macro ROUTE guidance, and
     * a region name as a stage heading is exactly what the traveler should
     * never see. So resolve it deterministically here instead of trusting
     * the model, in decreasing order of trustworthiness:
     *
     *  1. the city of the day's own accommodation — where the traveler
     *     physically sleeps, so the truest answer for the map and for the
     *     drive from the previous day;
     *  2. `location` itself, when it already names a real city;
     *  3. the busiest city of the region `location` names, when it named a
     *     region rather than a city — a stand-in, but a real place with
     *     coordinates, which a region centroid is not.
     *
     * Also attaches the resolved city's political region as `region`, so
     * the UI can show it as a subtitle beside the city without depending on
     * the accommodation carrying a `city_id` of its own (many scraped
     * listings still don't). Anything unresolvable — a park/tourist-area
     * name the model was told not to use, a typo — is left exactly as the
     * model wrote it rather than blanked, same fail-open stance as the rest
     * of the pipeline.
     *
     * Runs after backfillAccommodation(), which still matches on the raw
     * model-written `location`, and before validateDrivingTimes(), which
     * only validates days whose location resolves to a real city.
     *
     * @param  array<int, array<string, mixed>>  $days
     * @return array<int, array<string, mixed>>
     */
    private function normalizeDayLocations(array $days): array
    {
        foreach ($days as &$day) {
            $location = (string) ($day['location'] ?? '');
            $accommodation = $day['accommodation'] ?? null;
            $accommodationCity = is_array($accommodation) ? ($accommodation['city'] ?? null) : null;

            $city = is_string($accommodationCity) ? $this->canonicalCity($accommodationCity) : null;
            $city ??= $this->canonicalCity($location);
            $city ??= $this->busiestCityOfRegion($location);

            if ($city === null) {
                $day['region'] ??= null;

                continue;
            }

            $day['location'] = $city->name;
            $day['region'] = $city->region?->name;
        }

        unset($day);

        return $days;
    }

    /**
     * The city standing in for a political region when that's all the AI
     * gave us — the one with the most published listings, i.e. the region's
     * tourism hub (Windhoek for Khomas, Swakopmund for Erongo), not an
     * arbitrary settlement that happens to sort first. Returns null for
     * anything that isn't one of the 14 region names.
     */
    private function busiestCityOfRegion(string $regionName): ?City
    {
        $name = mb_strtolower(trim($regionName));

        if ($name === '') {
            return null;
        }

        if ($this->regionHubCache === null) {
            $key = fn (City $city) => mb_strtolower((string) $city->region?->name);

            $this->regionHubCache = City::query()
                ->with('region')
                ->whereHas('region')
                ->whereHas('listings', fn ($q) => $q->where('is_published', true))
                ->withCount(['listings' => fn ($q) => $q->where('is_published', true)])
                // Name as the tiebreaker keeps the pick stable rather than
                // letting it drift with whatever order Postgres returns.
                ->orderByDesc('listings_count')
                ->orderBy('name')
                ->get()
                // unique() keeps the first of each region — the busiest city,
                // given the ordering above. keyBy() alone would keep the last.
                ->unique($key)
                ->keyBy($key);
        }

        return $this->regionHubCache->get($name);
    }

    /**
     * Find up to 5 published alternatives for a given listing — same type,
     * preferring same city, then same region, then adjacent budget tier
     * alone. No AI involved.
     *
     * @return array<int, array{id: int, slug: string|null, name: string, type: string, price_from: string|null, price_currency: string, duration_minutes: int|null, lat: float|null, lng: float|null, image: string|null, gallery: array<int, string>, city: string|null, short_description: string|null, rating: float|null, rating_count: int|null}>
     */
    public function alternatives(string $type, ?int $excludeId = null): array
    {
        $excluded = $excludeId !== null ? Listing::with('city.region')->find($excludeId) : null;

        $pool = Listing::query()
            ->where('is_published', true)
            ->where('type', $type)
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->with('city.region')
            ->get();

        if ($pool->isEmpty()) {
            return [];
        }

        $excludedTier = $excluded ? $this->budgetTier($excluded->price_from) : null;
        $excludedCity = $excluded?->city?->name;
        $excludedRegion = $excluded?->region;

        $tierMatches = fn (Listing $listing) => $excludedTier === null
            || $this->budgetTierDistance($listing->price_from, $excludedTier) <= 1;

        // Tier 1: same city + adjacent budget tier
        $narrow = $pool->filter(fn (Listing $listing) => $excludedCity !== null
            && $listing->city?->name === $excludedCity
            && $tierMatches($listing));

        // Tier 2: same region + adjacent budget tier
        if ($narrow->isEmpty()) {
            $narrow = $pool->filter(fn (Listing $listing) => $excludedRegion !== null
                && $listing->region === $excludedRegion
                && $tierMatches($listing));
        }

        // Tier 3: adjacent budget tier only (ignore city/region)
        if ($narrow->isEmpty() && $excludedTier !== null) {
            $narrow = $pool->filter($tierMatches);
        }

        $candidates = $narrow->isNotEmpty() ? $narrow : $pool;

        return $candidates
            ->take(5)
            ->map(fn (Listing $listing) => [
                'id' => $listing->id,
                'slug' => $listing->slug,
                'name' => $listing->getTranslation('name', 'en', useFallbackLocale: true),
                'type' => $listing->type->value,
                'price_from' => $listing->price_from,
                'price_currency' => $listing->price_currency,
                'duration_minutes' => $listing->duration_minutes,
                // Without coordinates a swapped-in alternative drops off the
                // trip map — every other builder of this shape carries them.
                'lat' => $listing->latitude ? (float) $listing->latitude : null,
                'lng' => $listing->longitude ? (float) $listing->longitude : null,
                'image' => $listing->image ? Controller::resolveMediaUrl($listing->image) : null,
                'gallery' => $this->resolveGallery($listing),
                'city' => $listing->city?->name,
                'region' => $listing->region,
                'short_description' => $listing->getTranslation('short_description', 'en', useFallbackLocale: true) ?: null,
                'rating' => $listing->rating !== null ? (float) $listing->rating : null,
                'rating_count' => $listing->rating_count,
            ])
            ->values()
            ->all();
    }

    /**
     * Pre-checks real-time availability for each accommodation stay against
     * its partner's live connector (ResConnect/NightsBridge/hopeCloud) and
     * swaps in a rule-based alternative — same type, same region, adjacent
     * budget tier, via alternatives() — when a stay is no longer bookable.
     * Listings whose partner has no automated connector (Manual/Wetu/NWR, or
     * simply not configured yet) can't be verified live and are left as-is,
     * exactly like today. Every connector call is wrapped to fail OPEN: any
     * error, timeout, or missing date just leaves the original suggestion in
     * place rather than breaking itinerary generation over a flaky external
     * API or a plan the AI left too vague to compute exact stay dates for.
     *
     * @param  array{trip_summary: string, variants: array<int, array<string, mixed>>, start_location: string, end_location: string, trip_params: array<string, mixed>}  $plan
     * @param  array<string, mixed>  $tripParams
     * @return array{trip_summary: string, variants: array<int, array<string, mixed>>, start_location: string, end_location: string, trip_params: array<string, mixed>}
     */
    private function applyAvailabilityChecks(array $plan, array $tripParams): array
    {
        $this->availabilityChecksUsed = 0;

        $adults = is_numeric($tripParams['adults'] ?? null) ? (int) $tripParams['adults'] : 1;
        $children = is_numeric($tripParams['children_under_13'] ?? null) ? (int) $tripParams['children_under_13'] : 0;

        $plan['variants'] = array_map(
            function (array $variant) use ($adults, $children) {
                $variant['days'] = $this->checkVariantAvailability($variant['days'] ?? [], $adults, $children);

                return $variant;
            },
            $plan['variants']
        );

        return $plan;
    }

    /**
     * @param  array<int, array<string, mixed>>  $days
     * @return array<int, array<string, mixed>>
     */
    private function checkVariantAvailability(array $days, int $adults, int $children): array
    {
        foreach ($this->groupConsecutiveStays($days) as [$startIdx, $endIdx]) {
            if ($this->availabilityChecksUsed >= self::MAX_AVAILABILITY_CHECKS_PER_GENERATE) {
                break;
            }

            $accommodation = $days[$startIdx]['accommodation'] ?? null;
            $listingId = is_numeric($accommodation['id'] ?? null) ? (int) $accommodation['id'] : null;

            if ($listingId === null) {
                continue;
            }

            $checkIn = $this->parseStayDate($days[$startIdx]['date'] ?? null);
            $checkOut = $this->parseStayDate($days[$endIdx]['date_to'] ?? null);

            if ($checkIn === null || $checkOut === null) {
                continue;
            }

            $listing = Listing::with('partner')->find((int) $listingId);

            if ($listing === null || $listing->partner === null) {
                continue;
            }

            if ($this->isAvailable($listing, $checkIn, $checkOut, $adults, $children)) {
                continue;
            }

            $replacement = $this->findAvailableAlternative($listingId, $checkIn, $checkOut, $adults, $children);

            if ($replacement === null) {
                continue;
            }

            for ($i = $startIdx; $i <= $endIdx; $i++) {
                $days[$i]['accommodation'] = $replacement;
                $days[$i]['accommodation_substituted'] = true;
                $days[$i]['accommodation_original_name'] = $accommodation['name'] ?? null;
            }
        }

        return $days;
    }

    /**
     * Groups a variant's days into [startIdx, endIdx] runs of consecutive
     * days sharing the same accommodation id — a multi-night stay only needs
     * one availability check for the whole stay, not one per night.
     *
     * @param  array<int, array<string, mixed>>  $days
     * @return array<int, array{0: int, 1: int}>
     */
    private function groupConsecutiveStays(array $days): array
    {
        $groups = [];
        $count = count($days);
        $i = 0;

        while ($i < $count) {
            $id = $days[$i]['accommodation']['id'] ?? null;

            if ($id === null) {
                $i++;

                continue;
            }

            $j = $i;

            while ($j + 1 < $count && ($days[$j + 1]['accommodation']['id'] ?? null) === $id) {
                $j++;
            }

            $groups[] = [$i, $j];
            $i = $j + 1;
        }

        return $groups;
    }

    private function parseStayDate(mixed $date): ?Carbon
    {
        if (! is_string($date) || $date === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('j M Y', $date);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Live availability for one listing/date range. Returns true (assume
     * available) whenever it can't actually be verified — no automated
     * connector configured on the partner, no property code set on this
     * specific listing yet, or the connector call itself failed — so this
     * check only ever narrows down confirmed-gone stays, never blocks on an
     * unrelated integration problem or a listing that isn't connected yet.
     */
    private function isAvailable(Listing $listing, Carbon $checkIn, Carbon $checkOut, int $adults, int $children): bool
    {
        if (blank($listing->connector_property_code) || $listing->partner === null) {
            return true;
        }

        try {
            $connector = ConnectorFactory::makeBooking($listing->partner);
        } catch (InvalidArgumentException) {
            return true;
        }

        $this->availabilityChecksUsed++;

        try {
            $response = $connector->checkAvailability(new AvailabilityRequest(
                propertyCode: $listing->connector_property_code,
                checkIn: $checkIn,
                checkOut: $checkOut,
                adults: $adults,
                children: $children,
            ));

            return $response->available;
        } catch (Throwable $e) {
            Log::warning('Kaia itinerary pre-check: availability lookup failed, assuming available', [
                'listing_id' => $listing->id,
                'partner_id' => $listing->partner->id,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Rule-based swap: walks alternatives() (same type/region/budget tier)
     * and returns the first one that is itself verifiably available, falling
     * back to the first alternative at all if none could be verified (still
     * a same-region/budget match — better than leaving a confirmed-gone
     * listing in the plan) or null if there's no alternative in the catalog.
     *
     * @return array{id: int, slug: string|null, name: string, type: string, price_from: string|null, price_currency: string, lat: float|null, lng: float|null}|null
     */
    private function findAvailableAlternative(int $excludeId, Carbon $checkIn, Carbon $checkOut, int $adults, int $children): ?array
    {
        $candidates = $this->alternatives('accommodation', $excludeId);

        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if ($this->availabilityChecksUsed >= self::MAX_AVAILABILITY_CHECKS_PER_GENERATE) {
                break;
            }

            $listing = Listing::with('partner')->find($candidate['id']);

            if ($listing?->partner === null) {
                continue;
            }

            if ($this->isAvailable($listing, $checkIn, $checkOut, $adults, $children)) {
                return $this->toAccommodationReference($listing);
            }
        }

        return $this->toAccommodationReference(Listing::find($candidates[0]['id']));
    }

    /**
     * @return array{id: int, slug: string|null, name: string, type: string, price_from: string|null, price_currency: string, lat: float|null, lng: float|null, image: string|null, gallery: array<int, string>, city: string|null, region: string|null, short_description: string|null, rating: float|null, rating_count: int|null}|null
     */
    private function toAccommodationReference(?Listing $listing): ?array
    {
        if ($listing === null) {
            return null;
        }

        return [
            'id' => $listing->id,
            'slug' => $listing->slug,
            'name' => $listing->getTranslation('name', 'en', useFallbackLocale: true),
            'type' => $listing->type->value,
            'price_from' => $listing->price_from,
            'price_currency' => $listing->price_currency,
            'lat' => $listing->latitude ? (float) $listing->latitude : null,
            'lng' => $listing->longitude ? (float) $listing->longitude : null,
            'image' => $listing->image ? Controller::resolveMediaUrl($listing->image) : null,
            'gallery' => $this->resolveGallery($listing),
            'city' => $listing->city?->name,
            'region' => $listing->region,
            'short_description' => $listing->getTranslation('short_description', 'en', useFallbackLocale: true) ?: null,
            'rating' => $listing->rating !== null ? (float) $listing->rating : null,
            'rating_count' => $listing->rating_count,
        ];
    }

    /**
     * Shared by every place that turns a Listing into the ItineraryListingRef
     * shape the frontend consumes — e.g. so RoomTypePicker's "choose a room"
     * flow has real property photos to show (there's no per-room-type photo
     * yet, see RoomType model) instead of just the single hero `image`.
     *
     * @return array<int, string>
     */
    private function resolveGallery(Listing $listing): array
    {
        return array_map(
            fn (string $path) => Controller::resolveMediaUrl($path),
            $listing->gallery ?? [],
        );
    }

    /**
     * @param  array<string, mixed>  $tripParams
     */
    private function systemPrompt(array $tripParams): string
    {
        $routeGuidance = $this->routingGuidance($tripParams);

        return <<<PROMPT
            You are Kaia, the AI travel companion for NamibWay. Build a real, bookable Namibia itinerary
            for the traveler based on the trip parameters and catalog you were given.

            You MUST base every day of the plan only on listings from the provided catalog. Never invent
            a property, activity, restaurant, or vehicle name that isn't in it.

            {$routeGuidance}

            CLASSIC ROUTES: the trip data includes a "Candidate classic routes" list. If it is non-empty,
            pick the ONE template that best matches the traveler's interests (compare against each
            template's trip_type/notes/stop highlights) and build the route by visiting its stops in the
            given order — do not reorder or skip a stop. Allocate nights to each stop within that stop's
            min_nights/max_nights range so the stops' nights sum to the trip's total night count (the
            Windhoek start/end days from the ROUTE guidance above are separate from and in addition to the
            stops). This takes priority over freely inventing your own route. If the list is empty, ignore
            this paragraph and follow the ROUTE guidance above instead. A stop's "region" says which area
            that leg covers — it is not the day's "location"; pick a city inside that region and write the
            city.

            Build exactly 1 variant — a single, focused itinerary that best fits the trip parameters. (A
            future version of this product may offer alternative variants again; for now, put all your
            effort into one excellent plan rather than splitting it across several.)

            For each day's "location" field, use the listing's exact "city" value — e.g. "Swakopmund",
            "Otjiwarongo", "Sesriem", "Windhoek". Never use a park or tourist-area name (e.g. "Etosha")
            even if that's what the traveler said — look up which of those city values the chosen listing
            actually carries and use that instead. These values are used to draw the route on the trip map
            and to check real driving times between consecutive days, so they must match a real city
            exactly — stay within the regions named in the ROUTE guidance above, but pick the specific city
            each chosen listing is actually in.

            For each day's "date" field, compute the calendar date from the trip's "travel_period" start
            date: if travel_period is "14 August 2026", day 1 is "14 Aug 2026", day 2 is "15 Aug 2026",
            and so on. If only a month is given (e.g. "August 2026"), use the 1st as day 1. Format as
            "D Mon YYYY" (e.g. "3 Aug 2026"). If the travel_period is too vague to compute a date, omit
            the date field.

            Reuse the same accommodation across multiple consecutive days when the traveler stays in one
            place for a few nights — you do NOT need a different accommodation for every day (a 14-day trip
            might only need 4-5 distinct accommodations, each covering several nights). Every single day
            MUST still have an accommodation filled in — this drives the trip map, so a blank day breaks
            it. This applies even for vehicle_type "camper": pick a specific campsite listing for every
            night, never leave accommodation blank just because the traveler brings their own gear. Only
            the activity or restaurant field may be left blank on a given day if nothing suitable is
            available.

            When an activity or restaurant naturally happens at a particular time of day (a sunrise game
            drive, a sundowner cruise, dinner), set "activity_time"/"restaurant_time" to that 24h "HH:MM"
            start time (e.g. "06:00", "19:00"). Omit these fields when no specific time applies — don't
            invent a time just to fill the field.

            The traveler's vehicle_type trip parameter is either "car" or "camper". Pick ONE vehicle listing
            per variant that matches — one whose highlights include "Camper" for vehicle_type "camper", or a
            plain self-drive vehicle otherwise. If vehicle_type is "camper", prefer accommodations whose
            highlights include "Camping" for as many nights as reasonable, since the traveler has their own
            camping gear; for vehicle_type "car", use regular lodges/guesthouses instead of camping-only
            sites. Put the chosen vehicle name in the variant's "vehicle" field (once per variant, not per
            day).

            Respond only by calling propose_itinerary — do not reply with plain text.

            Populate "trip_summary" and "variants" as separate, independent top-level fields of that tool
            call. "trip_summary" must contain ONLY the short summary paragraph — never embed the variants
            list, day-by-day plan, or any XML/tag-like markup inside it. "variants" must be a real JSON
            array value on the tool input, not a string.

            Unlike a day's "location" field (which must be the listing's exact city — see above), the
            "trip_summary" paragraph is read by the traveler and can speak more freely: parks and
            landmarks near the city, not just the city name itself (e.g. "Etosha" near Okaukuejo/Outjo,
            "Sossusvlei" near Sesriem, "the Skeleton Coast" near Swakopmund) — say what's actually there for
            the traveler, not just the town they're staying in.

            All text fields in the final plan (trip_summary, location, accommodation, activity, restaurant,
            vehicle) must be plain text — no markdown formatting or emoji.
            PROMPT;
    }

    /**
     * Namibia's road-trip geography is a hard fact, not something to leave to
     * the model's judgment call by call (see CLAUDE.md: Claude should not be
     * the source of truth for Namibian logistics). Most travelers fly into
     * and out of Windhoek and drive the same classic loop; only ~10-15% do a
     * one-way. This turns start_location/end_location plus trip length into
     * a concrete routing instruction instead of the old vague "sequence
     * sensibly" sentence.
     *
     * @param  array<string, mixed>  $tripParams
     */
    private function routingGuidance(array $tripParams): string
    {
        $start = $this->stringParam($tripParams, 'start_location', 'Windhoek');
        $end = $this->stringParam($tripParams, 'end_location', $start);
        $nights = is_numeric($tripParams['nights'] ?? null) ? (int) $tripParams['nights'] : null;

        $bucket = match (true) {
            $nights === null => 'medium',
            $nights <= 7 => 'short',
            $nights <= 16 => 'medium',
            default => 'long',
        };

        $loopGuidance = match ($bucket) {
            'short' => 'Keep it simple for a short trip: out from Khomas (Windhoek) to Kunene (Etosha '
                .'safari) and back — do not try to cover the whole country. Regions to use: Khomas, '
                .'Otjozondjupa, Kunene only. Do NOT use Karas (Luderitz / Fish River Canyon) or go all '
                .'the way to Erongo/Hardap — there is not enough time to reach them and drive back safely.',
            'medium' => $this->mediumLoopGuidance($nights),
            // Every DRIVING_HOURS pair touching Karas exceeds MAX_DRIVING_HOURS
            // (closest is Hardap|Karas at 6.7h) — it cannot be reached from or
            // returned to any of the other 5 regions within the daily limit, so
            // it is never safe to route there regardless of trip length. Depth,
            // not breadth, is what extra nights on a long trip should buy.
            default => 'Follow the classic Namibia loop (Khomas -> optionally Otjozondjupa -> Kunene -> '
                .'Erongo -> Hardap -> Khomas) but extend it for the extra nights by going deeper, not '
                .'wider: more time in Kunene for Etosha, more time in Erongo for the Skeleton Coast and '
                .'Damaraland, more time in Hardap for Sossusvlei and Naukluft. Regions to use: Khomas, '
                .'Otjozondjupa, Kunene, Erongo, Hardap only. Do NOT use Karas (Luderitz / Fish River '
                .'Canyon) — it cannot be reached from and returned to this loop within the daily '
                .'driving-time limit, no matter how many nights the trip has.',
        };

        $safety = $this->drivingSafetyGuidance();

        // Region names below name AREAS to route through, never a day's
        // "location" — that stays the listing's exact city (see
        // systemPrompt()). Spelled out at the end of both branches because
        // this is the one place in the prompt where regions are discussed at
        // length, and the model used to copy them straight into `location`.
        $granularity = 'The region names above are only there to say WHICH AREAS the trip passes '
            .'through. They are never a valid value for a day\'s "location" field — that must always '
            .'be the exact city of that day\'s accommodation (e.g. "Windhoek", not "Khomas"; '
            .'"Otjiwarongo", not "Otjozondjupa").';

        if (mb_strtolower($start) === mb_strtolower($end)) {
            $dayCount = $nights !== null
                ? "DAY COUNT: this is a {$nights}-night round trip, so produce EXACTLY ".($nights + 1)
                    .' day entries, numbered 1 to '.($nights + 1).'. Day 1 and the FINAL day (day '
                    .($nights + 1).') must both be in or next to "'.$start.'" — the final day '
                    .'is the return/drop-off day, so it is fine (and often correct) for it to reuse the '
                    ."previous day's accommodation rather than needing a new one. Do not pad the ending "
                    .'with a redundant extra night in one place just to fill the count — use the nights '
                    .'productively for touring and let the final day be the short return leg.'
                : '';

            return "ROUTE: the trip starts and ends in the same place (\"{$start}\") — day 1's location "
                ."and the last day's location must both be \"{$start}\" itself, or a city close enough "
                ."to it to reach the departure point comfortably. {$loopGuidance} Never jump back and "
                .'forth between distant regions; visit each region once, in one continuous pass.'
                ."\n\n{$dayCount}\n\n{$granularity}\n\n{$safety}";
        }

        return "ROUTE: this is a ONE-WAY trip. Day 1's location must be \"{$start}\" itself, or a city "
            ."close to it. The LAST day's location must be \"{$end}\" itself, or a city close to it — do "
            ."NOT loop back to \"{$start}\". Use this as a guide for the middle of the trip: "
            ."{$loopGuidance} — but drop the \"back to Khomas\" leg and finish in whichever region "
            ."contains \"{$end}\" instead. Never jump back and forth between distant regions."
            ."\n\n{$granularity}\n\n{$safety}";
    }

    /**
     * Fallback used only when matchingRouteTemplates() has nothing for this
     * night count (sparse content, or before any templates existed). The
     * classic loop's regions (Khomas/Otjozondjupa/Kunene/Erongo/Hardap)
     * comfortably fit an 8-16 night trip; Karas (Luderitz / Fish River
     * Canyon) does not — the Hardap<->Karas and Karas<->Khomas legs each
     * exceed the daily driving-time cap, so a medium-length trip that wanders
     * down there is unsafe by construction, not just a style choice. Earlier
     * wording only described where TO go and relied on the model inferring
     * Karas was out of scope; in practice it occasionally added it anyway,
     * which produced an itinerary validateDrivingTimes() then rejected,
     * burning the one corrective retry generate() gets before giving up
     * entirely. Naming the excluded region explicitly closes that gap.
     *
     * A generous medium trip (11+ nights) has enough slack to actually visit
     * the loop's real highlights rather than just passing through their
     * region, so that tier commits to them by name instead of leaving
     * Waterberg as a lower-priority "nice to have".
     */
    private function mediumLoopGuidance(?int $nights): string
    {
        $base = 'Follow the classic Namibia loop: Khomas (Windhoek) north toward Kunene (Etosha safari), '
            .'west to Erongo (Damaraland / Spitzkoppe / Skeleton Coast / Swakopmund coast), south to '
            .'Hardap (Sossusvlei / Sesriem / Solitaire dunes / Naukluft), then back to Khomas (Windhoek). '
            .'Regions to use for this trip: Khomas, Otjozondjupa, Kunene, Erongo, Hardap only. Do NOT use '
            .'Karas (Luderitz / Fish River Canyon) — it cannot be reached from and returned to this loop '
            .'within the daily driving-time limit, so it is reserved for longer trips only.';

        if ($nights !== null && $nights >= 11) {
            return $base.' With this many nights there is time to do the loop properly rather than just '
                .'passing through it: include a night in Otjozondjupa (Waterberg) on the way up or back, '
                .'and give Erongo and Hardap at least 2-3 nights each so the traveler actually experiences '
                .'Spitzkoppe, the Skeleton Coast, Swakopmund, Sossusvlei and the Naukluft area rather than '
                .'a single fast pass through each region.';
        }

        return $base.' Optionally stop a night in Otjozondjupa (Waterberg) on the way up or back if there '
            .'is time for it (nice-to-have, not mandatory).';
    }

    /**
     * Real per-leg driving durations (see DRIVING_HOURS) plus the traveler's
     * hard safety rules, turned into concrete prompt guidance — Claude gets
     * actual hours to reason with instead of guessing Namibian geography
     * from memory (see CLAUDE.md). Backstopped by validateDrivingTimes()
     * after generation, since a prompt instruction alone is a request, not
     * a guarantee.
     */
    private function drivingSafetyGuidance(): string
    {
        $table = collect(self::DRIVING_HOURS)
            ->map(fn (float $hours, string $pair) => str_replace('|', '-', $pair)." ~{$hours}h")
            ->implode(', ');

        $max = self::MAX_DRIVING_HOURS;
        $target = self::TARGET_DRIVING_HOURS;

        return <<<TEXT
            DRIVING SAFETY (hard rules — Namibia's roads are long and this is a real, bookable plan, not
            just a suggestion): Real one-way driving times between regions: {$table}. Never schedule more
            than {$max} hours of driving to reach a new region in a single day — target {$target}h or
            less. If the direct leg between two consecutive regions in your plan would take longer than
            {$max} hours, do NOT attempt it in one day: keep the traveler ONE EXTRA NIGHT in the region
            they are departing from (repeat the previous accommodation) before continuing, splitting the
            drive across two days instead. Assume driving only happens between 07:30 and 18:30 — never
            imply a leg that would require departing before dawn or arriving after dusk. Every plan with
            a driving day must leave the traveler time to refuel before the tank runs low and to handle a
            flat tire — never schedule long driving legs back-to-back with no rest day between them.
            TEXT;
    }

    /**
     * Backstop for drivingSafetyGuidance() — a prompt instruction is a
     * request, not a guarantee. Walks each variant's days and flags any
     * single-day city change whose real driving time exceeds the hard cap,
     * so generate() can retry with corrective feedback instead of silently
     * shipping a plan that would require illegal/unsafe driving.
     *
     * @param  array{variants: array<int, array<string, mixed>>}  $plan
     * @return array<int, string>
     */
    private function validateDrivingTimes(array $plan): array
    {
        $violations = [];

        foreach ($plan['variants'] as $variant) {
            $prevDay = null;
            $prevCity = null;

            foreach ($variant['days'] ?? [] as $day) {
                $city = $this->canonicalCity((string) ($day['location'] ?? ''));

                if ($city === null) {
                    continue;
                }

                if ($prevCity !== null && $prevCity->id !== $city->id) {
                    $hours = $this->drivingHours($prevCity, $city);

                    if ($hours !== null && $hours > self::MAX_DRIVING_HOURS) {
                        $violations[] = "Day {$prevDay}\u{2192}{$day['day']}: {$prevCity->name} \u{2192} "
                            ."{$city->name} is ~{$hours}h driving, over the ".self::MAX_DRIVING_HOURS
                            .'h daily limit';
                    }
                }

                $prevCity = $city;
                $prevDay = $day['day'] ?? $prevDay;
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * @param  array<int, string>  $violations
     */
    private function correctionMessage(array $violations): string
    {
        return 'Your previous itinerary broke the daily driving-time safety limit on these legs: '
            .implode('; ', $violations).'. Fix this by keeping the traveler an extra night in the '
            .'city they are departing from before continuing (splitting the drive across two days), '
            .'not by attempting the whole distance in a single day. Re-plan the full itinerary with this '
            .'correction — keep the total day count and start/end locations the same.';
    }

    /**
     * Looks up the real driving time between two cities. Tries the
     * city_driving_hours DB table first (real OSRM data, see
     * OsrmDrivingTimeService / namibway:backfill-city-driving-hours). If the
     * pair isn't covered there (e.g. one of the cities is a small settlement
     * excluded from that backfill), falls back to the region-level
     * DRIVING_HOURS table — but only when the two cities are in different
     * regions; a same-region pair missing from the city matrix returns null
     * ("can't validate, skip") rather than fabricating an intra-region
     * estimate, consistent with the existing null-skip semantics for any
     * unknown pair.
     */
    private function drivingHours(City $a, City $b): ?float
    {
        if ($a->id === $b->id) {
            return 0.0;
        }

        $cityHours = $this->cityDrivingHours($a->id, $b->id);

        if ($cityHours !== null) {
            return $cityHours;
        }

        if ($a->region_id === $b->region_id || $a->region === null || $b->region === null) {
            return null;
        }

        return $this->regionDrivingHours($a->region->name, $b->region->name);
    }

    /**
     * @return array<string, float>
     */
    private function cityHoursIndex(): array
    {
        if ($this->cityHoursCache === null) {
            $this->cityHoursCache = DB::table('city_driving_hours')
                ->get(['city_a_id', 'city_b_id', 'hours'])
                ->mapWithKeys(fn ($row) => ["{$row->city_a_id}|{$row->city_b_id}" => (float) $row->hours])
                ->all();
        }

        return $this->cityHoursCache;
    }

    private function cityDrivingHours(int $cityAId, int $cityBId): ?float
    {
        $a = min($cityAId, $cityBId);
        $b = max($cityAId, $cityBId);

        return $this->cityHoursIndex()["{$a}|{$b}"] ?? null;
    }

    /**
     * Looks up the real driving time between two regions regardless of
     * argument order (DRIVING_HOURS is keyed alphabetically). Returns null
     * only when the pair isn't in the table (shouldn't happen for any of
     * the 14 known regions) — callers treat null as "can't validate, skip".
     */
    private function regionDrivingHours(string $a, string $b): ?float
    {
        if ($a === $b) {
            return 0.0;
        }

        $key = $a < $b ? "{$a}|{$b}" : "{$b}|{$a}";

        return self::DRIVING_HOURS[$key] ?? null;
    }

    /**
     * Maps a possibly loosely-cased/whitespaced city string from the AI's
     * `location` output back to the real City it names — the region-level
     * fallback inside drivingHours() reads region_id/region straight off
     * the returned model, so this returns a City, not just a canonical
     * string (unlike the old region-string version this replaced). Returns
     * null for anything that isn't a real city (park/tourist-area names
     * Claude was told not to use, typos, etc.) — that day is simply skipped
     * from driving-time validation.
     */
    private function canonicalCity(string $name): ?City
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        if ($this->cityIndex === null) {
            $this->cityIndex = City::query()->with('region')->get()->keyBy(fn (City $city) => mb_strtolower($city->name));
        }

        return $this->cityIndex->get(mb_strtolower($name));
    }

    /**
     * @param  array<string, mixed>  $tripParams
     */
    private function stringParam(array $tripParams, string $key, string $default): string
    {
        $value = $tripParams[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * @param  array<string, mixed>  $tripParams
     * @return array{nights: int|null, travel_period: string, interests: string, adults: int, children_under_13: int, children_ages: string|null, vehicle_type: string, budget_tier: string}
     */
    private function extractTripParams(array $tripParams): array
    {
        $childrenAges = $tripParams['children_ages'] ?? null;

        return [
            'nights' => is_numeric($tripParams['nights'] ?? null) ? (int) $tripParams['nights'] : null,
            'travel_period' => $this->stringParam($tripParams, 'travel_period', ''),
            'interests' => $this->stringParam($tripParams, 'interests', ''),
            'adults' => is_numeric($tripParams['adults'] ?? null) ? (int) $tripParams['adults'] : 1,
            'children_under_13' => is_numeric($tripParams['children_under_13'] ?? null) ? (int) $tripParams['children_under_13'] : 0,
            'children_ages' => is_string($childrenAges) && $childrenAges !== '' ? $childrenAges : null,
            'vehicle_type' => $this->stringParam($tripParams, 'vehicle_type', ''),
            'budget_tier' => $this->stringParam($tripParams, 'budget_tier', ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function proposeItineraryTool(): array
    {
        return [
            'name' => 'propose_itinerary',
            'description' => 'Submit the final structured itinerary.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'trip_summary' => ['type' => 'string'],
                    'variants' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'vehicle' => ['type' => 'string', 'description' => 'The one vehicle for the whole trip, not per day'],
                                'days' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'day' => ['type' => 'integer'],
                                            'date' => ['type' => 'string', 'description' => 'Calendar date for this day, e.g. "14 Aug 2026". Computed from travel_period start date.'],
                                            'location' => ['type' => 'string', 'description' => 'Exact city value from the listing catalog, e.g. "Swakopmund", "Otjiwarongo", "Sesriem" — never a park/tourist-area name like "Etosha"'],
                                            'accommodation' => ['type' => 'string'],
                                            'activity' => ['type' => 'string'],
                                            'activity_time' => ['type' => 'string', 'description' => 'Optional 24h "HH:MM" start time for the activity, e.g. "06:00" for a sunrise game drive. Omit if no particular time of day applies.'],
                                            'restaurant' => ['type' => 'string'],
                                            'restaurant_time' => ['type' => 'string', 'description' => 'Optional 24h "HH:MM" start time for the restaurant, e.g. "19:00" for dinner. Omit if no particular time of day applies.'],
                                        ],
                                        'required' => ['day', 'location'],
                                    ],
                                ],
                            ],
                            'required' => ['name', 'days'],
                        ],
                    ],
                ],
                'required' => ['trip_summary', 'variants'],
            ],
        ];
    }

    /**
     * A deterministically pre-filtered slice of the catalog — plain indexed
     * SQL, not AI. This is what keeps cost and latency flat as the catalog
     * grows from a few dozen listings today to hundreds or thousands later:
     * Claude never sees the whole catalog, only a bounded shortlist already
     * narrowed by hard constraints (budget tier, vehicle type). Semantic
     * matching (e.g. "interests" against descriptions) stays with the AI —
     * that needs judgment; region/price/vehicle-type don't.
     *
     * @param  array<string, mixed>  $tripParams
     * @return Collection<int, Listing>
     */
    private function candidateListings(array $tripParams): Collection
    {
        $requestedTier = is_string($tripParams['budget_tier'] ?? null) ? $tripParams['budget_tier'] : null;
        $vehicleType = is_string($tripParams['vehicle_type'] ?? null) ? $tripParams['vehicle_type'] : null;

        return Listing::query()
            ->where('is_published', true)
            ->with('city.region')
            ->get()
            ->groupBy(fn (Listing $listing) => $listing->type->value)
            ->flatMap(function ($listings, string $type) use ($requestedTier, $vehicleType) {
                if ($type === 'vehicle' && $vehicleType !== null) {
                    $listings = $listings->filter(
                        fn (Listing $listing) => $this->isCamper($listing) === ($vehicleType === 'camper')
                    );
                } elseif ($type !== 'vehicle' && $requestedTier !== null) {
                    $listings = $listings->filter(
                        fn (Listing $listing) => $this->budgetTierDistance($listing->price_from, $requestedTier) <= 1
                    );
                }

                return $listings->take(self::MAX_CANDIDATES_PER_TYPE);
            })
            ->values();
    }

    /**
     * @param  Collection<int, Listing>  $listings
     * @return array<int, array<string, mixed>>
     */
    private function toAiCatalog(Collection $listings): array
    {
        return $listings
            ->map(fn (Listing $listing) => [
                'name' => $listing->getTranslation('name', 'en', useFallbackLocale: true),
                'type' => $listing->type->value,
                // Both sent: 'region' satisfies the macro ROUTE guidance ("which regions to
                // visit"), 'city' is what the day-by-day "location" field must be filled with.
                'region' => $listing->region,
                'city' => $listing->city?->name,
                'description' => $listing->getTranslation('description', 'en', useFallbackLocale: true),
                'highlights' => $listing->getTranslation('highlights', 'en', useFallbackLocale: true) ?? [],
                'price_from' => $listing->price_from,
                'price_currency' => $listing->price_currency,
            ])
            ->values()
            ->all();
    }

    private function isCamper(Listing $listing): bool
    {
        return in_array('Camper', $listing->getTranslation('highlights', 'en', useFallbackLocale: true) ?? [], true);
    }

    /**
     * Deterministic pre-filter, same spirit as candidateListings(): total
     * night count is a hard feasibility fact (like region/price/vehicle-type
     * there), so it's filtered in code, not left for the model to judge.
     * Which of the matches best fits the traveler's free-text interests is
     * genuinely a judgment call, so that part stays with the AI — it gets
     * every matching template's trip_type/highlights/notes as context and
     * picks one itself (see systemPrompt()'s ROUTE guidance).
     *
     * Round-trip only for now (see plan): a one-way trip's route varies too
     * much end-to-end to usefully template, so this returns empty for those
     * and generation falls through to the freeform ROUTE guidance instead —
     * exactly like it does when no template's night range matches either.
     *
     * @param  array<string, mixed>  $tripParams
     * @return array<int, array<string, mixed>>
     */
    private function matchingRouteTemplates(array $tripParams): array
    {
        $start = $this->stringParam($tripParams, 'start_location', 'Windhoek');
        $end = $this->stringParam($tripParams, 'end_location', $start);

        if (mb_strtolower($start) !== mb_strtolower($end)) {
            return [];
        }

        $nights = is_numeric($tripParams['nights'] ?? null) ? (int) $tripParams['nights'] : null;

        if ($nights === null) {
            return [];
        }

        return RouteTemplate::query()
            ->where('is_published', true)
            ->where('min_nights', '<=', $nights)
            ->where('max_nights', '>=', $nights)
            ->with('stops')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (RouteTemplate $template) => [
                'name' => $template->getTranslation('name', 'en', useFallbackLocale: true),
                'trip_type' => $template->trip_type->value,
                'notes' => $template->notes,
                'stops' => $template->stops->map(fn (RouteTemplateStop $stop) => [
                    'region' => $stop->region,
                    'min_nights' => $stop->min_nights,
                    'max_nights' => $stop->max_nights,
                    'highlights' => $stop->highlights,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    private function budgetTierDistance(?string $price, string $requestedTier): int
    {
        $tier = $this->budgetTier($price);

        if ($tier === null) {
            return 0;
        }

        $requestedIndex = array_search($requestedTier, self::BUDGET_TIERS, true);
        $tierIndex = array_search($tier, self::BUDGET_TIERS, true);

        if ($requestedIndex === false || $tierIndex === false) {
            return 0;
        }

        return abs($requestedIndex - $tierIndex);
    }

    private function budgetTier(?string $price): ?string
    {
        if ($price === null) {
            return null;
        }

        $value = (float) $price;

        return match (true) {
            $value < 150 => 'budget',
            $value <= 400 => 'mid-range',
            default => 'premium',
        };
    }
}
