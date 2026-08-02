<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class PartnerApiGuideController extends Controller
{
    /**
     * A one-page PDF quickstart for partners/OTAs integrating against the
     * public listings API — separate from the full interactive reference at
     * /docs (Scribe-generated), meant to be forwarded by email alongside a
     * token (see the "API Partner Setup" guide in the admin panel).
     */
    public function __invoke(): Response
    {
        $pdf = Pdf::loadView('pdf.partner-api-guide', [
            'baseUrl' => rtrim(config('scribe.base_url'), '/'),
        ]);

        return $pdf->download('namibway-api-guide.pdf');
    }
}
