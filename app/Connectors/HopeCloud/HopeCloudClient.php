<?php

namespace App\Connectors\HopeCloud;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Low-level HTTP client for the hopeCloud (HOPE Software) API.
 *
 * hopeCloud is a Namibia-specific PMS handling Tourism Levy, NTB and HAN reporting.
 * API docs: https://hopecloud.co.za/ (contact HOPE Software for API access)
 * Authentication: API key per property account.
 */
class HopeCloudClient
{
    private const BASE_URL = 'https://api.hopecloud.co.za/v1';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $accountId,
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
        if (blank($this->apiKey) || blank($this->accountId)) {
            throw new RuntimeException('hopeCloud api_key and account_id are required.');
        }

        return Http::withHeaders([
            'X-API-Key' => $this->apiKey,
            'X-Account-ID' => $this->accountId,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->timeout(30);
    }

    private function url(string $endpoint): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($endpoint, '/');
    }
}
