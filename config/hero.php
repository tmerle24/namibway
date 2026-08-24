<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Homepage hero photographs
    |--------------------------------------------------------------------------
    |
    | The homepage hero is an illustration by default — the night-to-rust sky,
    | the dune silhouettes, the dead tree, the sun. List photographs here and
    | the hero shows one of them instead, rotating to the next one every day.
    | An empty list is a supported state, not a broken one: the illustrated
    | hero is what renders, exactly as before.
    |
    | The photograph *replaces* the illustration rather than sitting behind
    | it. Flat vector dunes drawn on top of a photograph of real dunes read
    | as a sticker, so the tree, the dune shapes and the sun step aside and
    | the photo carries the frame on its own.
    |
    | Each entry:
    |
    |   slug    Stable id. Only used by the `?hero=<slug>` preview override,
    |           which is how you show somebody a specific photo without
    |           waiting for its day to come round.
    |   file    Path under public/ (e.g. `images/hero/sossusvlei-dawn.jpg`),
    |           or a full URL if the file lives on R2.
    |   credit  Photographer/agency, shown small in the hero's bottom corner.
    |           null when the licence needs no attribution — but check, do
    |           not assume: most stock licences do.
    |   focus   CSS object-position. A hero crops hard and crops differently
    |           per viewport — wide on desktop, tall in the mobile app — so
    |           which part of the frame has to survive is a property of the
    |           photograph, not of the layout. Defaults to the centre.
    |
    | What a file needs to be: landscape, at least 2400px wide (it runs
    | full-bleed on a desktop monitor and a 900px file visibly softens),
    | JPEG, compressed to well under 500 KB — it is the largest thing on the
    | page and it loads before anything else does. Keep the top third calm:
    | the headline is white and sits on it. And it must be a photograph we
    | actually hold the rights to publish — a listing's or a directory's
    | photos are not that (see ContentSource in CLAUDE.md).
    |
    */

    'photos' => [
        // [
        //     'slug' => 'sossusvlei-dawn',
        //     'file' => 'images/hero/sossusvlei-dawn.jpg',
        //     'credit' => 'Jane Doe / Unsplash',
        //     'focus' => '50% 65%',
        // ],
    ],

];
