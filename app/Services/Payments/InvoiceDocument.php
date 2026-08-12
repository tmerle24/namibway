<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Services\Payments\DTOs\InvoiceLine;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfWrapper;

/**
 * Renders an issued invoice as a PDF.
 *
 * A derivative, on demand, from the frozen snapshot — never a stored file.
 * Two reasons, and both have already bitten this codebase once:
 *
 * - **Privacy.** An invoice carries a named guest and what they paid. Our
 *   media bucket is public and R2 has no per-object visibility, which is
 *   exactly why the Documents store keeps its files on the private disk. A PDF
 *   written to R2 would be readable by anyone who guessed the key.
 * - **Drift.** A stored file is a second copy of an immutable document. The
 *   row cannot change, so the file cannot be wrong — until somebody changes
 *   how the template renders, and then the archive and the live render
 *   disagree with no way to tell which was sent.
 *
 * The snapshot is what makes this safe: rendering reads `lines` and nothing
 * else, so a rate plan renamed or a VAT rate changed afterwards cannot alter
 * what comes out.
 */
class InvoiceDocument
{
    public function render(Invoice $invoice): PdfWrapper
    {
        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice->load(['reservation', 'corrects', 'creditNote']),
            'lines' => $this->lines($invoice),
        ]);
    }

    /** What the file is called when it lands in somebody's downloads folder. */
    public function filename(Invoice $invoice): string
    {
        return strtolower($invoice->number).'.pdf';
    }

    /**
     * @return array<int, InvoiceLine>
     */
    private function lines(Invoice $invoice): array
    {
        return array_map(
            fn (array $row): InvoiceLine => InvoiceLine::fromArray($row),
            $invoice->lineItems(),
        );
    }
}
