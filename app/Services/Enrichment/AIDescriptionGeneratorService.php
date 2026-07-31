<?php

namespace App\Services\Enrichment;

use App\Models\Listing;
use App\Services\Kaia\AnthropicClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Step 5 of the enrichment pipeline: Claude writes fresh, original copy for
 * a listing — never a copy/paste of scraped source text. Any homepage
 * text gathered earlier is passed only as background context for the model
 * to write *about*, and the system prompt explicitly forbids reusing its
 * wording.
 */
class AIDescriptionGeneratorService
{
    private const MAX_CONTEXT_CHARS = 3000;

    public function __construct(private readonly AnthropicClient $client) {}

    /**
     * @return array{data: array{short_description: string, long_description: string, seo_description: string, meta_title: string}, usage: array{input_tokens: int, output_tokens: int}}
     */
    public function generate(Listing $listing, ?string $context = null): array
    {
        $facts = array_filter([
            'name' => $listing->name,
            'type' => $listing->type->value,
            'region' => $listing->region,
            'address' => $listing->address,
            'activities' => $listing->activities,
            'facilities' => $listing->facilities,
        ]);

        $prompt = 'Known facts (JSON): '.json_encode($facts);

        if (filled($context)) {
            $prompt .= PHP_EOL.PHP_EOL.'Background context from the business\'s own website (for reference only — do not copy sentences from it):'
                .PHP_EOL.mb_substr($context, 0, self::MAX_CONTEXT_CHARS);
        }

        $response = $this->client->send(
            config('enrichment.description_model'),
            $this->systemPrompt(),
            [['role' => 'user', 'content' => $prompt]],
            [$this->descriptionTool()],
            config('enrichment.description_max_tokens'),
            ['type' => 'tool', 'name' => 'propose_listing_copy'],
        );

        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'tool_use' && $block['name'] === 'propose_listing_copy') {
                return [
                    'data' => $this->validated($block['input']),
                    'usage' => [
                        'input_tokens' => (int) ($response['usage']['input_tokens'] ?? 0),
                        'output_tokens' => (int) ($response['usage']['output_tokens'] ?? 0),
                    ],
                ];
            }
        }

        Log::warning('AIDescriptionGeneratorService: no propose_listing_copy tool call returned', ['listing_id' => $listing->id]);

        throw new RuntimeException('AI description generation did not return copy.');
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            You write original marketing copy for NamibWay, a Namibian travel platform. You are
            given known facts about a tourism listing and optionally some background context from
            the business's own website. Write entirely new sentences — never copy or lightly
            reword phrases from the background context, it is for your understanding only.

            Write exactly these four pieces:
            - short_description: about 80 words, evocative, for a listing card/preview.
            - long_description: about 250 words, for the full listing page — cover what the place
              offers, its setting, and what makes it worth visiting.
            - seo_description: a single search-engine meta description, max 160 characters.
            - meta_title: a page title, max 60 characters, including the listing name.

            No marketing cliches ("nestled in", "hidden gem", "look no further"). No emoji, no
            markdown. Respond only by calling propose_listing_copy.
            PROMPT;
    }

    /** @return array<string, mixed> */
    private function descriptionTool(): array
    {
        return [
            'name' => 'propose_listing_copy',
            'description' => 'Submit the four pieces of original listing copy.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'short_description' => ['type' => 'string'],
                    'long_description' => ['type' => 'string'],
                    'seo_description' => ['type' => 'string'],
                    'meta_title' => ['type' => 'string'],
                ],
                'required' => ['short_description', 'long_description', 'seo_description', 'meta_title'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{short_description: string, long_description: string, seo_description: string, meta_title: string}
     */
    private function validated(array $input): array
    {
        return [
            'short_description' => trim((string) ($input['short_description'] ?? '')),
            'long_description' => trim((string) ($input['long_description'] ?? '')),
            'seo_description' => mb_substr(trim((string) ($input['seo_description'] ?? '')), 0, 160),
            'meta_title' => mb_substr(trim((string) ($input['meta_title'] ?? '')), 0, 60),
        ];
    }
}
