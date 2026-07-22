<?php

namespace App\Services\Kaia;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const API_VERSION = '2023-06-01';

    /**
     * @param  array<int, array{role: string, content: mixed}>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array<string, mixed>|null  $toolChoice
     * @return array<string, mixed>
     */
    public function send(string $model, string $system, array $messages, array $tools = [], int $maxTokens = 1024, ?array $toolChoice = null): array
    {
        $apiKey = config('services.anthropic.api_key');

        if (blank($apiKey)) {
            throw new RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => $messages,
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
        }

        if ($toolChoice !== null) {
            $payload['tool_choice'] = $toolChoice;
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::API_VERSION,
        ])
            ->timeout(60)
            ->post(self::API_URL, $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                'Anthropic API request failed: '.$response->status().' '.$response->body()
            );
        }

        return $response->json();
    }
}
