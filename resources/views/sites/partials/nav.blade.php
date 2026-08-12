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
    $navTypes = ['about', 'highlights', 'gallery', 'opening_hours', 'price_list', 'enquiry', 'contact'];
    $items = [];
    $booking = null;
    $n = 0;

    foreach ($blocks as $navBlock) {
        if (! in_array($navBlock->type, ['hero', 'footer'], true)) {
            $n++;
        }

        $label = $navBlock->data['heading'] ?? $navBlock->definition()?->label() ?? '';

        // Booking is held aside rather than queued with the rest. It is the
        // thing the site exists to do, so it cannot be the item that falls off
        // the end when a business has a lot to say.
        if ($navBlock->type === 'booking') {
            $booking = ['anchor' => 's'.$n, 'label' => $label];

            continue;
        }

        // Five is where a bar this size stops reading as a menu and starts
        // reading as a list. The panel is not so constrained, but the two have
        // to agree, so the cap is shared.
        if (in_array($navBlock->type, $navTypes, true) && count($items) < 5) {
            $items[] = ['anchor' => 's'.$n, 'label' => $label];
        }
    }

    // Home first, and only once there is somewhere else to go: a single-item
    // menu reading "Home" on the page you are already on is furniture.
    if ($items !== [] || $booking !== null) {
        array_unshift($items, ['anchor' => 'top', 'label' => 'Home']);
    }

    if ($booking !== null) {
        $items[] = $booking;
    }

    $logo = $site->logoUrl(400);
@endphp
<header class="nav {{ $hasHero ? '' : 'nav--solid' }}" id="nav">
    <div class="nav__inner">
        {{-- Over a hero the name is set twice — once here and once in type
             three lines below it — so the mark holds itself back until the
             hero has scrolled past. Only under `.js`, because the class that
             brings it back is added by script: without one it is simply
             always there. --}}
        <a class="nav__name {{ $hasHero ? 'nav__name--defer' : '' }}" href="#top">
            @if ($logo)
                <img src="{{ $logo }}" alt="{{ $site->name }}" class="nav__logo">
            @else
                {{ $site->name }}
            @endif
        </a>

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
