@php

    use App\Services\Payments\DTOs\InvoiceLine;
    use App\Support\Money;

    /** @var \App\Models\Invoice $invoice */
    /** @var array<int, InvoiceLine> $lines */
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
{{--
    The PDF is a *rendering* of the invoice, not the invoice — every number
    here comes out of the frozen `lines` snapshot and nothing is recomputed
    from the stay. That is why no file is stored: the row is the document, and
    a second copy on disk is a copy that can drift from it.
--}}
<style>
    @page { margin: 2cm; }
    * { box-sizing: border-box; }
    body, h1, h2, p, table { margin: 0; padding: 0; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10.5px; color: #2c2521; background: #fff; }

    .eyebrow { font-size: 9.5px; letter-spacing: 0.5px; text-transform: uppercase; color: #c0533a; font-weight: bold; }
    h1 { font-size: 22px; color: #2c2521; margin: 2px 0 4px; }
    .number { font-size: 12px; color: #7a6a5e; margin-bottom: 20px; }

    .parties { width: 100%; margin-bottom: 22px; }
    .parties td { width: 50%; vertical-align: top; padding: 0; border: none; }
    .label { font-size: 9px; text-transform: uppercase; color: #a09080; margin-bottom: 3px; }
    .party { font-size: 11px; line-height: 1.5; }

    table.lines { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    table.lines th { text-align: left; font-size: 9px; text-transform: uppercase; color: #a09080; padding: 4px 8px 6px 0; border-bottom: 1px solid #e8e0d4; }
    table.lines td { padding: 7px 8px 7px 0; border-bottom: 1px dotted #e8e0d4; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }

    .included { color: #a09080; font-size: 9.5px; }

    table.totals { width: 45%; margin-left: 55%; border-collapse: collapse; margin-top: 10px; }
    table.totals td { padding: 4px 0; font-size: 11px; }
    table.totals .grand td { border-top: 2px solid #2c2521; font-size: 14px; font-weight: bold; padding-top: 8px; }

    .note { margin-top: 22px; padding: 10px 14px; background: #faf8f5; border-left: 3px solid #c0533a; font-size: 10px; color: #5b5346; }

    .footer { margin-top: 26px; padding-top: 10px; border-top: 1px solid #e8e0d4; font-size: 9px; color: #a09080; }
</style>
</head>
<body>

    <div class="eyebrow">{{ $invoice->issuer_name }}</div>
    <h1>{{ $invoice->kind->label() }}</h1>
    <div class="number">
        {{ $invoice->number }} · {{ $invoice->issued_at->isoFormat('D MMMM YYYY') }}
        @if ($invoice->corrects)
            · corrects {{ $invoice->corrects->number }}
        @endif
    </div>

    <table class="parties">
        <tr>
            <td>
                <div class="label">Billed to</div>
                <div class="party">
                    {{ $invoice->bill_to_name }}
                    @if ($invoice->bill_to_email)
                        <br>{{ $invoice->bill_to_email }}
                    @endif
                    @if ($invoice->bill_to_address)
                        <br>{!! nl2br(e($invoice->bill_to_address)) !!}
                    @endif
                </div>
            </td>
            <td>
                @if ($invoice->reservation)
                    <div class="label">Booking</div>
                    <div class="party">
                        {{ $invoice->reservation->reference }}<br>
                        {{ $invoice->reservation->check_in->isoFormat('D MMM YYYY') }}
                        &ndash; {{ $invoice->reservation->check_out->isoFormat('D MMM YYYY') }}
                    </div>
                @endif
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Qty</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>
                        {{ $line->description }}
                        {{-- A charge already inside the price is shown and not
                             added. "VAT 15% included" is the sentence a guest
                             asks about, and an invoice that never mentions the
                             VAT inside its own total gets disputed. --}}
                        @if ($line->isIncluded)
                            <span class="included">included</span>
                        @endif
                    </td>
                    <td class="num">{{ $line->quantity ?? '' }}</td>
                    <td class="num">
                        @if ($line->isIncluded)
                            <span class="included">{{ Money::format($line->amount, $invoice->currency) }}</span>
                        @else
                            {{ Money::format($line->amount, $invoice->currency) }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Subtotal</td>
            <td class="num">{{ Money::format($invoice->subtotal, $invoice->currency) }}</td>
        </tr>
        @if (Money::cents($invoice->tax_amount) !== 0)
            <tr>
                <td>VAT</td>
                <td class="num">{{ Money::format($invoice->tax_amount, $invoice->currency) }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td>Total</td>
            <td class="num">{{ Money::format($invoice->total, $invoice->currency) }}</td>
        </tr>
    </table>

    @if ($invoice->isCancelled())
        <div class="note">
            This invoice has been credited in full by {{ $invoice->creditNote?->number }} and is no longer payable.
        </div>
    @endif

    <div class="footer">
        {{ $invoice->number }} · issued {{ $invoice->issued_at->isoFormat('D MMMM YYYY, HH:mm') }}.
        An issued document is never changed; a correction is a credit note referencing it.
    </div>

</body>
</html>
