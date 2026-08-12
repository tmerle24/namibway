@php
    use App\Sites\LegalText;
    use App\Sites\Rendering\SafeLink;

    /**
     * The strip at the very bottom, on the home page and on the legal pages
     * alike — so Privacy is never a page you can reach but not leave.
     *
     * `$data` is the footer block's payload where there is one, and nothing on
     * a legal page, which is why every read of it is guarded.
     */
    $data = $data ?? [];
    $terms = LegalText::termsUrl();
@endphp
{{-- Two rows rather than one wrapping one. Everything in a single flex line
     reflowed on a phone into a ragged block with "Powered by NamibWay" stranded
     on its own — so the links are their own row and the copyright is last,
     which is where a copyright belongs anyway. --}}
<div class="foot__legal">
    <div class="foot__row">
        @foreach (LegalText::pages() as $slug => $label)
            <a href="{{ $site->pageUrl($slug) }}">{{ $label }}</a>
        @endforeach

        @foreach ($data['links'] ?? [] as $link)
            <a href="{{ SafeLink::href($link['href']) }}">{{ $link['label'] }}</a>
        @endforeach

        {{-- Ours, not theirs, and it says so. A line at the bottom of every
             site we build is the only advertising this product does. --}}
        <span class="foot__powered">
            Powered by <a href="https://namibway.com" target="_blank" rel="noopener">NamibWay</a>@if ($terms)
                · <a href="{{ SafeLink::href($terms) }}" target="_blank" rel="noopener">Website terms</a>@endif
        </span>
    </div>

    <div class="foot__row foot__row--copy">
        <span>&copy; {{ now()->year }} {{ LegalText::copyright($site) }}</span>

        @if (filled($data['registration'] ?? null))
            <span>{{ $data['registration'] }}</span>
        @endif

        @if (filled($data['responsible_person'] ?? null))
            <span>Responsible: {{ $data['responsible_person'] }}</span>
        @endif
    </div>
</div>
