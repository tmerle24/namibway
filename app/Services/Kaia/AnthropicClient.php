<?php

namespace App\Services\Kaia;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const API_VERSION = '2023-06-01';

    // Retries only cover transient failures (timeouts, rate limits, upstream
    // 5xx) — a 400 (bad request / insufficient credit) or 401 (bad key) will
    // never succeed on retry, so those fail fast instead of wasting time.
    private const MAX_ATTEMPTS = 3;

    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504, 529];

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

        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => self::API_VERSION,
                ])
                    ->timeout(60)
                    ->post(self::API_URL, $payload);
            } catch (ConnectionException $e) {
                $lastError = $e->getMessage();

                $this->waitBeforeRetry($attempt);

                continue;
            }

            if (! $response->failed()) {
                return $response->json();
            }

            $lastError = $response->status().' '.$response->body();

            if (! in_array($response->status(), self::RETRYABLE_STATUSES, true)) {
                throw new RuntimeException('Anthropic API request failed: '.$lastError);
            }

            $this->waitBeforeRetry($attempt);
        }

        throw new RuntimeException('Anthropic API request failed after '.self::MAX_ATTEMPTS.' attempts: '.$lastError);
    }

    private function waitBeforeRetry(int $attempt): void
    {
        if ($attempt < self::MAX_ATTEMPTS) {
            usleep(300_000 * $attempt);
        }
    }
}
