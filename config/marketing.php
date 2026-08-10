<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Print material offered in the admin panel
    |--------------------------------------------------------------------------
    |
    | The PDFs are build artifacts committed under marketing/out/ — regenerated
    | with `node marketing/build-flyer.mjs`, and shipped by whatever deploy picks
    | up the commit. Nothing generates them on the server.
    |
    | This list is also the security boundary for the download route: a request
    | names a key from here, never a filename, so no request can address a file
    | outside the directory below. Adding material means adding an entry, which
    | is deliberate — see App\Support\MarketingMaterial.
    |
    */

    'directory' => 'marketing/out',

    'variants' => [
        'print' => [
            'label' => 'Print (with bleed)',
            'note' => '216 × 303 mm — A4 plus 3 mm bleed, no crop marks. This is the file for a print shop.',
        ],
        'screen' => [
            'label' => 'A4 (screen)',
            'note' => 'Plain A4, for email and the office printer.',
        ],
    ],

    'material' => [

        'partner-flyer' => [
            'title' => 'Partner flyer',
            'audience' => 'Lodges, camps, guides and restaurants we want listed',
            'description' => 'Why a partner should be on NamibWay: a free listing, requests from travellers who have already committed to dates, and one-click confirm from an email. The spoken talk track and objection FAQ that go with it live in marketing/partner-outreach-copy.md.',
            'basename' => 'namibway-partner-flyer-a4',
            'source' => 'marketing/flyer-partners-a4.html',
        ],

        'websites-flyer' => [
            'title' => 'Websites flyer',
            'audience' => 'Namibian business owners with no website',
            'description' => 'The website service, for prospecting: one monthly price covering build, hosting, domain and later changes, plus a block on the back for custom software. Carries a price, so check it is still the one we quote before printing a batch.',
            'basename' => 'namibway-websites-flyer-a4',
            'source' => 'marketing/flyer-websites-a4.html',
        ],

        'booking-system-flyer' => [
            'title' => 'Booking system proposal',
            'audience' => 'Namibia Wildlife Resorts — a leave-behind for a meeting',
            'description' => 'Proposes a pilot on one property rather than a system replacement. Addressed to a named organisation, so confirm the registered name before printing, and keep the claims as they are — the back page deliberately lists only what runs in production today.',
            'basename' => 'namibway-booking-system-flyer-a4',
            'source' => 'marketing/flyer-booking-system-a4.html',
        ],

    ],

];
