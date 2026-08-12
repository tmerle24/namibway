<?php

namespace App\Services\Payments\Providers\DTOs;

/**
 * A verified webhook, reduced to the two things the application needs: which
 * attempt it is about, and what happened.
 *
 * "Verified" is the provider's word — parseCallback() is where a signature is
 * checked, and returning one of these means the provider is vouching that the
 * request really came from the gateway. Nothing downstream re-checks, because
 * only the provider knows how.
 */
class ProviderCallback
{
    public function __construct(
        /** Our own reference, as we sent it and as the gateway echoed it back. */
        public readonly string $intentReference,
        public readonly ProviderOutcome $outcome,
    ) {}
}
