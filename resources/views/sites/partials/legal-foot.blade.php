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
<div class="foot__legal">
    <span>&copy; {{ now()->year }} {{ LegalText::copyright($site) }}</span>

    @if (filled($data['registration'] ?? null))
        <span>{{ $data['registration'] }}</span>
    @endif

    @if (filled($data['responsible_person'] ?? null))
        <span>Responsible: {{ $data['responsible_person'] }}</span>
    @endif

    @foreach (LegalText::pages() as $slug => $label)
        <a href="{{ $site->pageUrl($slug) }}">{{ $label }}</a>
    @endforeach

    @foreach ($data['links'] ?? [] as $link)
        <a href="{{ SafeLink::href($link['href']) }}">{{ $link['label'] }}</a>
    @endforeach

    {{-- Ours, not theirs, and it says so. A line at the bottom of every site we
         build is the only advertising this product does. --}}
    <span class="foot__powered">
        Powered by <a href="https://namibway.com" target="_blank" rel="noopener">NamibWay</a>@if ($terms)
            · <a href="{{ SafeLink::href($terms) }}" target="_blank" rel="noopener">Website terms</a>@endif
    </span>
</div>
