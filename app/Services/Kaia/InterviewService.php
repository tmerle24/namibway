<?php

namespace App\Services\Kaia;

use App\Enums\InterviewSlot;

class InterviewService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are Kaia, the AI travel companion for NamibWay — "The smartest way to experience Namibia."

        EVERY reply is a tool call. You never write a bare message; pick the tool that matches what
        the traveler wants:

        1. GENERAL QUESTIONS: If the user asks a factual question about Namibia (best travel season,
        visa requirements, road conditions, safety, wildlife, packing, budget tips, driving distances,
        etc.) — answer it with reply_to_traveler and awaiting = "none". Be concise and helpful. You
        may follow up with an offer to help plan their trip, but do not force the interview.

        2. SPECIFIC RECOMMENDATION: If the user asks for a recommendation for a specific type of
        place or experience (e.g. "recommend a lodge in Etosha", "best activity near Swakopmund",
        "where should I eat in Windhoek") — call the recommend_listing tool. Do NOT start the
        interview first.

        3. BROWSE / SEARCH: If the user wants to see a list of options (e.g. "show me lodges",
        "what activities are there in Sossusvlei") — call the trigger_listing_search tool.

        4. FULL TRIP PLANNING: If the user wants a complete multi-day itinerary planned — run the
        interview with reply_to_traveler. You need: nights, travel period, interests/style, budget
        tier, traveler count, vehicle type. Collect ONLY what is still missing — never re-ask for
        anything already stated.

        INFER aggressively: "14 days" → 13 nights (days minus one). "two weeks" → 14 nights.
        A full date range "1.8.–16.8." gives nights directly. A start month + nights = travel period.
        Never ask the user to compute or restate things you can derive yourself.

        ONE THING PER TURN. The traveler answers by tapping a suggested reply, not by typing, so a
        question must be answerable with a single tap: ask for exactly one of the fields below and
        name it in `awaiting`. Never bundle two fields into one question — half of it would then have
        nothing to tap. Ask in this order, skipping everything already known: nights, travel period,
        travelers, interests, budget tier, vehicle.

        Typical fields to collect if not yet known:
        (1) Travel period (start date or month) — awaiting "travel_period". Only if no date info yet.
        (2) Duration in nights — awaiting "nights". Only if truly absent (not inferrable).
        (3) Interests / style (wildlife, adventure, relaxation, culture, photography…) — "interests".
        (4) Budget tier (budget, mid-range, or premium) — awaiting "budget_tier".
        (5) Travelers — awaiting "travelers". Adults and children in ONE question. If ages are given
        for children, count under-13s yourself — never ask the user to recount or re-specify ages
        they already stated. E.g. "3 kids aged 13, 15, 17" → 0 under 13. "kids aged 8 and 11" → 2
        under 13. Only ask ages if children are mentioned but no ages given.
        (6) Vehicle — awaiting "vehicle_type". Regular car, 4x4, camper with rooftop tent, motorhome.
        (7) START/END LOCATION — awaiting "start_end". Do NOT ask by default. Assume Windhoek
        round-trip silently. Only ask if the user's own words imply an asymmetric route (different
        arrival/departure city, continuing to another country).

        Max 5 questions before calling ready_for_itinerary. If something is still unknown after that,
        assume a sensible default (mid-range, 2 adults, a 4x4) instead of asking a sixth time. If the
        first message already covers everything, call ready_for_itinerary immediately without asking.

        Write plain text only — no markdown, no bold, no headers, no emoji. The UI renders raw text.
        PROMPT;

    /**
     * Every conversational reply — an answer to a general question as much as
     * an interview question — comes back through this, because `awaiting` is
     * what lets the UI offer the answer as something to tap. Its enum is
     * InterviewSlot; a value outside it is dropped rather than shown, so a
     * hallucinated slot costs the traveler a set of buttons, never a wrong one.
     *
     * @return array<string, mixed>
     */
    private function replyTool(): array
    {
        return [
            'name' => 'reply_to_traveler',
            'description' => 'Say something to the traveler — an answer to a general Namibia question, or the next interview question. Always name what the reply is waiting for in `awaiting` so the app can offer it as tappable answers.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'text' => ['type' => 'string', 'description' => 'What to say, in plain text.'],
                    'awaiting' => [
                        'type' => 'string',
                        'enum' => InterviewSlot::values(),
                        'description' => 'The single interview field this reply asks for, or "none" when it asks for nothing (a general answer, or a question that has no fixed set of answers).',
                    ],
                ],
                'required' => ['text', 'awaiting'],
            ],
        ];
    }

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
                // Optional on purpose: the interview is capped at a handful of
                // questions, so this must never cost one. Set it only when the
                // traveler volunteers the detail; otherwise the trip plan's
                // vehicle picker is where they refine it, and an absent value
                // plans exactly as it did before this field existed.
                'vehicle_class' => ['type' => 'string', 'enum' => ['sedan', 'suv', 'camper_4x4', 'motorhome', 'minibus'], 'description' => 'The specific kind of vehicle, ONLY if the traveler was specific about it (e.g. "a 4x4 with a rooftop tent" -> camper_4x4, "a motorhome" -> motorhome, "something cheap for tar roads" -> sedan). Never ask a question just to fill this — omit it when in doubt.'],
                'start_location' => ['type' => 'string', 'description' => 'Where the trip starts, e.g. "Windhoek". Default to "Windhoek" unless the traveler said otherwise — do not ask for this unless a one-way trip is already implied.'],
                'end_location' => ['type' => 'string', 'description' => 'Where the trip ends, e.g. "Windhoek". Same as start_location for the common round-trip case; a different city only for a one-way trip.'],
            ],
            'required' => ['nights', 'travel_period', 'interests', 'budget_tier', 'adults', 'children_under_13', 'vehicle_type'],
        ],
    ];

    public function __construct(private readonly AnthropicClient $client) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{type: 'question', text: string, awaiting: InterviewSlot|null}|array{type: 'ready', params: array<string, mixed>}|array{type: 'search_intent', intent: array<string, mixed>}|array{type: 'recommend_intent', intent: array<string, mixed>}
     */
    public function respond(array $history, string $locale = 'en'): array
    {
        $response = $this->client->send(
            config('kaia.interview_model'),
            $this->systemPrompt($locale),
            $history,
            [$this->replyTool(), self::TOOL, self::SEARCH_TOOL, self::RECOMMEND_TOOL],
            config('kaia.interview_max_tokens'),
            // Forced, so a turn is always one of the four shapes above and
            // parsing has no prose branch to guess at. That is what makes
            // `awaiting` dependable enough to hang the tap-through flow on:
            // a reply that forgot to declare its slot would silently drop the
            // traveler back into typing.
            ['type' => 'any'],
        );

        $textParts = [];

        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $textParts[] = $block['text'];
            } elseif (($block['type'] ?? null) === 'tool_use' && $block['name'] === 'reply_to_traveler') {
                /** @var array<string, mixed> $input */
                $input = $block['input'] ?? [];

                return [
                    'type' => 'question',
                    'text' => trim((string) ($input['text'] ?? '')),
                    'awaiting' => $this->slot($input['awaiting'] ?? null),
                ];
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

        // Prose, despite the forced tool choice. Still a perfectly good answer
        // to show — it just carries no slot, so the traveler gets the text
        // field for that turn instead of buttons.
        return ['type' => 'question', 'text' => trim(implode("\n", $textParts)), 'awaiting' => null];
    }

    /**
     * "none" and an unrecognised value are the same answer to the UI — offer
     * nothing — so both come back as null rather than as a case to handle.
     */
    private function slot(mixed $value): ?InterviewSlot
    {
        $slot = is_string($value) ? InterviewSlot::tryFrom($value) : null;

        return $slot === InterviewSlot::None ? null : $slot;
    }

    private function systemPrompt(string $locale): string
    {
        $language = config("locales.labels.{$locale}", 'English');

        return self::SYSTEM_PROMPT."\n\n        IMPORTANT: Reply in {$language} ({$locale}) — every single message, ".
            'no matter what language earlier turns in the conversation happen to be in. Never switch to English '.
            "unless {$language} is English.";
    }
}
