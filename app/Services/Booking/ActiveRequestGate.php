<?php

namespace App\Services\Booking;

use App\Enums\InquiryStatus;
use App\Models\Inquiry;

/**
 * The "one active request pipeline per traveler" rule from CLAUDE.md's core
 * product mechanic — the thing that stops an AI-generated plan from turning
 * into a flood of speculative requests at partner owners.
 *
 * It used to be three copies of the same query (ListingController's single and
 * batch inquiry paths, TripController's plan booking), each keyed on the
 * submitted email address alone. That made the rule trivially avoidable: the
 * same traveler types a second address and starts a second pipeline, and
 * nothing connects the two. Now that booking requires an account, the account
 * is the stronger identity — but the email still has to count too, because
 * every row created before this has no account behind it, and a traveler can
 * legitimately book under a contact address that isn't their login.
 */
class ActiveRequestGate
{
    /**
     * True when this traveler already has a request pipeline in flight and
     * must wait for it to resolve before starting another.
     */
    public static function blocks(?int $userId, string $email): bool
    {
        $activeStatuses = array_map(
            fn (InquiryStatus $status) => $status->value,
            array_filter(InquiryStatus::cases(), fn (InquiryStatus $status) => $status->isActive())
        );

        return Inquiry::query()
            ->whereIn('status', $activeStatuses)
            ->where(function ($query) use ($userId, $email) {
                $query->where('email', $email);

                if ($userId !== null) {
                    $query->orWhere('user_id', $userId);
                }
            })
            ->exists();
    }

    /**
     * The single wording every entry point uses when the gate blocks — the
     * traveler shouldn't get a different explanation depending on which button
     * they pressed.
     */
    public static function message(): string
    {
        return __('You already have an active booking request in progress. Please wait for it to be resolved before submitting a new one.');
    }
}
