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
    |   slug           Stable id. Used by the `?hero=<slug>` preview override,
    |                  which is how you show somebody a specific one without
    |                  waiting for its day to come round. `?hero=illustration`
    |                  shows the drawing.
    |   file           Path under public/ (e.g. `images/hero/dune-hiker.jpg`),
    |                  or a full URL if the file lives on R2.
    |   title          One line naming the picture. Shown on /legal.
    |   photographer   Who took it.
    |   license        The licence we hold it under, spelled the way the
    |                  licence spells itself ('CC0 1.0', 'CC BY 3.0').
    |   source         Where it came from, as a URL somebody can open and
    |                  check. These four fields are not decoration: they are
    |                  what the credits section of /legal is rendered from
    |                  (App\Support\LegalNotice), so the page cannot drift
    |                  from what the site actually shows. A photo with no
    |                  provenance recorded here is a photo we cannot prove we
    |                  may use — do not add one.
    |   credit_on_hero Whether to print the credit over the hero itself. Set
    |                  it whenever the licence requires attribution; /legal
    |                  names the photographer either way, but a licence that
    |                  says "credit the author" is not satisfied by a page
    |                  three clicks away. Defaults to false.
    |   focus          CSS object-position. A hero crops hard and crops
    |                  differently per viewport — wide on desktop, tall in the
    |                  mobile app — so which part of the frame has to survive
    |                  is a property of the photograph, not of the layout.
    |                  Worth knowing while you pick a value: the chat panel
    |                  covers the middle-left of the hero and the headline
    |                  sits above it, so a subject you want seen has to end up
    |                  to the right of the panel or below it. That is why some
    |                  values here look far from centre.
    |   scrim          'strong' (default) or 'light'. The scrim is what keeps
    |                  the white headline readable, and 'strong' is set from
    |                  the worst case: it holds 4.5:1 over a blown-white sky.
    |                  Use 'light' only for a photograph that is already dark
    |                  on its own — a night sky loses its stars under the
    |                  strong one — and look at the result before shipping it,
    |                  because with 'light' the contrast depends on the photo
    |                  rather than on the scrim.
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

        [
            'slug' => 'namib-night-sky',
            'file' => 'images/hero/namib-night-sky.jpg',
            // The trip this site sells, in one frame. Framed low so the
            // figure on the roof clears the bottom of the chat panel.
            'title' => 'The Milky Way over a 4x4 in the Namib',
            'photographer' => 'Jonatan Pie',
            'license' => 'CC0 1.0',
            'source' => 'https://commons.wikimedia.org/wiki/File:Observing_space_(Unsplash).jpg',
            'credit_on_hero' => false,
            'focus' => '50% 30%',
            'scrim' => 'light',
        ],

        [
            'slug' => 'dune-ridge-sunrise',
            'file' => 'images/hero/dune-ridge-sunrise.jpg',
            // The same night-to-gold-to-rust run the brand palette is built on.
            'title' => 'A dune ridge at sunrise',
            'photographer' => 'Jonatan Pie',
            'license' => 'CC0 1.0',
            'source' => 'https://commons.wikimedia.org/wiki/File:Standing_on_top_of_a_sand_dune_(Unsplash).jpg',
            'credit_on_hero' => false,
            'focus' => '50% 55%',
        ],

        [
            'slug' => 'dune-hiker',
            'file' => 'images/hero/dune-hiker.jpg',
            // Framed high so the walker clears the bottom of the chat panel
            // instead of standing behind it.
            'title' => 'A walker on a dune under an empty sky',
            'photographer' => 'Titus Aparici',
            'license' => 'CC0 1.0',
            'source' => 'https://commons.wikimedia.org/wiki/File:Feeling_Small_(Unsplash).jpg',
            'credit_on_hero' => false,
            'focus' => '50% 14%',
        ],

        [
            'slug' => 'namib-dune-edge',
            'file' => 'images/hero/namib-dune-edge.jpg',
            'title' => 'Where the dunes meet the gravel plain, near Gobabeb',
            'photographer' => 'Jonatan Pie',
            'license' => 'CC0 1.0',
            'source' => 'https://commons.wikimedia.org/wiki/File:Gobabeb,_Namibia_(Unsplash).jpg',
            'credit_on_hero' => false,
            'focus' => '50% 55%',
        ],

        [
            'slug' => 'oryx-plain',
            'file' => 'images/hero/oryx-plain.jpg',
            // Off-centre on purpose: the headline sits left, the oryx right.
            'title' => 'An oryx on the plain',
            'photographer' => 'Andy Brunner',
            'license' => 'CC0 1.0',
            'source' => 'https://commons.wikimedia.org/wiki/File:Wildlife_in_the_Desert_(Unsplash).jpg',
            'credit_on_hero' => false,
            'focus' => '68% 55%',
        ],

        [
            'slug' => 'quiver-trees',
            'file' => 'images/hero/quiver-trees.jpg',
            // The only one of these that isn't sand, and the silhouettes
            // rhyme with the drawing's dead tree. CC BY, so it is credited on
            // the hero itself — the licence's condition, not a preference.
            'title' => 'Quiver trees near Keetmanshoop',
            'photographer' => 'Pavel Špindler',
            'license' => 'CC BY 3.0',
            'source' => 'https://commons.wikimedia.org/wiki/File:Quiver_tree_forest,_Aloe_rozsochat%C3%A1_-_Namibie_-_panoramio.jpg',
            'credit_on_hero' => true,
            'focus' => '45% 55%',
        ],

    ],

];
