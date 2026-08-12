<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Commission and deposit — the fallback values only
    |--------------------------------------------------------------------------
    |
    | These are the bottom of the resolution chain, not the place the numbers
    | are actually set:
    |
    |     listing override → partner override → platform setting → this file
    |
    | The platform setting is a single row the team edits in /admin without a
    | deploy (App\Models\PaymentSettings), seeded from these values the first
    | time it is read. Deliberately the same shape as MessageSettings, and the
    | same rule the availability calendar already uses for its sparse
    | overrides: null means inherit, most specific wins.
    |
    | Two rates, two very different owners — PAYMENTS.md § 2a:
    |
    | - Commission is OURS. Adjustable per partner and per listing, but only by
    |   us; it never appears as an editable field in the partner panel. A
    |   partner may of course see what they pay.
    | - The deposit is the PARTNER'S, within the range we allow.
    |
    | Both are percentages, stored as percentages (5.0 means 5 %), because that
    | is how they are discussed, negotiated and typed. A fraction would be one
    | more place for a factor of a hundred to go missing.
    |
    */

    // CLAUDE.md models ~5 %, deliberately below OTA rates.
    'commission_rate' => (float) env('PAYMENTS_COMMISSION_RATE', 5.0),

    'deposit_rate' => (float) env('PAYMENTS_DEPOSIT_RATE', 15.0),

    /*
    |--------------------------------------------------------------------------
    | The deposit floor
    |--------------------------------------------------------------------------
    |
    | Null means "the commission rate", which is the sensible landing spot and
    | the reason it is the default: at exactly that floor, what we collect and
    | what we are owed cancel, so no money has to move between us and the
    | partner at all — no payout run, no statement, no reconciliation
    | (PAYMENTS.md § 2C).
    |
    | Below the floor is not simply a smaller number: at 0 % we collect nothing
    | and have to invoice the partner for commission, which puts collection
    | risk on us. That is unlocked per partner by us rather than typed by them
    | — see slice 4's `allow_zero_deposit`.
    |
    */

    'minimum_deposit_rate' => env('PAYMENTS_MINIMUM_DEPOSIT_RATE') === null
        ? null
        : (float) env('PAYMENTS_MINIMUM_DEPOSIT_RATE'),
];
