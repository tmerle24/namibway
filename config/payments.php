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

    /*
    |--------------------------------------------------------------------------
    | The payment provider
    |--------------------------------------------------------------------------
    |
    | Which gateway takes money from guests. Platform-level rather than
    | per-partner, because under the models where anything is collected online
    | it is collected by us — see PaymentProviderFactory.
    |
    | The default is the demo provider and it stays the default until a real
    | merchant account exists. It runs entirely in-process with no network,
    | which is what lets the whole flow — authorise, capture, decline, refund
    | and the asynchronous callback — be demonstrated and tested before any
    | commercial arrangement is made (PAYMENTS.md § 5).
    |
    | **The candidates, checked 2026-08-12.** PAYMENTS.md § 5 has the detail;
    | the short version is that only one of them covers Namibia:
    |
    | - **DPO Pay by Network** — operates in Namibia with a team in Windhoek,
    |   bills and settles in **NAD**, one documented public API. Implemented,
    |   and the one to use once a merchant account exists.
    | - **PayGate** — absorbed into the same group.
    | - **Peach Payments** — live in South Africa, Kenya and Mauritius. Namibia
    |   is an announced intention rather than an account anybody can open.
    | - **Ozow / Netcash** — South African bank-to-bank rails; Instant EFT works
    |   only with South African bank accounts.
    | - **PayToday** — Namibian, run by Nedbank Namibia, but a wallet with a
    |   plugin rather than a documented API. A method to add, not an acquirer.
    | - **FNB / Bank Windhoek / Standard Bank Namibia** — e-commerce acquiring
    |   exists, through a relationship and usually behind a gateway. A
    |   conversation, not an integration.
    | - **Paystack** — implemented, but it does **not** support Namibia as a
    |   merchant country (Nigeria, Ghana, Kenya, South Africa, Côte d'Ivoire;
    |   Egypt and Rwanda newer). Usable only through a South African entity
    |   settling in ZAR, which is a decision about the company.
    |
    | Each is a class implementing PaymentProvider and an entry below; nothing
    | above the interface changes.
    |
    */

    'default_provider' => env('PAYMENTS_PROVIDER', 'demo'),

    'providers' => [
        'dpo' => [
            // From the DPO merchant portal. It is the whole of the
            // authentication — DPO carries it inside the request body rather
            // than in a header, so it never appears in a URL or a log line.
            'company_token' => env('DPO_COMPANY_TOKEN'),
            'base_url' => env('DPO_BASE_URL', 'https://secure.3gdirectpay.com'),

            // Configured per DPO account; there is no universal value, and a
            // wrong one is rejected at createToken rather than silently.
            'service_type' => env('DPO_SERVICE_TYPE'),

            // How long a payment link stays alive, in hours. A link that lives
            // forever is a room held forever behind an attempt nobody will
            // finish.
            'link_hours' => (int) env('DPO_LINK_HOURS', 48),

            // NAD first, which is the point: a Namibian folio needs no
            // conversion at all, so none of the peg machinery below is
            // reached.
            'currencies' => ['NAD', 'ZAR', 'USD', 'EUR', 'GBP'],

            /*
             * verifyToken result codes that mean the attempt is over and
             * unpaid.
             *
             * **Empty on purpose, and it must stay empty until somebody has
             * the code table from DPO.** `000` is documented as "Transaction
             * Paid" and is the only code the provider treats as final;
             * everything else is reported as still pending. That is the safe
             * direction — a code guessed to mean "declined" writes off a
             * payment that may have arrived, and the guest is the one who
             * finds out.
             *
             * Ask DPO for the verifyToken codes, then list the genuine
             * failures here. This is also where a reviewer can see at a glance
             * which codes we claim to understand.
             */
            'failure_codes' => [],
        ],

        'paystack' => [
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'public_key' => env('PAYSTACK_PUBLIC_KEY'),
            'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),

            // What the account can settle. A South African Paystack account
            // is ZAR; a Nigerian one would be NGN. Deliberately configurable
            // rather than hardcoded, because it depends on which entity holds
            // the account.
            'currencies' => ['ZAR'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency pegs
    |--------------------------------------------------------------------------
    |
    | Used only when the folio's currency is one the chosen gateway cannot
    | settle. NAD is pegged 1:1 to ZAR under the Common Monetary Area, which is
    | already why config/currencies.php converts NAD using the ZAR rate — so
    | charging a Namibian folio in ZAR is an identity, not a conversion
    | somebody has to trust.
    |
    | The rate used is stored on the payment intent rather than looked up
    | again, because a peg is a policy and policies end. A refund has to return
    | the money that was taken, at the rate it was taken at.
    |
    */

    'currency_pegs' => [
        'NAD' => ['ZAR' => 1.0],
    ],
];
