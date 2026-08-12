<?php

namespace App\Http\Controllers;

use App\Models\WebsiteTerms;
use Illuminate\Http\Response;

/**
 * Our terms for the website product, on our own host.
 *
 * Served as a plain page rather than through Inertia: it is linked from the
 * foot of every customer website, so it is read by people who arrived from a
 * host that ships none of the travel platform, and loading a JavaScript bundle
 * to show a legal text would be the one heavy page in an otherwise light
 * product.
 *
 * 404 while nothing is published, which is what `LegalText::termsUrl()` already
 * assumes: an unpublished draft is not a document anybody has agreed to.
 */
class WebsiteTermsController extends Controller
{
    public function __invoke(): Response
    {
        $terms = WebsiteTerms::current();

        abort_if($terms === null, 404);

        return response()->view('legal.website-terms', ['terms' => $terms]);
    }
}
