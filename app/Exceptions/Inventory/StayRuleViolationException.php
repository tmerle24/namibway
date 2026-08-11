<?php

namespace App\Exceptions\Inventory;

use RuntimeException;

/**
 * A stay restriction refused the booking: minimum stay, closed to arrival, or
 * closed to departure. Separate from InventoryUnavailableException because
 * the answers differ — "there is no room" versus "there is a room but not on
 * those terms", and only the second one is worth suggesting a change of dates
 * for.
 */
class StayRuleViolationException extends RuntimeException
{
    public const MIN_STAY = 'min_stay';

    public const CLOSED_TO_ARRIVAL = 'closed_to_arrival';

    public const CLOSED_TO_DEPARTURE = 'closed_to_departure';

    public function __construct(
        public readonly string $rule,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function minStay(int $required, int $actual): self
    {
        return new self(self::MIN_STAY, "Minimum stay is {$required} night(s); {$actual} requested.");
    }

    public static function closedToArrival(string $date): self
    {
        return new self(self::CLOSED_TO_ARRIVAL, "Arrivals are closed on {$date}.");
    }

    public static function closedToDeparture(string $date): self
    {
        return new self(self::CLOSED_TO_DEPARTURE, "Departures are closed on {$date}.");
    }
}
