@php
    /**
     * The business's mark in the bar: its logo, or its name set in type.
     *
     * Shared by the site's own bar and the legal pages', so a long name steps
     * down to the same size in both. `$href` is where the mark points — the top
     * of this page on the site itself, back to the site from a legal page.
     *
     * The bar has a fixed height (see the stylesheet), but the name is allowed
     * to wrap inside it — up to two lines, never cut mid-word. Its size is a
     * custom property the stylesheet sets from App\Sites\Typography: computed
     * from the length of the name, or whatever the business chose instead.
     */
    $logo = $site->logoUrl(400);
@endphp
<a class="nav__name {{ $logo ? 'nav__name--logo' : '' }}" href="{{ $href }}">
    @if ($logo)
        <img src="{{ $logo }}" alt="{{ $site->name }}" class="nav__logo">
    @else
        {{-- Site::brandName(), not the name: the registered name belongs in the
             page title and the legal notice, and in a 64px bar it wrapped
             wherever the browser felt like — "…Hunting / Safari". A line break
             typed into that field is honoured here and nowhere else. --}}
        {!! nl2br(e($site->brandName())) !!}
    @endif
</a>
