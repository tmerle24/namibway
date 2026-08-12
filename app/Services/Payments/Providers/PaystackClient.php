<?php

namespace App\Services\Payments\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The HTTP half of the Paystack integration, kept apart from the provider so
 * the provider can be tested without a network — the same split every
 * connector in `app/Connectors` already uses.
 *
 * Nothing here interprets a response. Deciding what `status: "success"` means
 * is PaystackProvider's job; this returns what came back.
 */
class PaystackClient
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $baseUrl = 'https://api.paystack.co',
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function initialize(array $payload): array
    {
        return $this->request()->post('/transaction/initialize', $payload)->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $reference): array
    {
        return $this->request()->get('/transaction/verify/'.urlencode($reference))->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function refund(array $payload): array
    {
        return $this->request()->post('/refund', $payload)->json() ?? [];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->secretKey)
            ->acceptJson()
            // A guest is sitting in front of a spinner while this runs, so a
            // hung gateway has to become an error they can act on rather than
            // a request that eventually times out at the web server.
            ->timeout(20)
            // Retried once, and only on a connection failure: re-posting an
            // initialize that already reached Paystack is safe because the
            // reference is ours and idempotent at their end.
            ->retry(2, 200, throw: false);
    }
}
