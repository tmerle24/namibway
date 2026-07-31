<?php

namespace App\Services\Enrichment;

use App\Models\Listing;
use App\Services\Kaia\AnthropicClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Step 4 of the enrichment pipeline: given a listing's already-fetched
 * homepage text, ask Claude to pull out structured facts — address, GPS,
 * phone, email, activities, facilities, languages, opening hours — via a
 * forced tool call, mirroring the propose_itinerary pattern in
 * App\Services\Kaia\ItineraryService (single-shot, forced tool_choice, no
 * free-text parsing).
 */
class AIListingExtractorService
{
    /** Homepage text is truncated before it reaches the model — bounds cost/latency. */
    private const MAX_PAGE_CHARS = 6000;

    public function __construct(private readonly AnthropicClient $client) {}

    /**
     * @return array{data: array<string, mixed>, usage: array{input_tokens: int, output_tokens: int}}
     */
    public function extract(Listing $listing, string $pageText): array
    {
        $messages = [
            [
                'role' => 'user',
                'content' => "Listing: {$listing->name} ({$listing->type->value}, region: {$listing->region})"
                    .PHP_EOL.PHP_EOL.'Homepage text:'.PHP_EOL.mb_substr($pageText, 0, self::MAX_PAGE_CHARS),
            ],
        ];

        $response = $this->client->send(
            config('enrichment.extraction_model'),
            $this->systemPrompt(),
            $messages,
            [$this->extractTool()],
            config('enrichment.extraction_max_tokens'),
            ['type' => 'tool', 'name' => 'extract_listing_details'],
        );

        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'tool_use' && $block['name'] === 'extract_listing_details') {
                return [
                    'data' => $this->clean($block['input']),
                    'usage' => [
                        'input_tokens' => (int) ($response['usage']['input_tokens'] ?? 0),
                        'output_tokens' => (int) ($response['usage']['output_tokens'] ?? 0),
                    ],
                ];
            }
        }

        Log::warning('AIListingExtractorService: no extract_listing_details tool call returned', ['listing_id' => $listing->id]);

        throw new RuntimeException('AI extraction did not return structured data.');
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            You extract factual details about a Namibian tourism business from its own website
            text. Only report what is actually stated on the page — never guess or invent a value.
            Omit any field you cannot find. GPS coordinates must only be reported if explicitly
            printed on the page (e.g. in a contact/directions section) — do not estimate them.
            Respond only by calling extract_listing_details.
            PROMPT;
    }

    /** @return array<string, mixed> */
    private function extractTool(): array
    {
        return [
            'name' => 'extract_listing_details',
            'description' => 'Submit the structured facts found on the page. Omit fields not found.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'address' => ['type' => 'string'],
                    'latitude' => ['type' => 'number'],
                    'longitude' => ['type' => 'number'],
                    'phone' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'activities' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'facilities' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'languages' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'opening_hours' => [
                        'type' => 'object',
                        'description' => 'Day name (e.g. "monday") to hours string (e.g. "08:00-17:00" or "closed").',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed> */
    private function clean(array $input): array
    {
        return array_filter($input, fn ($value) => $value !== null && $value !== '' && $value !== []);
    }
}
