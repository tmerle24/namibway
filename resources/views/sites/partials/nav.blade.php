@php
    /**
     * The sticky bar. Transparent over a hero and solid once the page scrolls,
     * which is the state change the brief asks for and costs one class toggle.
     *
     * Links point at sections that actually exist on this page — a menu item
     * for a block that was switched off because there was nothing to put in it
     * would be a link to nothing.
     *
     * Below 800px the same list is a panel behind a burger. It is not a
     * separate menu: one array renders twice, so a link can never be in one and
     * missing from the other.
     */
    $navTypes = ['about', 'highlights', 'gallery', 'opening_hours', 'price_list', 'booking', 'enquiry', 'contact'];
    $items = [];
    $n = 0;

    foreach ($blocks as $navBlock) {
        if (! in_array($navBlock->type, ['hero', 'footer'], true)) {
            $n++;
        }

        // Five is where a bar this size stops reading as a menu and starts
        // reading as a list. The panel is not so constrained, but they have to
        // agree, so the cap is shared.
        if (in_array($navBlock->type, $navTypes, true) && count($items) < 5) {
            $items[] = [
                'anchor' => 's'.$n,
                'label' => $navBlock->data['heading'] ?? $navBlock->definition()?->label() ?? '',
            ];
        }
    }
@endphp
<header class="nav {{ $hasHero ? '' : 'nav--solid' }}" id="nav">
    <div class="nav__inner">
        <a class="nav__name" href="#top">{{ $site->name }}</a>

        @if ($items !== [])
            <nav class="nav__links">
                @foreach ($items as $item)
                    <a href="#{{ $item['anchor'] }}">{{ $item['label'] }}</a>
                @endforeach
            </nav>

            {{-- Only ever shown by the script that can close it again. A burger
                 on a page with no JavaScript is a button that does nothing, and
                 the links below are reachable by scrolling anyway. --}}
            <button class="nav__burger" id="nav-burger" type="button"
                    aria-expanded="false" aria-controls="nav-panel" aria-label="Menu" hidden>
                <span></span><span></span><span></span>
            </button>
        @endif
    </div>

    @if ($items !== [])
        <div class="nav__panel" id="nav-panel" hidden>
            @foreach ($items as $item)
                <a href="#{{ $item['anchor'] }}">{{ $item['label'] }}</a>
            @endforeach
        </div>
    @endif
</header>
