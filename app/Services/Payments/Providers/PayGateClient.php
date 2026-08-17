<?php

namespace App\Services\Payments\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The HTTP half of the PayGate PayWeb3 integration.
 *
 * PayWeb3 speaks URL-encoded form posts and returns the same. Every call
 * carries PAYGATE_ID + CHECKSUM built by concatenating all non-empty field
 * values (in array order, not key order) with the encryption key appended,
 * then MD5-hashed. The same checksum scheme is used for all three endpoints
 * and for the NOTIFY callback we receive — so this class is also the place
 * where incoming checksums are verified.
 *
 * Test credentials (from PayGate's public documentation):
 *   PAYGATE_ID    : 10011072130
 *   encryption key: secret
 */
class PayGateClient
{
    private const INITIATE_URL = 'https://secure.paygate.co.za/payweb3/initiate.trans';

    public const PROCESS_URL = 'https://secure.paygate.co.za/payweb3/process.trans';

    private const QUERY_URL = 'https://secure.paygate.co.za/payweb3/query.trans';

    public function __construct(
        private readonly string $paygateId,
        private readonly string $encryptionKey,
    ) {}

    /**
     * Start a transaction. Returns an array that includes PAY_REQUEST_ID and
     * CHECKSUM on success, or ERROR on failure.
     *
     * The CHECKSUM returned here is what gets posted to process.trans to send
     * the guest to PayGate's hosted page — store it on the intent.
     *
     * @param  array<string, string>  $fields  all fields except PAYGATE_ID and CHECKSUM
     * @return array<string, string>
     */
    public function initiate(array $fields): array
    {
        $fields = array_merge(['PAYGATE_ID' => $this->paygateId], $fields);
        $fields['CHECKSUM'] = $this->checksum($fields);

        return $this->post(self::INITIATE_URL, $fields);
    }

    /**
     * Ask PayGate what happened to a transaction.
     *
     * @return array<string, string>
     */
    public function query(string $payRequestId, string $reference): array
    {
        $fields = [
            'PAYGATE_ID' => $this->paygateId,
            'PAY_REQUEST_ID' => $payRequestId,
            'REFERENCE' => $reference,
        ];
        $fields['CHECKSUM'] = $this->checksum($fields);

        return $this->post(self::QUERY_URL, $fields);
    }

    /**
     * Build the MD5 checksum for a set of fields.
     *
     * Rule: concatenate non-empty values in array order (CHECKSUM key skipped
     * if present), then append the encryption key, then MD5.
     *
     * @param  array<string, string>  $fields
     */
    public function checksum(array $fields): string
    {
        $source = '';

        foreach ($fields as $key => $value) {
            if ($key === 'CHECKSUM' || $value === '') {
                continue;
            }

            $source .= $value;
        }

        return md5($source.$this->encryptionKey);
    }

    /**
     * Verify the CHECKSUM in a set of fields received from PayGate.
     *
     * Used for both the NOTIFY callback and query responses. Returns false
     * rather than throwing so the caller decides whether a mismatch is a
     * warning or a hard stop.
     *
     * @param  array<string, string>  $fields  must include a CHECKSUM key
     */
    public function verifyChecksum(array $fields): bool
    {
        $received = $fields['CHECKSUM'] ?? null;

        if (! is_string($received) || $received === '') {
            return false;
        }

        $withoutChecksum = $fields;
        unset($withoutChecksum['CHECKSUM']);

        return hash_equals($this->checksum($withoutChecksum), $received);
    }

    public function paygateId(): string
    {
        return $this->paygateId;
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    private function post(string $url, array $fields): array
    {
        $response = $this->request()->asForm()->post($url, $fields);

        /** @var array<string, mixed> $parsed */
        $parsed = [];
        parse_str($response->body(), $parsed);

        return array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $parsed);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl('https://secure.paygate.co.za')
            ->timeout(25)
            ->retry(2, 250, throw: false);
    }
}
