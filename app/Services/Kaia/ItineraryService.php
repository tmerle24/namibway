<?php

namespace App\Services\Kaia;

use App\Models\Listing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ItineraryService
{
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
     * @return array{trip_summary: string, variants: array<int, array<string, mixed>>, start_location: string, end_location: string}
     */
    public function generate(array $tripParams): array
    {
        try {
            return $this->attempt($tripParams);
        } catch (RuntimeException $e) {
            Log::warning('Kaia itinerary attempt failed, retrying with a fresh conversation', [
                'reason' => $e->getMessage(),
            ]);

            return $this->attempt($tripParams);
        }
    }

    /**
     * @param  array<string, mixed>  $tripParams
     * @return array{trip_summary: string, variants: array<int, array<string, mixed>>, start_location: string, end_location: string}
     */
    private function attempt(array $tripParams): array
    {
        $listings = $this->candidateListings($tripParams);

        $messages = [
            [
                'role' => 'user',
                'content' => 'Trip parameters: '.json_encode($tripParams)
                    .PHP_EOL.PHP_EOL.'NamibWay catalog (only use listings from here): '
                    .json_encode($this->toAiCatalog($listings)),
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
        /** @var array<string, array{id: int, slug: string, name: string, type: string, price_from: ?string, price_currency: string, lat: float|null, lng: float|null}> $index */
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
                'lat' => $listing->latitude ? (float) $listing->latitude : null,
                'lng' => $listing->longitude ? (float) $listing->longitude : null,
            ];
        }

        $resolve = function (?string $name, string $type) use ($index): ?array {
            if ($name === null || $name === '') {
                return null;
            }

            return $index[$type.'|'.mb_strtolower($name)] ?? ['id' => null, 'slug' => null, 'name' => $name, 'type' => $type, 'price_from' => null, 'price_currency' => 'NAD', 'lat' => null, 'lng' => null];
        };

        $plan['variants'] = array_map(function (array $variant) use ($resolve, $listings) {
            $variant['vehicle'] = $resolve($variant['vehicle'] ?? null, 'vehicle');

            $variant['days'] = array_map(function (array $day) use ($resolve) {
                $day['accommodation'] = $resolve($day['accommodation'] ?? null, 'accommodation');
                $day['activity'] = $resolve($day['activity'] ?? null, 'activity');
                $day['restaurant'] = $resolve($day['restaurant'] ?? null, 'restaurant');

                return $day;
            }, $variant['days']);

            $variant['days'] = $this->backfillAccommodation($variant['days'], $listings);

            return $variant;
        }, $plan['variants']);

        return $plan;
    }

    /**
     * The prompt asks Claude to fill in every day's accommodation, but that's
     * a request, not a guarantee — and a blank day falls back to a coarse
     * region-centroid on the trip map, collapsing distinct legs into one
     * marker. Backfill deterministically instead of trusting the model:
     * first from a neighboring day at the same location (keeps the
     * "same lodge for several nights" pattern), then as a last resort any
     * published accommodation in that region, so every day gets a real point.
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

        $accommodationsByRegion = $listings
            ->filter(fn (Listing $listing) => $listing->type->value === 'accommodation')
            ->groupBy(fn (Listing $listing) => mb_strtolower((string) $listing->region));

        foreach ($days as &$day) {
            if ($day['accommodation'] !== null) {
                continue;
            }

            $fallback = $accommodationsByRegion->get(mb_strtolower((string) $day['location']))?->first();

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
                'lat' => $fallback->latitude ? (float) $fallback->latitude : null,
                'lng' => $fallback->longitude ? (float) $fallback->longitude : null,
            ];
        }

        unset($day);

        return $days;
    }

    /**
     * Find up to 5 published alternatives for a given listing — same type,
     * preferring same region and adjacent budget tier. No AI involved.
     *
     * @return array<int, array{id: int, slug: string|null, name: string, type: string, price_from: string|null, price_currency: string}>
     */
    public function alternatives(string $type, ?int $excludeId = null): array
    {
        $excluded = $excludeId !== null ? Listing::find($excludeId) : null;

        $pool = Listing::query()
            ->where('is_published', true)
            ->where('type', $type)
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get();

        if ($pool->isEmpty()) {
            return [];
        }

        $excludedTier = $excluded ? $this->budgetTier($excluded->price_from) : null;
        $excludedRegion = $excluded?->region;

        // Narrow: same region + adjacent budget tier
        $narrow = $pool->filter(function (Listing $listing) use ($excludedRegion, $excludedTier) {
            $regionMatch = $excludedRegion === null || $listing->region === $excludedRegion;
            $tierMatch = $excludedTier === null || $this->budgetTierDistance($listing->price_from, $excludedTier) <= 1;

            return $regionMatch && $tierMatch;
        });

        // Fallback: adjacent budget tier only (ignore region)
        if ($narrow->isEmpty() && $excludedTier !== null) {
            $narrow = $pool->filter(
                fn (Listing $listing) => $this->budgetTierDistance($listing->price_from, $excludedTier) <= 1
            );
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
            ])
            ->values()
            ->all();
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

            Build exactly 2 variants of differing budget/pace where the catalog allows it, otherwise 1 is
            fine — both variants must follow the same ROUTE instructions above; direction is handled
            separately, outside this generation step.

            For each day's "location" field, use the listing's exact "region" value — e.g. "Khomas",
            "Erongo", "Hardap", "Kunene", "Otjozondjupa", "Karas". Never use a park or tourist-area name
            (e.g. "Etosha") even if that's what the traveler said — look up which of those region values
            the chosen listing actually carries and use that instead. These values are used to draw the
            route on the trip map and must match a real region exactly.

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
                .'safari) and back — do not try to cover the whole country.',
            'medium' => 'Follow the classic Namibia loop: Khomas (Windhoek) north to Otjozondjupa '
                .'(Waterberg) and Kunene (Etosha safari), west to Erongo (Damaraland / Spitzkoppe / '
                .'Swakopmund coast), south to Hardap (Sossusvlei / Sesriem / Solitaire dunes), then back '
                .'to Khomas (Windhoek).',
            default => 'Follow the classic Namibia loop (Khomas -> Otjozondjupa -> Kunene -> Erongo -> '
                .'Hardap -> Khomas) but extend it for the extra nights: add more time in Erongo for the '
                .'Skeleton Coast, and extend south into Karas (Luderitz / Fish River Canyon) before '
                .'looping back.',
        };

        if (mb_strtolower($start) === mb_strtolower($end)) {
            return "ROUTE: the trip starts and ends in the same place (\"{$start}\") — day 1's location "
                ."and the last day's location must both be the region containing \"{$start}\". "
                ."{$loopGuidance} Never jump back and forth between distant regions; visit each region "
                .'once, in one continuous pass.';
        }

        return "ROUTE: this is a ONE-WAY trip. Day 1's location must be the region containing "
            ."\"{$start}\". The LAST day's location must be the region containing \"{$end}\" — do NOT "
            ."loop back to \"{$start}\". Use this as a guide for the middle of the trip: {$loopGuidance} "
            ."— but drop the \"back to Khomas\" leg and finish in whichever region contains \"{$end}\" "
            .'instead. Never jump back and forth between distant regions.';
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
                                            'location' => ['type' => 'string', 'description' => 'Exact region value from the listing catalog, e.g. "Khomas", "Kunene", "Erongo" — never a park/tourist-area name like "Etosha"'],
                                            'accommodation' => ['type' => 'string'],
                                            'activity' => ['type' => 'string'],
                                            'restaurant' => ['type' => 'string'],
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
                'region' => $listing->region,
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
