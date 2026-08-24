<?php

namespace App\Enums;

/**
 * What Kaia's last question is waiting for — the one thing that makes a
 * tappable answer possible.
 *
 * The interview is a slot-filling conversation (nights, period, interests,
 * budget, party, vehicle), but the model used to answer in free prose, so the
 * only thing the UI could offer was a text field. Naming the slot turns every
 * question into a small closed set the traveler can tap through instead: the
 * chat says *what* it asked, and the frontend owns *how* that is offered
 * (`resources/js/lib/kaia-suggestions.ts`) and in which language.
 *
 * Deliberately the interview's slots, not the trip params: `travelers` covers
 * adults and children together because that is one question, and `start_end`
 * is one because a round trip answers both ends at once. `None` is the honest
 * answer for a general Namibia question, where a canned reply set would be a
 * guess about what somebody wants to know next.
 */
enum InterviewSlot: string
{
    case Nights = 'nights';
    case TravelPeriod = 'travel_period';
    case Interests = 'interests';
    case BudgetTier = 'budget_tier';
    case Travelers = 'travelers';
    case VehicleType = 'vehicle_type';
    case StartEnd = 'start_end';
    case None = 'none';

    /** @return list<string> */
    public static function values(): array
    {
        // array_values, not array_map alone: the annotation promises a list,
        // and that is the shape the tool schema's `enum` has to be.
        return array_values(array_map(fn (self $slot) => $slot->value, self::cases()));
    }
}
