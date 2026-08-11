<?php

namespace App\Exceptions\Inventory;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Not enough units free for what was asked. Carries the night that ran out so
 * a caller can say which date is the problem rather than "sold out".
 */
class InventoryUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly int $roomTypeId,
        public readonly ?Carbon $date = null,
        public readonly int $requested = 0,
    ) {
        $on = $date !== null ? ' on '.$date->toDateString() : '';

        parent::__construct("Room type [{$roomTypeId}] has fewer than {$requested} unit(s) free{$on}.");
    }
}
