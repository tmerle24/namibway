<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class ListingsPartnerHandbookPdfController extends Controller
{
    /**
     * PDF export of the "Listings & Partner Handbook" admin page, for
     * printing or forwarding to whoever's doing the content/outreach work.
     * Gated to admins (not public like the partner-api-guide PDF) since it
     * describes internal workflow.
     */
    public function __invoke(): Response
    {
        abort_unless(auth()->user()?->is_admin, 403);

        $pdf = Pdf::loadView('pdf.listings-partner-handbook');

        return $pdf->download('namibway-listings-partner-handbook.pdf');
    }
}
