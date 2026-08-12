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
            'description' => 'Why a partner should be on NamibWay: a free listing, requests from travellers who have already committed to dates, one-click confirm from an email, and — since August 2026 — the option to run the property here as well, on an occupancy calendar with its own rates and invoices. That last part has no price yet, so the flyer offers to quote and names no figure. The spoken talk track and objection FAQ that go with it live in marketing/partner-outreach-copy.md.',
            'basename' => 'namibway-partner-flyer-a4',
            'source' => 'marketing/flyer-partners-a4.html',
        ],

        'websites-flyer' => [
            'title' => 'Websites flyer',
            'audience' => 'Namibian business owners with no website',
            'description' => 'The website service, for prospecting: a phone mock-up of what a customer gets, one monthly price covering build, hosting, domain and later changes, the "built in Namibia" argument, and a block on the back for custom software — including live availability and prices on the customer\'s own site if they let rooms. Carries a price and a phone number, so check both are still current before printing a batch.',
            'basename' => 'namibway-websites-flyer-a4',
            'source' => 'marketing/flyer-websites-a4.html',
        ],

        'booking-system-flyer' => [
            'title' => 'Booking system proposal',
            'audience' => 'Namibia Wildlife Resorts — a leave-behind for a meeting',
            'description' => 'Proposes a pilot on one property rather than a system replacement, and closes by offering to set one of their camps up on example bookings and show it running. Addressed to a named organisation, so confirm the registered name before printing, and keep the claims as they are — the back page deliberately lists only what runs in production today, which now runs from the availability calendar through to the numbered invoice.',
            'basename' => 'namibway-booking-system-flyer-a4',
            'source' => 'marketing/flyer-booking-system-a4.html',
        ],

        /*
         * The concept papers. A flyer argues; these explain — an overview plus one
         * per product line, seven or eight A4 pages of diagrams and explanation
         * boxes, for somebody who has already said "tell me how it actually works".
         *
         * They are one set of four and cross-reference each other by name, so a
         * change to what one is called has to be made in the other three as well.
         *
         * The load-bearing convention in all four: every capability carries a
         * status chip — running / built / designed — and each ends on a page saying
         * what we do not claim. That page is the reason these can be handed to
         * somebody who will check them, and it is not decoration.
         *
         * The overview is listed first because it is the one to hand over first.
         */

        'concept-overview' => [
            'title' => 'Concept: The whole picture',
            'audience' => 'Anyone being introduced to NamibWay — co-founder, board, investors, a partner asking what the company is',
            'description' => 'The paper to hand over first. Three product lines on one shared platform and why that is one company rather than three; the loop in which each line makes the next easier to sell; where the money comes from and what is still unpriced; and a three-column map of what runs in production, what is built but never proven live, and what is only decided. Deliberately carries no traveller numbers, no revenue, no projections and no claim of funding — see its closing page, which is the point of it.',
            'basename' => 'namibway-concept-overview-a4',
            'source' => 'marketing/concept-overview.html',
        ],

        'concept-kaia' => [
            'title' => 'Concept: Kaia trip planning & listings',
            'audience' => 'Co-founder, prospective partners, investors',
            'description' => 'How a trip plan is made and why it can be trusted: the model writes the plan but is never the source of a fact, the catalogue is fetched in code rather than searched, and driving times come from maintained road data. Also the account line (planning is free, booking is not), the one-active-request rule that stops partners being flooded, and the content-source ladder that decides what may be published. Ends on what we do not claim — no traveller numbers, no named partners, no validated connector.',
            'basename' => 'namibway-concept-kaia-trip-planning-a4',
            'source' => 'marketing/concept-kaia-trip-planning.html',
        ],

        'concept-booking-payment' => [
            'title' => 'Concept: Booking & payment',
            'audience' => 'Lodge owners and operators, the co-founder selling it, investors',
            'description' => 'The densest of the three, because the buyer for it has been sold a booking system before. The hourglass the design rests on, the occupancy calendar and how the last room is protected from being sold twice, how a night is priced the way this market quotes, the folio and the gapless invoice, and the three settlement models picked by one number. Two sentences in it must never drift: no connector has run against a live partner account, and no merchant account has ever settled a real transaction.',
            'basename' => 'namibway-concept-booking-payment-a4',
            'source' => 'marketing/concept-booking-payment.html',
        ],

        'concept-websites' => [
            'title' => 'Concept: Websites',
            'audience' => 'Business owners, the co-founder prospecting, investors',
            'description' => 'Why the sites are generated rather than hand-built, why not WordPress, and why the website owns its own content instead of reading a listing — the decision the whole product line rests on. Also the one-click flow through to a customer domain, what an owner deliberately cannot do, and the live availability a site can show. Carries the monthly price twice, which is a proposal until signed off, and states plainly that no customer site is live yet.',
            'basename' => 'namibway-concept-websites-a4',
            'source' => 'marketing/concept-websites.html',
        ],

    ],

];
