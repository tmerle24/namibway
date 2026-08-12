@php
    /**
     * The sticky bar. Transparent over a hero and solid once the page scrolls,
     * which is the state change the brief asks for and costs one class toggle.
     *
     * Links point at sections that actually exist on this page — a menu item
     * for a block that was switched off because there was nothing to put in it
     * would be a link to nothing.
     *
     * Where a site has more than one page, the pages come first and the
     * anchors of the current page fill whatever is left. That order is the
     * priority: getting to another page matters more than jumping within this
     * one, and a visitor who cannot see the other pages cannot know they exist.
     *
     * Below 1024px the same list is a panel behind a burger. It is not a
     * separate menu: one array renders twice, so a link can never be in one and
     * missing from the other.
     */
    $navTypes = ['about', 'highlights', 'gallery', 'opening_hours', 'price_list', 'enquiry', 'contact'];
    $items = [];
    $booking = null;
    $n = 0;

    // The site's other pages. pageUrl() rather than a bare slug, because a
    // draft is read at ?preview=<token> and a link that dropped the token would
    // land on a 404 in front of whoever is reviewing it.
    $pages = $site->pages()
        ->where('locale', $site->default_locale)
        ->orderByDesc('is_home')
        ->orderBy('sort')
        ->get();

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

    // Six is the whole menu: five items plus the one that says where you are.
    // It is not a matter of taste — the bar has a fixed height that the hero is
    // pulled up under by exactly that many pixels, so a menu allowed to grow
    // leaves a strip of background above the photograph. Booking is counted
    // separately below, as it always was.
    $cap = 6;

    if ($pages->count() > 1) {
        $links = [];

        foreach ($pages as $navPage) {
            $links[] = [
                'href' => $site->pageUrl($navPage->is_home ? null : $navPage->slug),
                'label' => $navPage->title ?: ($navPage->is_home ? 'Home' : $navPage->slug),
                'current' => $navPage->id === $page->id,
            ];
        }

        // Pages first and anchors after: getting to another page matters more
        // than jumping within this one, and a visitor who cannot see the other
        // pages cannot know they exist. Both are cut to the same total.
        $links = array_slice($links, 0, $cap);
        $items = array_merge($links, array_slice($items, 0, max(0, $cap - count($links))));
    } elseif ($items !== [] || $booking !== null) {
        // Home first, and only once there is somewhere else to go: a single-item
        // menu reading "Home" on the page you are already on is furniture.
        array_unshift($items, ['anchor' => 'top', 'label' => 'Home']);
        $items = array_slice($items, 0, $cap);
    }

    if ($booking !== null) {
        $items[] = $booking;
    }

@endphp
<header class="nav {{ $hasHero ? '' : 'nav--solid' }}" id="nav">
    <div class="nav__inner">
        @include('sites.partials.brand', ['href' => $pages->count() > 1 ? $site->pageUrl() : '#top'])

        @if ($items !== [])
            <nav class="nav__links">
                @foreach ($items as $item)
                    <a href="{{ $item['href'] ?? '#'.$item['anchor'] }}"
                       @if ($item['current'] ?? false) aria-current="page" @endif>{{ $item['label'] }}</a>
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
                <a href="{{ $item['href'] ?? '#'.$item['anchor'] }}"
                   @if ($item['current'] ?? false) aria-current="page" @endif>{{ $item['label'] }}</a>
            @endforeach
        </div>
    @endif
</header>
