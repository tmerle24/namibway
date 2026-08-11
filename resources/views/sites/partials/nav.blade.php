@php
    /**
     * The sticky bar. Transparent over a hero and solid once the page scrolls,
     * which is the state change the brief asks for and costs one class toggle.
     *
     * Links point at sections that actually exist on this page — a menu item
     * for a block that was switched off because there was nothing to put in it
     * would be a link to nothing.
     */
    $navTypes = ['about', 'gallery', 'opening_hours', 'price_list', 'booking', 'contact'];
    $items = [];
    $n = 0;

    foreach ($blocks as $navBlock) {
        if (! in_array($navBlock->type, ['hero', 'footer'], true)) {
            $n++;
        }

        if (in_array($navBlock->type, $navTypes, true) && count($items) < 4) {
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
        @endif
    </div>
</header>
