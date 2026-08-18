@php
    $paymentState = $inquiry->payment_state;
    $isPaid       = $paymentState === 'simulated_paid';
    $isCash       = $paymentState === 'awaiting_cash';
    $total        = $inquiry->total_amount;
    $currency     = $inquiry->currency ?? 'NAD';
@endphp
<x-mail::message>
# New order — {{ $inquiry->sellerName() }}

**{{ $inquiry->name }}** has placed an order through your website.

<x-mail::table>
| | |
|---|---|
| **Customer** | {{ $inquiry->name }} |
@if (filled($inquiry->email))
| **Email** | {{ $inquiry->email }} |
@endif
@if (filled($inquiry->phone))
| **WhatsApp / Phone** | {{ $inquiry->phone }} |
@endif
@if ($total)
| **Order total** | {{ number_format($total, 2) }} {{ $currency }} |
@endif
| **Payment** | @if ($isPaid) ✅ Paid (simulation — prototype) @elseif ($isCash) 💵 Cash on collection @else Pending @endif |
</x-mail::table>

## Items ordered

<x-mail::table>
| Item | Qty | Unit price | Line total |
|---|---|---|---|
@foreach ($inquiry->items as $item)
| {{ $item->name }} | {{ $item->quantity }} | {{ number_format($item->unit_price, 2) }} {{ $item->currency }} | {{ number_format($item->line_total, 2) }} {{ $item->currency }} |
@endforeach
@if ($total)
| **Total** | | | **{{ number_format($total, 2) }} {{ $currency }}** |
@endif
</x-mail::table>

@if ($inquiry->message)
**Customer note:**

{{ $inquiry->message }}
@endif

---

Please confirm that you can fulfil this order, or decline if you cannot.

<x-mail::button :url="$confirmUrl" color="success">
Confirm order
</x-mail::button>

<x-mail::button :url="$cancelUrl" color="error">
Decline order
</x-mail::button>

@if ($isCash)
> **Payment:** The customer will pay cash on collection. You receive the payment directly — NamibWay does not handle or hold your funds.
@elseif ($isPaid)
> **Payment:** The customer completed the demo payment simulation. In production this will be replaced by your PayToday payment link. NamibWay does not handle or hold your funds.
@endif

> This is a prototype. Confirm and decline links expire in 48 hours.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
