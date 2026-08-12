@php
    /**
     * The business's mark in the bar: its logo, or its name set in type.
     *
     * Shared by the site's own bar and the legal pages', so a long name steps
     * down to the same size in both. `$href` is where the mark points — the top
     * of this page on the site itself, back to the site from a legal page.
     *
     * The bar has a fixed height (see the stylesheet), so the name is one line
     * whatever happens: two size steps first, then the ellipsis as a backstop.
     */
    $logo = $site->logoUrl(400);
    $length = mb_strlen($site->name);
    $nameClass = match (true) {
        $length > 30 => 'nav__name--longer',
        $length > 20 => 'nav__name--long',
        default => '',
    };
@endphp
<a class="nav__name {{ $nameClass }}" href="{{ $href }}">
    @if ($logo)
        <img src="{{ $logo }}" alt="{{ $site->name }}" class="nav__logo">
    @else
        {{ $site->name }}
    @endif
</a>
