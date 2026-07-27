<?php

namespace App\Connectors\ResConnect;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Low-level HTTP client for the ResConnect (ResRequest) API.
 *
 * ResConnect documentation: https://www.resrequest.com/resconnect
 * Each partner supplies their own ResRequest API key and property code.
 */
class ResConnectClient
{
    private const API_VERSION = 'v7';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
    ) {}

    /**
     * GET request against the ResConnect API.
     *
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
     * POST request against the ResConnect API.
     *
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
        if (blank($this->apiKey)) {
            throw new RuntimeException('ResConnect API key is not configured for this partner.');
        }

        return Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->timeout(30);
    }

    private function url(string $endpoint): string
    {
        return rtrim($this->baseUrl, '/').'/'.self::API_VERSION.'/'.ltrim($endpoint, '/');
    }
}
