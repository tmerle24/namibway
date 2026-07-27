<?php

namespace App\Connectors\Wetu;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Low-level HTTP client for the Wetu Content API.
 *
 * Wetu API docs: https://wetu.com/API/
 * Authentication: API key per account (shared across properties).
 */
class WetuClient
{
    private const BASE_URL = 'https://wetu.com/API/Profiles';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = self::BASE_URL,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $endpoint, array $query = []): array
    {
        return $this->http()
            ->get($this->url($endpoint), $query)
            ->throw()
            ->json();
    }

    private function http(): PendingRequest
    {
        if (blank($this->apiKey)) {
            throw new RuntimeException('Wetu API key is not configured.');
        }

        return Http::withHeaders([
            'Authorization' => 'Basic '.$this->apiKey,
            'Accept' => 'application/json',
        ])->timeout(30);
    }

    private function url(string $endpoint): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($endpoint, '/');
    }
}
