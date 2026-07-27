<?php

namespace App\Connectors\NightsBridge;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Low-level HTTP client for the NightsBridge Channel Manager API.
 *
 * NightsBridge API docs: https://api.nightsbridge.com/
 * Authentication: Basic Auth (bbid + api_key) per property.
 */
class NightsBridgeClient
{
    private const BASE_URL = 'https://api.nightsbridge.com/bridge';

    public function __construct(
        private readonly string $bbid,
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

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $endpoint, array $payload = []): array
    {
        return $this->http()
            ->post($this->url($endpoint), $payload)
            ->throw()
            ->json();
    }

    private function http(): PendingRequest
    {
        if (blank($this->bbid) || blank($this->apiKey)) {
            throw new RuntimeException('NightsBridge bbid and api_key are required.');
        }

        return Http::withBasicAuth($this->bbid, $this->apiKey)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->timeout(30);
    }

    private function url(string $endpoint): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($endpoint, '/');
    }
}
