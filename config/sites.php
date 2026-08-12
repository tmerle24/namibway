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
        'document_bytes' => 60 * 1024,
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
    | Off by default, and the default is the cautious reading rather than the
    | obvious one. Those photographs are publishable on namibway.com under
    | Google's terms and they expire (google_photos_expire_at) — neither is true
    | of a website we have told a customer is theirs to keep if they leave. So
    | the gate refuses them at publication while still allowing them on a draft,
    | which is where they earn their keep: winning the meeting.
    |
    | It is a switch and not a rule because it is a commercial judgement, not a
    | technical one, and it is not the code's to make. Turning it on publishes
    | Google's photography on a customer's own site; if that is the trade you
    | want, this is where you say so.
    |
    */

    'allow_google_photos_when_published' => (bool) env('SITES_ALLOW_GOOGLE_PHOTOS', false),

];
