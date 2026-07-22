<?php

namespace App\Services\Kaia;

use App\Models\Listing;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ItineraryService
{
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
        $messages = [
            [
                'role' => 'user',
                'content' => 'Trip parameters: '.json_encode($tripParams)
                    .PHP_EOL.PHP_EOL.'NamibWay catalog (only use listings from here): '
                    .json_encode($this->catalog()),
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
                return $this->validatePlan($block['input']);
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
        ];
    }

    /**
     * The entire published catalog, deterministically fetched — small enough
     * (a few dozen listings) to hand to Claude in one shot instead of letting
     * it explore via tool calls one region/type at a time.
     *
     * @return array<int, array<string, mixed>>
     */
    private function catalog(): array
    {
        return Listing::query()
            ->where('is_published', true)
            ->get()
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
}
