<?php

namespace App\Http\Controllers\Sites;

use App\Models\Site;
use App\Sites\Rendering\BusinessCard;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The digital business card: `/card`, the contact file behind it, and the QR
 * code that gets printed on the paper one.
 *
 * Reached through SiteController's path dispatcher, so a draft needs its
 * preview token like every other page of the site.
 */
class SiteCardController
{
    public function page(Site $site, string $accent): Response
    {
        return response()->view('sites.card', [
            'site' => $site,
            'accent' => $accent,
            'card' => BusinessCard::for($site),
        ]);
    }

    /** The contact file a phone offers to save. */
    public function vcard(Site $site): Response
    {
        $card = BusinessCard::for($site);

        return response($card->vcard(), 200, [
            'Content-Type' => 'text/vcard; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$card->filename().'"',
        ]);
    }

    /**
     * The QR for print. Same rule as the trader order code: a site with its own
     * domain gets its canonical URL, because a printed card cannot be changed;
     * the path fallback derives from the request, so a code scanned from a
     * laptop on the same network still reaches the page.
     */
    public function qr(Request $request, Site $site): Response
    {
        $url = filled($site->host)
            ? $site->pageUrl('card')
            : rtrim(Str::before($request->url(), '/card'), '/').'/card'
                .($site->isPublished() ? '' : '?preview='.$site->draft_token);

        $result = (new Builder(
            writer: new PngWriter,
            data: $url,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            // Printed on a business card: big enough that a phone reads it from
            // the back of one, and a PNG a printer will accept.
            size: 900,
            margin: 30,
        ))->build();

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($result->getString(), 200, [
            'Content-Type' => $result->getMimeType(),
            'Cache-Control' => 'public, max-age=86400',
            'Content-Disposition' => $disposition.'; filename="'.$site->slug.'-card-qr.png"',
        ]);
    }
}
