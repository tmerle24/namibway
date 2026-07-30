<?php

namespace App\Services\Kaia;

class InterviewService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are Kaia, the AI travel companion for NamibWay — "The smartest way to experience Namibia."

        You have four modes — pick the right one based on what the user actually wants:

        1. GENERAL QUESTIONS: If the user asks a factual question about Namibia (best travel season,
        visa requirements, road conditions, safety, wildlife, packing, budget tips, driving distances,
        etc.) — answer it directly in plain text. Be concise and helpful. You may follow up with an
        offer to help plan their trip, but do not force the interview.

        2. SPECIFIC RECOMMENDATION: If the user asks for a recommendation for a specific type of
        place or experience (e.g. "recommend a lodge in Etosha", "best activity near Swakopmund",
        "where should I eat in Windhoek") — call the recommend_listing tool. Do NOT start the
        interview first.

        3. BROWSE / SEARCH: If the user wants to see a list of options (e.g. "show me lodges",
        "what activities are there in Sossusvlei") — call the trigger_listing_search tool.

        4. FULL TRIP PLANNING: If the user wants a complete multi-day itinerary planned — run the
        interview. Collect: (1) TRAVEL DATES + DURATION as ONE combined question. Accept any form:
        a full date range (e.g. "1.8.–16.8.") gives you both — infer nights yourself. A vague period
        (e.g. "August") is fine; ask for nights once in the same follow-up if missing. Never ask the
        user to calculate or disambiguate things you can infer.
        (2) INTERESTS / STYLE (wildlife, adventure, relaxation, culture, photography…).
        (3) BUDGET TIER (budget, mid-range, or premium).
        (4) TRAVELERS — adults and whether children are joining. If children mentioned, ask once:
        how many under 13?
        (5) VEHICLE — two clear options: regular car (2WD or 4x4) or camper (rooftop tent or
        motorhome). One question, mention they can adjust later.
        Ask as few questions as possible. Combine related fields. Max 5 questions. If the first
        message answers everything, call ready_for_itinerary immediately.

        Reply in plain text only — no markdown, no bold, no headers, no emoji. The UI renders raw text.
        PROMPT;

    /** @var array<string, mixed> */
    private const RECOMMEND_TOOL = [
        'name' => 'recommend_listing',
        'description' => 'Call when the user asks for a specific recommendation for an accommodation, activity, restaurant, or vehicle in Namibia — e.g. "recommend a lodge in Etosha", "best restaurant in Windhoek". Do NOT use for browsing lists; use trigger_listing_search for that.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['accommodation', 'activity', 'restaurant', 'vehicle'], 'description' => 'The type of listing the user wants'],
                'region' => ['type' => 'string', 'description' => 'Region or location name; omit if not specified'],
                'intro' => ['type' => 'string', 'description' => 'A short 1-sentence intro to display before the recommendation card, e.g. "Here is my top pick for a lodge in Etosha:"'],
            ],
            'required' => ['intro'],
        ],
    ];

    /** @var array<string, mixed> */
    private const SEARCH_TOOL = [
        'name' => 'trigger_listing_search',
        'description' => 'Call this when the user explicitly asks to browse, search, or find listings (accommodations, activities, restaurants, or vehicles) by location, type, or keyword — i.e. they want to see a list of options rather than create a full itinerary. Do NOT call this for general itinerary planning requests.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['accommodation', 'activity', 'restaurant', 'vehicle'], 'description' => 'Listing type to filter by; omit if not specified'],
                'region' => ['type' => 'string', 'description' => 'Region or location name to filter by; omit if not specified'],
                'keyword' => ['type' => 'string', 'description' => 'Free-text keyword to search for; omit if not specified'],
                'budget' => ['type' => 'string', 'enum' => ['budget', 'mid-range', 'premium'], 'description' => 'Budget tier to filter by; omit if not specified'],
            ],
            'required' => [],
        ],
    ];

    /** @var array<string, mixed> */
    private const TOOL = [
        'name' => 'ready_for_itinerary',
        'description' => 'Call once trip length, travel period, interests, budget tier, traveler counts, and vehicle type are all known.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'nights' => ['type' => 'integer', 'description' => 'Trip length in nights'],
                'travel_period' => ['type' => 'string', 'description' => 'Specific start date or date range — e.g. "14 August 2026", "August 2026", or "15–29 August 2026". Pin to a real date, not just a season.'],
                'interests' => ['type' => 'string', 'description' => 'Travel style / interests, free text'],
                'budget_tier' => ['type' => 'string', 'enum' => ['budget', 'mid-range', 'premium']],
                'adults' => ['type' => 'integer', 'description' => 'Number of adults travelling'],
                'children_under_13' => ['type' => 'integer', 'description' => 'Number of children under 13 in the group; 0 if none or not travelling with children'],
                'vehicle_type' => ['type' => 'string', 'enum' => ['car', 'camper'], 'description' => 'car = regular 2WD or 4x4; camper = rooftop tent or motorhome'],
            ],
            'required' => ['nights', 'travel_period', 'interests', 'budget_tier', 'adults', 'children_under_13', 'vehicle_type'],
        ],
    ];

    public function __construct(private readonly AnthropicClient $client) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{type: 'question', text: string}|array{type: 'ready', params: array<string, mixed>}|array{type: 'search_intent', intent: array<string, mixed>}|array{type: 'recommend_intent', intent: array<string, mixed>}
     */
    public function respond(array $history, string $locale = 'en'): array
    {
        $response = $this->client->send(
            config('kaia.interview_model'),
            $this->systemPrompt($locale),
            $history,
            [self::TOOL, self::SEARCH_TOOL, self::RECOMMEND_TOOL],
            config('kaia.interview_max_tokens'),
        );

        $textParts = [];

        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $textParts[] = $block['text'];
            } elseif (($block['type'] ?? null) === 'tool_use' && $block['name'] === 'ready_for_itinerary') {
                return ['type' => 'ready', 'params' => $block['input']];
            } elseif (($block['type'] ?? null) === 'tool_use' && $block['name'] === 'trigger_listing_search') {
                /** @var array<string, mixed> $intent */
                $intent = $block['input'] ?? [];

                return ['type' => 'search_intent', 'intent' => $intent];
            } elseif (($block['type'] ?? null) === 'tool_use' && $block['name'] === 'recommend_listing') {
                /** @var array<string, mixed> $intent */
                $intent = $block['input'] ?? [];

                return ['type' => 'recommend_intent', 'intent' => $intent];
            }
        }

        return ['type' => 'question', 'text' => trim(implode("\n", $textParts))];
    }

    private function systemPrompt(string $locale): string
    {
        $language = config("locales.labels.{$locale}", 'English');

        return self::SYSTEM_PROMPT."\n\n        IMPORTANT: Reply in {$language} ({$locale}) — every single message, ".
            'no matter what language earlier turns in the conversation happen to be in. Never switch to English '.
            "unless {$language} is English.";
    }
}
