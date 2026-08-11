<?php

namespace App\Exceptions\Pricing;

use RuntimeException;

/**
 * A code was typed in and it did not work.
 *
 * Always thrown rather than silently ignored. Somebody typing a code is
 * telling you they expect a discount; quietly charging full price is how a
 * guest finds out at check-out, and a receptionist who is not told cannot
 * explain it. Every message says which part failed, because "invalid code" is
 * not something anybody can act on.
 *
 * A *public* offer that does not apply is not an error — nobody asked for it.
 * That is why only the code path throws.
 */
class PromotionUnavailableException extends RuntimeException
{
    public static function notFound(string $code): self
    {
        return new self("There is no offer with the code {$code} at this property.");
    }

    public static function inactive(string $code): self
    {
        return new self("The offer {$code} is not running.");
    }

    public static function exhausted(string $code): self
    {
        return new self("The offer {$code} has been used as many times as it allows.");
    }

    public static function notApplicable(string $code): self
    {
        return new self("The offer {$code} does not cover these dates, rooms or rates.");
    }
}
