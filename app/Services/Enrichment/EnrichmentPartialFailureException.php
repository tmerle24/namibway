<?php

namespace App\Services\Enrichment;

use RuntimeException;
use Throwable;

/**
 * Thrown by EnrichmentPipeline::run() when a step fails partway through the run —
 * carries whatever had already been accumulated (fields to save, tokens/Places calls
 * spent) so the caller (EnrichListingJob) can still record real cost and progress
 * instead of it silently vanishing. Before this existed, any exception anywhere in
 * run() discarded every field found so far AND left true API spend (calls already
 * billed by Google/Anthropic before the crash) completely unrecorded in
 * enrichment_jobs — invisible in the dashboard's cost tracking, which made a bug like
 * the redundant-lookup one look far cheaper per failed attempt than it actually was.
 */
class EnrichmentPartialFailureException extends RuntimeException
{
    /**
     * @param array{fields_updated: list<string>, tokens_used: int, cost_estimate: float, places_calls: array<string, int>, places_cost_estimate: float, log: list<string>, score: int} $partialResult
     */
    public function __construct(string $message, public readonly array $partialResult, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
