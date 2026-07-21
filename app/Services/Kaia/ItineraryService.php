<?php

namespace App\Services\Kaia;

use App\Models\Listing;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ItineraryService
{
    private const MAX_TOOL_ROUNDS = 10;

    public function __construct(private readonly AnthropicClient $client) {}

    /**
     * Retries once with a fresh conversation on failure — Claude occasionally
     * malforms the propose_itinerary call (e.g. embedding the variants list
     * as text instead of populating the real field), and a clean retry
     * reliably self-corrects rather than surfacing a one-off glitch to the
     * traveler.
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
        $messages = [
            [
                'role' => 'user',
                'content' => 'Trip parameters: '.json_encode($tripParams),
            ],
        ];

        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $response = $this->client->send(
                config('kaia.itinerary_model'),
                $this->systemPrompt(),
                $messages,
                $this->tools(),
                config('kaia.itinerary_max_tokens'),
            );

            $content = $response['content'] ?? [];
            $messages[] = ['role' => 'assistant', 'content' => $content];

            $toolResults = [];

            foreach ($content as $block) {
                if (($block['type'] ?? null) !== 'tool_use') {
                    continue;
                }

                if ($block['name'] === 'propose_itinerary') {
                    return $this->validatePlan($block['input']);
                }

                if ($block['name'] === 'search_listings') {
                    $toolResults[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $block['id'],
                        'content' => json_encode($this->searchListings($block['input'])),
                    ];
                }
            }

            if ($toolResults === []) {
                break;
            }

            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        Log::warning('Kaia exhausted tool-use rounds without proposing an itinerary', [
            'rounds' => self::MAX_TOOL_ROUNDS,
            'last_content' => $content,
        ]);

        throw new RuntimeException('Kaia did not produce an itinerary within the allowed tool-use rounds.');
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

    private function systemPrompt(): string
    {
        $regions = implode(', ', config('kaia.regions'));

        return <<<PROMPT
            You are Kaia, the AI travel companion for NamibWay. Build a real, bookable Namibia itinerary
            for the traveler based on the trip parameters you were given.

            Use the search_listings tool to find real accommodations, activities, restaurants, and vehicles —
            you MUST base every day of the plan only on listings returned by search_listings. Never invent
            a property, activity, restaurant, or vehicle name that wasn't returned by the tool. Call
            search_listings as many times as you need (different types/regions) before building the plan.

            The only valid values for the "region" search parameter are: {$regions}. These are Namibia's
            political regions, not tourist areas or town names — e.g. search "Hardap" for Sossusvlei,
            "Kunene" for Etosha/Damaraland, "Erongo" for Swakopmund, "Khomas" for Windhoek.

            Sequence days in a sensible geographic order using the "region" field of each listing — don't
            jump back and forth between distant regions. Build exactly 2 variants of differing budget/pace
            where the available listings allow it, otherwise 1 is fine.

            Reuse the same accommodation across multiple consecutive days when the traveler stays in one
            place for a few nights — you do NOT need a different accommodation for every day (a 14-day trip
            might only need 4-5 distinct accommodations, each covering several nights). Only search once per
            region/type combination; do not repeat a search you already made. It's fine to leave the
            activity or restaurant field blank on a given day if nothing suitable is available.

            The traveler's vehicle_type trip parameter is either "car" or "camper". Search type "vehicle"
            once (region "Khomas", where rentals are picked up in Windhoek) and pick ONE vehicle per variant
            that matches — a listing whose highlights include "Camper" for vehicle_type "camper", or a plain
            self-drive vehicle otherwise. If vehicle_type is "camper", prefer accommodations whose highlights
            include "Camping" for as many nights as reasonable, since the traveler has their own camping
            gear; for vehicle_type "car", use regular lodges/guesthouses instead of camping-only sites.
            Put the chosen vehicle name in the variant's "vehicle" field (once per variant, not per day).

            Once you have enough listings, call propose_itinerary with the final structured plan. Do not
            reply with plain text — the only valid final output is a propose_itinerary tool call.

            Populate "trip_summary" and "variants" as separate, independent top-level fields of that tool
            call. "trip_summary" must contain ONLY the short summary paragraph — never embed the variants
            list, day-by-day plan, or any XML/tag-like markup inside it. "variants" must be a real JSON
            array value on the tool input, not a string.

            All text fields in the final plan (trip_summary, location, accommodation, activity, restaurant,
            vehicle) must be plain text — no markdown formatting or emoji.
            PROMPT;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tools(): array
    {
        return [
            [
                'name' => 'search_listings',
                'description' => 'Search NamibWay\'s real listing catalog for accommodations, activities, restaurants, or vehicles.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['accommodation', 'activity', 'restaurant', 'vehicle']],
                        'region' => ['type' => 'string', 'enum' => config('kaia.regions')],
                        'budget_tier' => ['type' => 'string', 'enum' => ['budget', 'mid-range', 'premium']],
                    ],
                    'required' => ['type'],
                ],
            ],
            [
                'name' => 'propose_itinerary',
                'description' => 'Submit the final structured itinerary once enough real listings have been found.',
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
                                    'estimated_total_usd' => ['type' => 'number'],
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
                                'required' => ['name', 'estimated_total_usd', 'days'],
                            ],
                        ],
                    ],
                    'required' => ['trip_summary', 'variants'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<int, array<string, mixed>>
     */
    private function searchListings(array $input): array
    {
        $query = Listing::query()->where('is_published', true);

        if (isset($input['type'])) {
            $query->where('type', $input['type']);
        }

        if (isset($input['region'])) {
            $query->where('region', $input['region']);
        }

        return $query->limit(8)->get()
            ->filter(fn (Listing $listing) => ! isset($input['budget_tier']) || $this->budgetTier($listing->price_from) === $input['budget_tier'])
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
