<?php

namespace App\Services\Payments\Providers\DTOs;

/**
 * What a gateway gives back when an attempt is started: somewhere to send the
 * guest, and its own name for the attempt.
 */
class ProviderIntent
{
    public function __construct(
        /** Where the guest goes to pay. */
        public readonly string $redirectUrl,
        /** The gateway's own id, kept so a callback can be matched two ways. */
        public readonly ?string $providerReference = null,
        /**
         * Anything the provider wants to keep on the intent. Nothing above
         * the interface reads it.
         *
         * @var array<string, mixed>
         */
        public readonly array $meta = [],
    ) {}
}
