<?php

namespace App\Http\Controllers;

use App\Support\LegalNotice;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The legal notice / imprint, and the credits that belong with it.
 *
 * Everything on the page is either a fact somebody entered in config/legal.php
 * or a fact read off the site's own configuration — the hero photographs
 * credit themselves out of config/hero.php. Nothing is written here that reads
 * like legal advice; see App\Support\LegalNotice.
 */
class LegalController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Legal', [
            'operator' => LegalNotice::operator(),
            'copyright' => LegalNotice::copyright(),
            'imageCredits' => LegalNotice::imageCredits(),
        ]);
    }
}
