<?php

namespace App\Services\Payments\Providers\DTOs;

use App\Enums\PaymentIntentStatus;

/**
 * What a gateway says happened.
 *
 * `pending` is a real answer and not an error: a guest who has opened the page
 * and not paid yet is neither a success nor a failure, and treating "I do not
 * know yet" as "no" is how a payment that arrives thirty seconds later ends up
 * with nowhere to land.
 */
class ProviderOutcome
{
    public function __construct(
        public readonly PaymentIntentStatus $status,
        public readonly ?string $providerReference = null,
        /** What the gateway said, for a human reading a failed attempt later. */
        public readonly ?string $message = null,
        /** @var array<string, mixed> */
        public readonly array $meta = [],
    ) {}

    public static function pending(?string $message = null): self
    {
        return new self(PaymentIntentStatus::Pending, message: $message);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function succeeded(?string $providerReference = null, array $meta = []): self
    {
        return new self(PaymentIntentStatus::Succeeded, $providerReference, meta: $meta);
    }

    public static function failed(?string $message = null, ?string $providerReference = null): self
    {
        return new self(PaymentIntentStatus::Failed, $providerReference, $message);
    }

    public static function abandoned(?string $message = null): self
    {
        return new self(PaymentIntentStatus::Abandoned, message: $message);
    }

    public function isSuccessful(): bool
    {
        return $this->status === PaymentIntentStatus::Succeeded;
    }
}
