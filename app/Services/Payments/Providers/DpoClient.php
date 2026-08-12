<?php

namespace App\Services\Payments\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

/**
 * The HTTP half of the DPO Pay integration, kept apart from the provider so
 * the provider can be tested without a network — the same split every
 * connector in `app/Connectors` already uses.
 *
 * ## It speaks XML, and that is not a complaint
 *
 * DPO's public API is a single endpoint that takes an `<API3G>` document and
 * answers with one. Every call carries the company token inside the body
 * rather than in a header — there is no bearer token, no signature, no
 * separate auth step. That is older than a JSON API would be and it is also
 * completely specified, which is worth more.
 *
 * Element **order matters** to DPO, so the documents are built in the order
 * their documentation gives rather than from an associative array whose order
 * nobody controls.
 *
 * Nothing here interprets a response. Deciding what `<Result>000</Result>`
 * means is DpoProvider's job.
 */
class DpoClient
{
    public function __construct(
        private readonly string $companyToken,
        private readonly string $baseUrl = 'https://secure.3gdirectpay.com',
    ) {}

    /**
     * Create a transaction and get a token to redirect the guest with.
     *
     * @param  array<string, string|int|float>  $transaction  ordered as DPO documents it
     * @param  array<string, string>  $service
     * @return array<string, mixed>
     */
    public function createToken(array $transaction, array $service): array
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><API3G/>');
        $xml->addChild('CompanyToken', $this->escape($this->companyToken));
        $xml->addChild('Request', 'createToken');

        $node = $xml->addChild('Transaction');

        foreach ($transaction as $key => $value) {
            $node->addChild($key, $this->escape((string) $value));
        }

        // A "service" is what is being sold — DPO uses it for the merchant's
        // own reporting and, for travel, for the date the service is
        // delivered. A stay has one: the arrival.
        $services = $xml->addChild('Services');
        $entry = $services->addChild('Service');

        foreach ($service as $key => $value) {
            $entry->addChild($key, $this->escape($value));
        }

        return $this->send($xml);
    }

    /**
     * Ask DPO what happened to a transaction.
     *
     * @return array<string, mixed>
     */
    public function verifyToken(string $transactionToken): array
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><API3G/>');
        $xml->addChild('CompanyToken', $this->escape($this->companyToken));
        $xml->addChild('Request', 'verifyToken');
        $xml->addChild('TransactionToken', $this->escape($transactionToken));

        return $this->send($xml);
    }

    /**
     * @return array<string, mixed>
     */
    public function refundToken(string $transactionToken, float $amount, string $details): array
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><API3G/>');
        $xml->addChild('CompanyToken', $this->escape($this->companyToken));
        $xml->addChild('Request', 'refundToken');
        $xml->addChild('TransactionToken', $this->escape($transactionToken));
        $xml->addChild('refundAmount', number_format($amount, 2, '.', ''));
        $xml->addChild('refundDetails', $this->escape($details));

        return $this->send($xml);
    }

    /** Where the guest is sent once a token exists. */
    public function checkoutUrl(string $transactionToken): string
    {
        return rtrim($this->baseUrl, '/').'/payv3.php?ID='.urlencode($transactionToken);
    }

    /**
     * @return array<string, mixed>
     */
    private function send(SimpleXMLElement $document): array
    {
        $response = $this->request()->withBody($document->asXML() ?: '', 'application/xml')->post('/API/v6/');

        return $this->parse($response->body());
    }

    /**
     * The response document as a flat array.
     *
     * Flat because DPO's answers are: a Result, an explanation, and a handful
     * of scalars beside them. Anything nested is a detail this integration
     * does not use, and flattening it here keeps the provider readable.
     *
     * @return array<string, mixed>
     */
    private function parse(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        // Warnings off and checked by return value: a gateway answering with
        // an HTML error page is a thing that happens, and it must become an
        // empty result the provider reads as "not yet" rather than a PHP
        // warning in a log nobody watches.
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return [];
        }

        $parsed = [];

        foreach ($xml->children() as $name => $child) {
            $parsed[(string) $name] = $child->count() > 0
                ? json_decode((string) json_encode($child), true)
                : (string) $child;
        }

        return $parsed;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->accept('application/xml')
            // A guest is watching a spinner while this runs, so a hung gateway
            // has to become an error they can act on rather than a request
            // that eventually times out at the web server.
            ->timeout(25)
            // Retried on a connection failure only. Re-posting a createToken
            // is safe because CompanyRefUnique makes DPO refuse a duplicate
            // reference rather than create a second transaction.
            ->retry(2, 250, throw: false);
    }

    /**
     * SimpleXMLElement::addChild does not escape, so anything with an
     * ampersand in it — a lodge called "Sun & Sand", a URL with a query
     * string — would produce a document DPO cannot parse.
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
