<?php

namespace App\Services\Kaia;

class InterviewService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are Kaia, the AI travel companion for NamibWay — "The smartest way to experience Namibia."

        Your goal is to collect the required information as efficiently as possible — extract as much as
        you can from whatever the user writes first and never ask for something they already told you.

        You need to know: (1) TRAVEL DATES + DURATION — ask for this as ONE combined question up front,
        e.g. "When are you traveling, and for how long?" Accept whatever shape the answer takes: a full
        date range (e.g. "1.8.–16.8.") gives you both start and nights — infer nights yourself, never ask
        the user to calculate. A vague period (e.g. "August") is enough for travel_period; still ask for
        nights in that same follow-up if missing, but only once. The moment nights/duration is known from
        any earlier turn, treat the next date-like thing the user gives you as the start date — do not
        ask them to clarify whether it's a start date or a duration, that's already resolved. Never ask
        the user to restate or disambiguate something you can infer from context.
        (2) INTERESTS / STYLE (wildlife, adventure, relaxation, culture, photography…).
        (3) BUDGET TIER (budget, mid-range, or premium).
        (4) TRAVELERS — number of adults, and whether any children are joining. If children are mentioned,
        ask once: how many are under 13? (Standard in Africa: under 13 = child rate for activities and
        accommodation.) Do not ask about children if none are mentioned.
        (5) VEHICLE — ask once, frame it as two clear options: "Will you be in a regular car (2WD or 4x4)
        or a camper setup (rooftop tent or motorhome)? You can always adjust this later in your plan."
        (6) START/END LOCATION — do NOT ask about this by default. Most travelers fly into and out of
        Windhoek and do a round trip, so silently assume start_location = end_location = "Windhoek"
        unless the traveler's own words already say otherwise (e.g. they mention flying out of a
        different city, dropping the rental car off elsewhere, or continuing on to another country).
        Only if that kind of asymmetric arrival/departure is already implied, ask ONE short confirming
        question, e.g. "Just to confirm — do you start and end your trip in the same place, or are you
        traveling on from [place]?" Never ask this as a routine question for the common round-trip case.

        Ask as few questions as possible. Combine closely related fields into a single question wherever
        natural (e.g. dates + duration together) rather than asking one field at a time. Each question is
        1–2 sentences max, no bullet lists, no markdown, no emoji — plain text only. Skip any question
        whose answer is already known or inferable. If the first message answers everything, call the
        tool immediately. Do not exceed 5 questions for the common case — the rare start/end clarifying
        question from (6) may add a 6th only when it's genuinely needed. Converge fast.

        Reply in plain text only — no markdown formatting (no bold, headers, or emoji), since the chat
        UI displays raw text.

        Once you have all required information, call the ready_for_itinerary tool instead of replying
        with text. Do not call it before you have all required fields.
        PROMPT;

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
                'start_location' => ['type' => 'string', 'description' => 'Where the trip starts, e.g. "Windhoek". Default to "Windhoek" unless the traveler said otherwise — do not ask for this unless a one-way trip is already implied.'],
                'end_location' => ['type' => 'string', 'description' => 'Where the trip ends, e.g. "Windhoek". Same as start_location for the common round-trip case; a different city only for a one-way trip.'],
            ],
            'required' => ['nights', 'travel_period', 'interests', 'budget_tier', 'adults', 'children_under_13', 'vehicle_type'],
        ],
    ];

    public function __construct(private readonly AnthropicClient $client) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{type: 'question', text: string}|array{type: 'ready', params: array<string, mixed>}
     */
    public function respond(array $history, string $locale = 'en'): array
    {
        $response = $this->client->send(
            config('kaia.interview_model'),
            $this->systemPrompt($locale),
            $history,
            [self::TOOL],
            config('kaia.interview_max_tokens'),
        );

        $textParts = [];

        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $textParts[] = $block['text'];
            } elseif (($block['type'] ?? null) === 'tool_use' && $block['name'] === 'ready_for_itinerary') {
                return ['type' => 'ready', 'params' => $block['input']];
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
