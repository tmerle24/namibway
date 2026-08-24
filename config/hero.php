<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Homepage hero photographs
    |--------------------------------------------------------------------------
    |
    | The homepage hero rotates: a different Namibian landscape every day, and
    | — when `include_illustration` is on — the original drawing (the
    | night-to-rust sky, the dune silhouettes, the dead tree, the sun) comes
    | back around as one of the slots. Variety is the point; the drawing is
    | part of the variety, not just the fallback.
    |
    | An empty list is a supported state: the hero is then the drawing every
    | day, exactly as it was before photographs existed.
    |
    | A photograph *replaces* the drawing rather than sitting behind it. Flat
    | vector dunes on top of a photograph of real dunes read as a sticker, so
    | the tree, the dune shapes and the sun step aside and the photo carries
    | the frame on its own.
    |
    | Each entry:
    |
    |   slug    Stable id. Used by the `?hero=<slug>` preview override, which
    |           is how you show somebody a specific one without waiting for
    |           its day to come round. `?hero=illustration` shows the drawing.
    |   file    Path under public/ (e.g. `images/hero/dune-hiker.jpg`), or a
    |           full URL if the file lives on R2.
    |   credit  Photographer, shown small in the hero's bottom corner. null
    |           when the licence needs no attribution — but check, do not
    |           assume: most licences do. The CC0 photos below name their
    |           photographer in a comment instead of on the page; set the
    |           field if you would rather credit them there too.
    |   focus   CSS object-position. A hero crops hard and crops differently
    |           per viewport — wide on desktop, tall in the mobile app — so
    |           which part of the frame has to survive is a property of the
    |           photograph, not of the layout. Worth knowing while you pick a
    |           value: the chat panel covers the middle-left of the hero and
    |           the headline sits above it, so a subject you want seen has to
    |           end up to the right of the panel or below it. That is why
    |           some values here look far from centre.
    |   scrim   'strong' (default) or 'light'. The scrim is what keeps the
    |           white headline readable, and 'strong' is set from the worst
    |           case: it holds 4.5:1 over a blown-white sky. Use 'light' only
    |           for a photograph that is already dark on its own — a night
    |           sky loses its stars under the strong one — and look at the
    |           result before shipping it, because with 'light' the contrast
    |           depends on the photo rather than on the scrim.
    |
    | What a new file needs to be: landscape, ~2560px wide (it runs full-bleed
    | on a desktop monitor and a 900px file visibly softens), JPEG, under
    | 500 KB — it is the largest thing on the page and it loads before
    | anything else. Keep the top third calm: the headline sits on it. And it
    | must be a photograph we hold the rights to publish — a listing's or a
    | directory's photos are not that (see ContentSource in CLAUDE.md).
    |
    */

    'include_illustration' => true,

    'photos' => [

        // Milky Way over a kitted-out 4x4 — the trip this site sells, in one
        // frame. Framed low so the figure on the roof clears the panel. Jonatan Pie (r3dmax), CC0 via Wikimedia Commons:
        // https://commons.wikimedia.org/wiki/File:Observing_space_(Unsplash).jpg
        [
            'slug' => 'namib-night-sky',
            'file' => 'images/hero/namib-night-sky.jpg',
            'credit' => null,
            'focus' => '50% 30%',
            'scrim' => 'light',
        ],

        // Dune ridge at sunrise, footprints along the crest — the same
        // night-to-gold-to-rust run the brand palette is built on.
        // Jonatan Pie (r3dmax), CC0:
        // https://commons.wikimedia.org/wiki/File:Standing_on_top_of_a_sand_dune_(Unsplash).jpg
        [
            'slug' => 'dune-ridge-sunrise',
            'file' => 'images/hero/dune-ridge-sunrise.jpg',
            'credit' => null,
            'focus' => '50% 55%',
        ],

        // One walker on an orange dune under an enormous empty sky. Framed
        // high so the walker clears the bottom of the chat panel instead of
        // standing behind it.
        // Titus Aparici, CC0:
        // https://commons.wikimedia.org/wiki/File:Feeling_Small_(Unsplash).jpg
        [
            'slug' => 'dune-hiker',
            'file' => 'images/hero/dune-hiker.jpg',
            'credit' => null,
            'focus' => '50% 14%',
        ],

        // Where the dunes meet the gravel plain near Gobabeb, deep blue sky.
        // Jonatan Pie (r3dmax), CC0:
        // https://commons.wikimedia.org/wiki/File:Gobabeb,_Namibia_(Unsplash).jpg
        [
            'slug' => 'namib-dune-edge',
            'file' => 'images/hero/namib-dune-edge.jpg',
            'credit' => null,
            'focus' => '50% 55%',
        ],

        // An oryx on the plain — Namibia's national animal, and the one
        // photograph here with an animal in it. Off-centre on purpose: the
        // headline sits left, the oryx stands right.
        // Andy Brunner, CC0:
        // https://commons.wikimedia.org/wiki/File:Wildlife_in_the_Desert_(Unsplash).jpg
        [
            'slug' => 'oryx-plain',
            'file' => 'images/hero/oryx-plain.jpg',
            'credit' => null,
            'focus' => '68% 55%',
        ],

        // Quiver trees against a hard blue sky near Keetmanshoop — the only
        // one of these that isn't sand, and the silhouettes rhyme with the
        // drawing's dead tree. CC BY 3.0, so this one is credited on the
        // page; that is the licence's condition, not a preference.
        // Pavel Špindler:
        // https://commons.wikimedia.org/wiki/File:Quiver_tree_forest,_Aloe_rozsochat%C3%A1_-_Namibie_-_panoramio.jpg
        [
            'slug' => 'quiver-trees',
            'file' => 'images/hero/quiver-trees.jpg',
            'credit' => 'Pavel Špindler (CC BY 3.0)',
            'focus' => '45% 55%',
        ],

    ],

];
