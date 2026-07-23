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
     * @return array{trip_summary: string, variants: array<int, array<string, mixed>>}
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
     * @return array{trip_summary: string, variants: array<int, array<string, mixed>>}
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
            $this->systemPrompt(),
            $messages,
            [$this->proposeItineraryTool()],
            config('kaia.itinerary_max_tokens'),
            ['type' => 'tool', 'name' => 'propose_itinerary'],
        );

        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'tool_use' && $block['name'] === 'propose_itinerary') {
                $plan = $this->validatePlan($block['input']);

                return $this->resolveReferences($plan, $listings);
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
        /** @var array<string, array{id: int, slug: string, name: string, type: string, price_from: ?string, price_currency: string}> $index */
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
            ];
        }

        $resolve = function (?string $name, string $type) use ($index): ?array {
            if ($name === null || $name === '') {
                return null;
            }

            return $index[$type.'|'.mb_strtolower($name)] ?? ['id' => null, 'slug' => null, 'name' => $name, 'type' => $type, 'price_from' => null, 'price_currency' => 'NAD'];
        };

        $plan['variants'] = array_map(function (array $variant) use ($resolve) {
            $variant['vehicle'] = $resolve($variant['vehicle'] ?? null, 'vehicle');

            $variant['days'] = array_map(function (array $day) use ($resolve) {
                $day['accommodation'] = $resolve($day['accommodation'] ?? null, 'accommodation');
                $day['activity'] = $resolve($day['activity'] ?? null, 'activity');
                $day['restaurant'] = $resolve($day['restaurant'] ?? null, 'restaurant');

                return $day;
            }, $variant['days']);

            return $variant;
        }, $plan['variants']);

        return $plan;
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

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            You are Kaia, the AI travel companion for NamibWay. Build a real, bookable Namibia itinerary
            for the traveler based on the trip parameters and catalog you were given.

            You MUST base every day of the plan only on listings from the provided catalog. Never invent
            a property, activity, restaurant, or vehicle name that isn't in it.

            Sequence days in a sensible geographic order using each listing's "region" field — don't jump
            back and forth between distant regions. Build exactly 2 variants of differing budget/pace where
            the catalog allows it, otherwise 1 is fine.

            Reuse the same accommodation across multiple consecutive days when the traveler stays in one
            place for a few nights — you do NOT need a different accommodation for every day (a 14-day trip
            might only need 4-5 distinct accommodations, each covering several nights). It's fine to leave
            the activity or restaurant field blank on a given day if nothing suitable is available.

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
                                            'location' => ['type' => 'string'],
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
