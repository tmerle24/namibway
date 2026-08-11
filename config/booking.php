<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Partner panel host
    |--------------------------------------------------------------------------
    |
    | The lodge-facing booking system is sold as a product in its own right,
    | so it gets its own address rather than looking like a sub-page of a
    | travel site. Set this and the Filament partner panel binds to that host;
    | leave it empty and the panel behaves exactly as it always has, on
    | whatever host serves the app. Local development and CI therefore need no
    | hosts file entry and no configuration.
    |
    | When it *is* set, /partner on the main domain redirects here, so nothing
    | already bookmarked or already sent in an email breaks. The signed
    | confirm/decline links in routes/partner.php are deliberately excluded
    | from that redirect: a URL signature covers the host, so forwarding one
    | to another host invalidates it.
    |
    | The prerequisite is server-side and outside the application: a DNS
    | record at OVH (namibway.com's DNS is not on Cloudflare — that is what
    | broke cdn.namibway.com), a certificate covering the host, and an nginx
    | server block. See DEPLOYMENT.md.
    |
    */

    'panel_domain' => env('BOOKING_PANEL_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Demo tenants
    |--------------------------------------------------------------------------
    |
    | A sandbox partner built from a real listing so a prospect sees their own
    | lodge already running in the system. See booking:demo-tenant.
    |
    | A demo tenant inherits no contact details from the partner it was copied
    | from, precisely so that nothing it does can reach the real lodge owner.
    | Every address it holds is a plus-address on the team mailbox above.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Where booking mail goes before a lodge is switched on
    |--------------------------------------------------------------------------
    |
    | The team mailbox, used with plus-addressing: team+okonjima-bush-camp@…
    | delivers to the same box but says which property it is about. Demo
    | tenants address everything here, so a demo can send real mail through
    | the normal mailer and still never reach a lodge owner.
    |
    */

    'team_address' => env('BOOKING_TEAM_ADDRESS', 'team@namibway.com'),

    /*
    |--------------------------------------------------------------------------
    | The usual guest categories
    |--------------------------------------------------------------------------
    |
    | Offered to a property the first time it prices by guests, so setting up
    | is a choice rather than a configuration exercise — see
    | BOOKING_SYSTEM.md, "profiles, not configuration". A lodge edits them
    | afterwards; the age boundaries genuinely differ between properties,
    | which is why they are data and not an enum.
    |
    | These are the bands most Southern African lodges publish. They are a
    | starting point, not a rule, and a property that never touches them is
    | not thereby claiming anything about its own policy — nothing is created
    | until somebody asks for it.
    |
    */

    'guest_categories' => [
        ['code' => 'AD', 'name' => 'Adult', 'kind' => 'adult', 'min_age' => 12, 'max_age' => null, 'charge_percent' => 100, 'counts_toward_occupancy' => true],
        ['code' => 'CH', 'name' => 'Child', 'kind' => 'child', 'min_age' => 3, 'max_age' => 11, 'charge_percent' => 50, 'counts_toward_occupancy' => true],
        ['code' => 'IN', 'name' => 'Infant', 'kind' => 'infant', 'min_age' => 0, 'max_age' => 2, 'charge_percent' => 0, 'counts_toward_occupancy' => false],
    ],

    'demo' => [

        // How long a printed sign-in link stays valid. Long enough to survive
        // a meeting being moved, short enough that a link left in a chat
        // thread stops working.
        'sign_in_link_days' => (int) env('BOOKING_DEMO_SIGN_IN_DAYS', 14),

        // Weeks of seeded activity. Enough that scrolling the calendar keeps
        // finding a working business rather than running into empty grid.
        'weeks' => 12,
    ],
];
