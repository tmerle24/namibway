@props(['inquiry'])
@php
    use App\Enums\InquiryKind;
@endphp
{{--
    What was actually asked for, in the shape the request was made.

    One partial for the three emails that all had to answer the same question —
    the partner's confirm/decline request, the guest's copy, and the guest's
    confirmation. Before restaurants had a form of their own each of them
    printed check-in, check-out and a guest count regardless, so an order for
    two burgers arrived as a stay with no dates and a party of two, which is
    three wrong facts in a row. Kept together here so a fourth email cannot
    reintroduce them.
--}}
@if ($inquiry->kind === InquiryKind::Order)
<x-mail::table>
| Item | Qty | Price |
|:---|---:|---:|
@foreach ($inquiry->items as $item)
| {{ $item->name }} | {{ $item->quantity }} | {{ $item->currency }} {{ number_format($item->line_total, 2) }} |
@endforeach
| **Total** | | **{{ $inquiry->currency ?? 'NAD' }} {{ number_format((float) $inquiry->total_amount, 2) }}** |
</x-mail::table>
@elseif ($inquiry->kind === InquiryKind::TableReservation)
<x-mail::table>
| | |
|---|---|
| **Date** | {{ $inquiry->check_in?->format('D, d M Y') ?? $inquiry->travel_dates ?? '—' }} |
| **Time** | {{ $inquiry->arrivalTimeLabel() ?? '—' }} |
| **Party** | {{ $inquiry->adults }} adult{{ $inquiry->adults !== 1 ? 's' : '' }}@if($inquiry->children > 0), {{ $inquiry->children }} child{{ $inquiry->children !== 1 ? 'ren' : '' }}@endif |
</x-mail::table>
@else
<x-mail::table>
| | |
|---|---|
| **Check-in** | {{ $inquiry->check_in?->format('D, d M Y') ?? $inquiry->travel_dates ?? '—' }} |
| **Check-out** | {{ $inquiry->check_out?->format('D, d M Y') ?? '—' }} |
| **Guests** | {{ $inquiry->adults }} adult{{ $inquiry->adults !== 1 ? 's' : '' }}@if($inquiry->children > 0), {{ $inquiry->children }} child{{ $inquiry->children !== 1 ? 'ren' : '' }}@endif |
</x-mail::table>
@endif
