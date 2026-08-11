<?php

namespace App\Services\Demo;

use App\Models\Listing;
use App\Models\Partner;
use App\Models\User;

/**
 * What `booking:demo-tenant` produced, so the command can print it without
 * reaching back into the builder for pieces.
 */
class DemoTenant
{
    public function __construct(
        public readonly Partner $partner,
        public readonly Listing $listing,
        public readonly Listing $source,
        public readonly User $user,
        public readonly string $password,
        public readonly string $signInUrl,
        public readonly int $roomTypes,
        /** True when the lodge had none on file and the demo made them up. */
        public readonly bool $roomTypesAreInvented,
        public readonly int $stays,
        public readonly int $blocks,
    ) {}
}
