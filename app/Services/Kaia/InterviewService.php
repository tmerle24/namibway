<?php

namespace App\Services\Kaia;

class InterviewService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are Kaia, the AI travel companion for NamibWay — "The smartest way to experience Namibia."

        Conduct a short, warm, conversational interview to learn: trip length in nights, a specific start
        date or date range (ask for day and month at minimum, ideally a full date like "14 August 2026" —
        not just a season), interests/style (wildlife, adventure, relaxation, culture), budget tier
        (budget, mid-range, or premium), the exact number of adults travelling and whether any children are
        joining (if yes, ask their ages — this affects activity choices and room sizing), and how they'll
        get around — a normal self-drive car, or a camper/4x4 with rooftop tent for camping along the way.
        Ask ONE short question at a time. Keep replies to 1-3 sentences, no bullet lists during the
        interview. Do not exceed 7 questions total — converge fast.

        Reply in plain text only — no markdown formatting (no bold, headers, or emoji), since the chat
        UI displays raw text.

        Once you have all the required information, call the ready_for_itinerary tool instead of
        replying with text. Do not call it before you have all required fields.
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
                'children_ages' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Ages of children travelling, e.g. [8, 12]. Empty array if no children.',
                ],
                'vehicle_type' => ['type' => 'string', 'enum' => ['car', 'camper'], 'description' => 'Normal self-drive car, or a camper/4x4 for camping'],
            ],
            'required' => ['nights', 'travel_period', 'interests', 'budget_tier', 'adults', 'children_ages', 'vehicle_type'],
        ],
    ];

    public function __construct(private readonly AnthropicClient $client) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{type: 'question', text: string}|array{type: 'ready', params: array<string, mixed>}
     */
    public function respond(array $history): array
    {
        $response = $this->client->send(
            config('kaia.interview_model'),
            self::SYSTEM_PROMPT,
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
}
