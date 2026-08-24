<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Who operates namibway.com
    |--------------------------------------------------------------------------
    |
    | The operator details printed on /legal. Every field is optional and an
    | empty one simply does not render — but until `name` is filled the page
    | says so plainly instead of showing a legal notice with holes in it.
    | Nothing here may be guessed: an operator's address, register entry and
    | tax number are facts about a company, and a plausible-looking one is
    | worse than an absent one.
    |
    | The operating company is being registered in Namibia (as of
    | 2026-08-24), so the fields to fill as they become facts are `name`,
    | `address`, `email`/`phone` and `registration_number`. `register`,
    | `vat_id` and `content_responsible` are named the way German law names
    | them (§5 DDG, §18 MStV) and stay in the list because they cost nothing
    | empty and because most of this site's travellers read it from Europe.
    | Which fields are actually *required* is a question for NamibWay's own
    | legal advice, not for this file.
    |
    | `country` is filled in already because it is the one operator fact that
    | is settled. It renders nothing on its own: the block appears only once
    | `name` does.
    |
    | `notes` is free text printed at the end of the operator block, for
    | whatever a lawyer asks to be added — a dispute-resolution statement, a
    | supervisory authority, a professional-body reference.
    |
    */

    'operator' => [
        'name' => null,
        'legal_form' => null,
        'address' => [],
        'country' => 'Namibia',
        'represented_by' => [],
        'register' => null,
        'registration_number' => null,
        'vat_id' => null,
        'email' => null,
        'phone' => null,
        'content_responsible' => null,
        'notes' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Copyright
    |--------------------------------------------------------------------------
    |
    | Who the © line names. The year is not stored: it is the current one,
    | because a hardcoded year is a maintenance task nobody remembers and a
    | site that looks abandoned every January.
    |
    */

    'copyright_holder' => 'NamibWay',

    /*
    |--------------------------------------------------------------------------
    | Image credits beyond the hero
    |--------------------------------------------------------------------------
    |
    | The homepage hero photographs credit themselves — /legal renders them
    | from config/hero.php, so that list can never fall behind what the site
    | shows. This is for everything else whose provenance we know and record
    | by hand: illustrations, section imagery, stock we licensed.
    |
    | Same four fields as a hero photo's provenance: title, photographer,
    | license, source. An entry with no `title` is skipped.
    |
    | Deliberately empty rather than filled with guesses. The category
    | placeholder images under public/images/explore/ predate this file and
    | their origin is not recorded anywhere; listing photographs are not
    | listed here at all, because they belong to the partners and sources
    | described on the page itself and are credited on the listing.
    |
    */

    'image_credits' => [
        // [
        //     'title' => 'Explore category placeholders',
        //     'photographer' => 'Jane Doe',
        //     'license' => 'Unsplash Licence',
        //     'source' => 'https://unsplash.com/photos/...',
        // ],
    ],

];
