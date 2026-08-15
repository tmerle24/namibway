<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where customer websites live
    |--------------------------------------------------------------------------
    |
    | Every site is resolved by its host, looked up as an exact string against
    | the `host` column. A pattern would have been cheaper until the first
    | customer moves their own .com.na across — the flyer sells the domain as
    | included, so a custom domain is not a later luxury, it is what a paying
    | customer gets. An exact lookup treats both the same and makes the move an
    | UPDATE rather than a migration.
    |
    | `host_suffix` is only used to *mint* a default host when a site is
    | created ({slug}.websites.namibway.com). Leave it empty and sites are
    | created without a host and reachable only at the path fallback below,
    | which is what local development and CI do — no hosts file, no wildcard
    | certificate, no DNS.
    |
    | The prerequisite is server-side and outside the application: a wildcard
    | A record for *.websites.namibway.com, a wildcard certificate covering it
    | (namibway.com's DNS is at OVH, not Cloudflare — see the 2026-08-09
    | incident in CLAUDE.md), and an nginx server block. See DEPLOYMENT.md.
    |
    */

    'host_suffix' => env('SITES_HOST_SUFFIX'),

    /*
    | The path a site is additionally reachable at on the app's own host —
    | /_sites/{slug}. This is how a site is reviewed before DNS exists, and how
    | the renderer is tested at all. It is deliberately not a pretty URL: it is
    | a back door for us, not an address we give anybody.
    */

    'path_prefix' => '_sites',

    /*
    | Hosts that must never resolve to a customer site, whatever the database
    | says. The app's own host and the booking panel are the ones that would
    | actually hurt: a row with host = 'namibway.com' would otherwise replace
    | the travel platform with somebody's restaurant.
    */

    'reserved_hosts' => array_values(array_filter([
        parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST),
        env('BOOKING_PANEL_DOMAIN'),
    ])),

    /*
    |--------------------------------------------------------------------------
    | Paths that still belong to the platform on a site's host
    |--------------------------------------------------------------------------
    |
    | A site host is served by the same application, so a handful of paths have
    | to keep working there. /thumbs is the important one: images are emitted
    | root-relative so they travel over the connection the browser has already
    | opened, which on a slow Namibian mobile link is worth more than sharing a
    | cache across hosts.
    |
    */

    'passthrough_paths' => [
        'thumbs/*',
        'up',
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance budget for the first view
    |--------------------------------------------------------------------------
    |
    | Not decoration — the flyer promises "an old phone and a slow connection",
    | and a page that stays white for three seconds reads as broken rather than
    | as cheap. The numbers are checked by a test, so a block that blows the
    | budget fails CI instead of being discovered by a customer.
    |
    | `document_bytes` covers the delivered HTML including the inlined CSS and
    | JavaScript, uncompressed — everything the browser needs before it can
    | paint. Images are excluded because they stream in afterwards and are
    | governed by the width ladder in config/media.php instead.
    |
    */

    'budget' => [
        // Raised from 72 KB to 76 KB: the motion.blade.php partial (scroll-reveal
        // JS) is now inlined on every page, the inline styles grew with new block
        // types (enquiry, shop), and the block library reached 16 types. Still well
        // under the 100 KB first-view target that separates "fast" from "acceptable".
        'document_bytes' => 76 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Accents
    |--------------------------------------------------------------------------
    |
    | One template serves a lodge, a workshop and a restaurant. The base
    | palette is mineral and neutral for exactly that reason; the accent is the
    | one colour that changes, and it is chosen from this list rather than
    | typed, so no site can end up with an unreadable pairing.
    |
    | Keys are stored in the database. Changing a value here restyles every
    | site using it, which is the point.
    |
    */

    'accents' => [
        'copper' => '#9C4A21',
        'kalahari' => '#B07A2A',
        'acacia' => '#4F6B3A',
        'atlantic' => '#1F5A6B',
        'plum' => '#5C3A56',
    ],

    'default_accent' => 'copper',

    /*
    | Which accent a generated site starts with, by business type. A starting
    | point somebody overrides, not a rule about the trade.
    */

    'type_accents' => [
        'accommodation' => 'kalahari',
        'restaurant' => 'copper',
        'activity' => 'acacia',
        'car_rental' => 'atlantic',
        'tour_operator' => 'atlantic',
        'retail' => 'plum',
        'service' => 'copper',
    ],

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    |
    | Site images are copied into the site's own prefix on the shared bucket,
    | never referenced from a listing. A deletion on the listing side must not
    | be able to break a live customer site — that independence is what makes
    | "you keep your content" true rather than a slogan.
    |
    */

    'media_prefix' => 'sites',

    /*
    |--------------------------------------------------------------------------
    | Publishing a site that leans on Google Places photographs
    |--------------------------------------------------------------------------
    |
    | **On by default, decided 2026-08-12.** The cautious reading was the other
    | way round and it was costing time at the wrong end of the project: there
    | is no customer to protect until there is a customer, and a draft nobody
    | can show wins none.
    |
    | The trade it accepts, so that nobody has to rediscover it: Google's
    | photographs are publishable on namibway.com under Google's terms and they
    | expire (google_photos_expire_at), neither of which is true of a site we
    | have told a customer is theirs to keep if they leave. Set this to false
    | once real customers are on real sites, and the images stay marked
    | `prospect_only` in the meantime so the question can be asked again by
    | query rather than by memory.
    |
    | Nothing about `directory` content changes here. That is not ours to
    | publish anywhere, draft included, and it is not a switch.
    |
    */

    'allow_google_photos_when_published' => (bool) env('SITES_ALLOW_GOOGLE_PHOTOS', true),

    /*
    |---------------------------------------------------------------------------
    | Our own terms for the website product
    |---------------------------------------------------------------------------
    |
    | Linked from the foot of every customer site, and named in the confirmation
    | the business gives when the site is published — publishing under somebody
    | else's name is the moment they have to have agreed to both their own legal
    | pages and to ours.
    |
    | Empty until that page is written, and empty means no link. A Terms link
    | that 404s in front of a prospective customer is worse than no link.
    |
    */

    'terms_url' => env('SITES_TERMS_URL', ''),

    /*
    |---------------------------------------------------------------------------
    | The address a customer points their own domain at
    |---------------------------------------------------------------------------
    |
    | The public IPv4 of this server. It is shown to the customer in the copy
    | and paste instructions, and it is what an A record is compared against
    | before we try to issue a certificate.
    |
    | Empty disables the whole custom-domain flow rather than guessing: an A
    | record checked against nothing would either pass everything or fail
    | everything, and both are worse than saying it is not configured.
    |
    */

    'server_ip' => env('SITES_SERVER_IP', ''),

];
