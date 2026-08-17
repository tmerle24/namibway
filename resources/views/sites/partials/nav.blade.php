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
    $items = [];
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
        if (in_array($navBlock->type, ['hero', 'footer'], true)) {
            continue;
        }

        $n++;
        $definition = $navBlock->definition();

        // nav_visible in block data is the explicit setting; fall back to the
        // block type's own navDefault() for blocks that were placed before this
        // field existed.
        $inNav = (bool) ($navBlock->data['nav_visible'] ?? $definition?->navDefault() ?? false);

        if (! $inNav || count($items) >= 5) {
            continue;
        }

        // nav_label overrides the derived label when set. The enquiry block
        // label is named once by SiteActions so the menu and the button always
        // agree — but an explicit nav_label still wins.
        $label = filled($navBlock->data['nav_label'] ?? null)
            ? $navBlock->data['nav_label']
            : ($navBlock->type === 'enquiry'
                ? \App\Sites\Rendering\SiteActions::enquiryLabel($site, [$navBlock])
                : ($navBlock->data['heading'] ?? $definition?->label() ?? ''));

        // The band the enquiry button points at is marked as it is collected,
        // and moved to the end below. It is the one item in this list that is
        // an action rather than a place, and once the page has scrolled it is
        // the one that becomes a button — both of which want it at the end of
        // the row rather than in the middle of it.
        $items[] = [
            'anchor' => 's'.$n,
            'label' => $label,
            'action' => $navBlock->type === 'enquiry',
        ];
    }

    // "Request availability" after "Get in touch", not before it.
    usort($items, fn (array $a, array $b): int => (int) ($a['action'] ?? false) <=> (int) ($b['action'] ?? false));

    // Six is the whole menu: five items plus the one that says where you are.
    // It is not a matter of taste — the bar has a fixed height that the hero is
    // pulled up under by exactly that many pixels, so a menu allowed to grow
    // leaves a strip of background above the photograph. The booking button is
    // not in this count: it is a button beside the menu, not an item in it.
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
    } elseif ($items !== []) {
        // Home first, and only once there is somewhere else to go: a single-item
        // menu reading "Home" on the page you are already on is furniture.
        array_unshift($items, ['anchor' => 'top', 'label' => 'Home']);
        $items = array_slice($items, 0, $cap);
    }

    /**
     * Action buttons beside the menu — none of them by default. A button here
     * is redundant with the band it points at, which is already a menu item,
     * and it takes the width a long name needs: on the site this came from it
     * pushed "Ongombo West #56 Hunting Safari" onto a second line. It is a
     * switch rather than a rule, per screen, in App\Sites\ActionButtons.
     *
     * Worked out here rather than passed in, so the bar keeps rendering on its
     * own — one loop over the blocks it already has.
     */
    $actions = \App\Sites\Rendering\SiteActions::for($site, $blocks);

    // On the home page bare anchors scroll within the page. On shop or product
    // pages the sections don't exist, so prefix with the home URL so the link
    // still lands correctly.
    $anchorBase = ($isHome ?? true) ? '' : $site->pageUrl();
@endphp
<header class="nav {{ $hasHero ? '' : 'nav--solid' }}" id="nav">
    <div class="nav__inner">
        @include('sites.partials.brand', ['href' => $pages->count() > 1 ? $site->pageUrl() : '#top'])

        @if ($items !== [])
            <nav class="nav__links">
                @foreach ($items as $item)
                    <a href="{{ $item['href'] ?? $anchorBase.'#'.$item['anchor'] }}"
                       @if ($item['action'] ?? false) class="nav__link--action" @endif
                       @if ($item['current'] ?? false) aria-current="page" @endif>{{ $item['label'] }}</a>
                @endforeach
            </nav>
        @endif

        @foreach ($actions->buttons('menu') as $button)
            <a class="btn nav__cta {{ $button->isPrimary() ? '' : 'btn--light' }} {{ $button->deviceClass() }}"
               href="{{ $button->href }}"
               @if ($button->external) target="_blank" rel="noopener" @endif>{{ $button->label }}</a>
        @endforeach

        {{-- The enquiry button, once the opening screen has scrolled away with
             the one that was on it. Wide screens swap it for the menu item it
             duplicates; a burger-width screen has no menu item to swap, so it
             simply appears beside the burger. Hidden until then by the
             stylesheet — it is in the markup from the first byte, so it costs
             no layout when it arrives. --}}
        @if ($scrollButton = $actions->scrollButton())
            <a class="btn nav__cta nav__cta--scroll" href="{{ $scrollButton->href }}">{{ $scrollButton->label }}</a>
        @endif

        @if ($items !== [])
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
                <a href="{{ $item['href'] ?? $anchorBase.'#'.$item['anchor'] }}"
                   @if ($item['current'] ?? false) aria-current="page" @endif>{{ $item['label'] }}</a>
            @endforeach
        </div>
    @endif
</header>
