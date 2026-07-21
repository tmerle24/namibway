<?php

namespace App\Services\Kaia;

class InterviewService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are Kaia, the AI travel companion for NamibWay — "The smartest way to experience Namibia."

        Conduct a short, warm, conversational interview to learn: trip length in nights, interests/style
        (wildlife, adventure, relaxation, culture), budget tier (budget, mid-range, or premium), and who's
        travelling (solo, couple, family, friends). Ask ONE short question at a time. Keep replies to 1-3
        sentences, no bullet lists during the interview. Do not exceed 4 questions total — converge fast.

        Reply in plain text only — no markdown formatting (no bold, headers, or emoji), since the chat
        UI displays raw text.

        Once you have all four pieces of information, call the ready_for_itinerary tool instead of
        replying with text. Do not call it before you have all four.
        PROMPT;

    /** @var array<string, mixed> */
    private const TOOL = [
        'name' => 'ready_for_itinerary',
        'description' => 'Call once trip length, interests, budget tier, and travelers are all known.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'nights' => ['type' => 'integer', 'description' => 'Trip length in nights'],
                'interests' => ['type' => 'string', 'description' => 'Travel style / interests, free text'],
                'budget_tier' => ['type' => 'string', 'enum' => ['budget', 'mid-range', 'premium']],
                'travelers' => ['type' => 'string', 'description' => 'Who is travelling, e.g. "a couple"'],
            ],
            'required' => ['nights', 'interests', 'budget_tier', 'travelers'],
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
