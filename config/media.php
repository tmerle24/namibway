<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Image transformations (thumbnails)
    |--------------------------------------------------------------------------
    |
    | Thumbnails are produced on the fly by Cloudflare Image Transformations
    | (`/cdn-cgi/image/<options>/<path>`) rather than by generating and storing
    | derivative files. Nothing is written anywhere: the same stored original
    | is served resized, so there is no backfill to run over the existing
    | library and no second copy to keep in sync.
    |
    | This only works for URLs served through a Cloudflare-proxied zone that
    | has Transformations enabled. The default `pub-<hash>.r2.dev` bucket URL
    | does NOT qualify — a custom domain has to be attached to the media
    | bucket first. See DEPLOYMENT.md → "Bild-Thumbnails".
    |
    | While this is disabled every helper still returns the untouched original
    | URL, so the app renders correctly either way; it just doesn't get the
    | size win yet. Unsplash URLs are resized regardless (see MediaUrl), since
    | that runs off their own query parameters and costs us nothing.
    |
    */

    'transforms' => [

        'enabled' => (bool) env('MEDIA_TRANSFORMS_ENABLED', false),

        /*
         | Origins whose URLs may be rewritten through `/cdn-cgi/image/`. A URL
         | qualifies when it is root-relative (so: our own origin) or sits
         | under one of these. Anything else is returned untouched — a scraped
         | operator's website is not ours to proxy, and rewriting it would just
         | produce a 404.
         */
        'origins' => array_values(array_filter([
            env('CLOUDFLARE_R2_URL'),
            env('APP_URL'),
        ])),

        /*
         | Cloudflare bills per *unique* transformation, so requested widths are
         | snapped up to this ladder instead of being passed through verbatim —
         | otherwise the 44px day thumbnail, the 48px swap-modal thumbnail and
         | the 52px stay-card thumbnail would each bill their own variant for
         | what the eye reads as the same image. Keep the ladder short, and
         | spaced widely enough that a 1x and a 2x request for the same slot
         | still land on different rungs (they should, that's the point).
         */
        'widths' => [64, 128, 256, 400, 800, 1600],

        'quality' => (int) env('MEDIA_TRANSFORMS_QUALITY', 80),
    ],

];
