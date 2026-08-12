<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\Payments\InvoiceDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Renders an issued invoice as a PDF for whoever is entitled to see it.
 *
 * There is no stored file, so this route is the only way to the document and
 * the check below *is* the access rule — the same arrangement
 * DocumentDownloadController has, and for the same reason: an invoice names a
 * guest and says what they paid.
 *
 * Who may see one: our own staff, and the property the document belongs to.
 * Not "anyone with the id" — invoice numbers are sequential by design, so a
 * missing check here would let one lodge read every other lodge's takings by
 * counting upwards.
 */
class InvoiceDownloadController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice): Response
    {
        $user = $request->user();

        abort_unless($user !== null, 403);

        $isOurs = (bool) $user->is_admin;
        $isTheirs = $invoice->partner_id !== null && $invoice->partner_id === $user->partner_id;

        abort_unless($isOurs || $isTheirs, 403);

        $document = app(InvoiceDocument::class);

        // Inline rather than as a download: an invoice is looked at far more
        // often than it is filed, and a desk checking a number against a guest
        // standing there does not want it in a downloads folder first.
        return $document->render($invoice)->stream($document->filename($invoice));
    }
}
