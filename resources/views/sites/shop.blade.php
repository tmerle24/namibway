@php
    /** @var \App\Models\Site $site */
    /** @var string $accent */
    /** @var \Illuminate\Support\Collection<int, \App\Models\ShopProduct> $products */
    /** @var \Illuminate\Support\Collection<int, string> $categories */
    /** @var string|null $activeCategory */
    /** @var string $activeSort */
    /** @var string $search */
    /** @var int $page */
    /** @var int $totalPages */
    /** @var int $total */
    /** @var \Illuminate\Support\Collection<int, \App\Models\SiteBlock> $navBlocks */
    /** @var \App\Models\SitePage|null $navPage */

    $shopUrl = $site->pageUrl('shop');

    // Build a query-string for pagination links that preserves the active filters.
    $pagerBase = array_filter([
        'category' => $activeCategory,
        'sort' => $activeSort !== 'default' ? $activeSort : null,
        'q' => filled($search) ? $search : null,
    ]);
@endphp
<!DOCTYPE html>
<html lang="{{ $site->default_locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shop — {{ $site->name }}</title>
    @include('sites.partials.styles')
    <style>:root { --accent: {{ $accent }}; }</style>
</head>
<body id="top">
    <script>document.documentElement.classList.add('js');</script>

    @include('sites.partials.nav', [
        'blocks' => $navBlocks,
        'page' => $navPage,
        'hasHero' => false,
        'isHome' => false,
    ])

    <div class="shop-subbar">
        <div class="wrap">
            <nav class="shop-breadcrumb" aria-label="Breadcrumb">
                <a href="{{ $site->pageUrl() }}">{{ $site->name }}</a>
                <span aria-hidden="true">›</span>
                <span aria-current="page">Shop</span>
            </nav>
        </div>
    </div>

    <main>
        <section class="section">
            <div class="wrap">
                <h1 style="font-family:var(--font-display);font-size:38px;margin:0 0 var(--s5);">Shop</h1>

                {{-- Search --}}
                <form method="GET" action="{{ $shopUrl }}" class="shop-search" role="search">
                    @if ($activeCategory)
                        <input type="hidden" name="category" value="{{ $activeCategory }}">
                    @endif
                    @if ($activeSort !== 'default')
                        <input type="hidden" name="sort" value="{{ $activeSort }}">
                    @endif
                    <input type="search" name="q" value="{{ $search }}" placeholder="Search products…"
                           class="shop-search__input" aria-label="Search products">
                    <button type="submit" class="shop-search__btn" aria-label="Search">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"
                             stroke-linecap="round" aria-hidden="true">
                            <circle cx="8.5" cy="8.5" r="5.5"/>
                            <line x1="13" y1="13" x2="18" y2="18"/>
                        </svg>
                    </button>
                    @if (filled($search))
                        <a href="{{ $shopUrl.'?'.http_build_query(array_filter(['category' => $activeCategory, 'sort' => $activeSort !== 'default' ? $activeSort : null])) }}"
                           class="shop-search__clear" aria-label="Clear search">✕</a>
                    @endif
                </form>

                @if ($categories->isNotEmpty() || $products->isNotEmpty())
                    <div class="shop-filters">
                        {{-- Category pills --}}
                        <a href="{{ $shopUrl.($activeSort !== 'default' ? '?sort='.$activeSort : '') }}"
                           class="shop-filter {{ is_null($activeCategory) ? 'shop-filter--active' : '' }}">
                            All
                        </a>

                        @foreach ($categories as $cat)
                            @php
                                $params = array_filter(['category' => $cat, 'sort' => $activeSort !== 'default' ? $activeSort : null]);
                                $href = $shopUrl.'?'.http_build_query($params);
                            @endphp
                            <a href="{{ $href }}"
                               class="shop-filter {{ $activeCategory === $cat ? 'shop-filter--active' : '' }}">
                                {{ $cat }}
                            </a>
                        @endforeach

                        {{-- Sort --}}
                        <select class="shop-sort" onchange="location.href=this.value">
                            @php
                                $sortBase = $shopUrl.($activeCategory ? '?category='.urlencode($activeCategory).'&sort=' : '?sort=');
                            @endphp
                            <option value="{{ $sortBase.'default' }}" {{ $activeSort === 'default' ? 'selected' : '' }}>Default order</option>
                            <option value="{{ $sortBase.'name' }}" {{ $activeSort === 'name' ? 'selected' : '' }}>Name A–Z</option>
                            <option value="{{ $sortBase.'price_asc' }}" {{ $activeSort === 'price_asc' ? 'selected' : '' }}>Price low–high</option>
                            <option value="{{ $sortBase.'price_desc' }}" {{ $activeSort === 'price_desc' ? 'selected' : '' }}>Price high–low</option>
                        </select>
                    </div>
                @endif

                @if ($products->isNotEmpty())
                    <div class="grid-shop">
                        @foreach ($products as $product)
                            @php $thumb = $product->images->first(); @endphp
                            <a href="{{ $site->pageUrl('shop/'.$product->slug) }}" class="product-card reveal">
                                <div class="product-card__img">
                                    @if ($thumb)
                                        <img src="{{ $thumb->thumb(480) }}"
                                             @if ($srcset = $thumb->srcset(480)) srcset="{{ $srcset }}" @endif
                                             sizes="(min-width: 860px) 33vw, 50vw"
                                             alt="{{ $thumb->alt ?? $product->title }}"
                                             loading="lazy" decoding="async">
                                    @else
                                        @include('sites.partials.product-placeholder')
                                    @endif
                                </div>
                                <div class="product-card__body">
                                    @if (filled($product->category))
                                        <span class="product-card__category">{{ $product->category }}</span>
                                    @endif
                                    <h2 class="product-card__title">{{ $product->title }}</h2>
                                    @if (filled($product->priceLabel()))
                                        <p class="product-card__price">{{ $product->priceLabel() }}</p>
                                    @endif
                                </div>
                            </a>
                        @endforeach
                    </div>

                    {{-- Pagination --}}
                    @if ($totalPages > 1)
                        <nav class="shop-pager" aria-label="Pages">
                            @if ($page > 1)
                                <a href="{{ $shopUrl.'?'.http_build_query($pagerBase + ['page' => $page - 1]) }}"
                                   class="shop-pager__btn" aria-label="Previous page">‹</a>
                            @endif

                            @for ($p = 1; $p <= $totalPages; $p++)
                                @if ($p === $page)
                                    <span class="shop-pager__num shop-pager__num--active">{{ $p }}</span>
                                @elseif ($p === 1 || $p === $totalPages || abs($p - $page) <= 2)
                                    <a href="{{ $shopUrl.'?'.http_build_query($pagerBase + ['page' => $p]) }}"
                                       class="shop-pager__num">{{ $p }}</a>
                                @elseif (abs($p - $page) === 3)
                                    <span class="shop-pager__ellipsis">…</span>
                                @endif
                            @endfor

                            @if ($page < $totalPages)
                                <a href="{{ $shopUrl.'?'.http_build_query($pagerBase + ['page' => $page + 1]) }}"
                                   class="shop-pager__btn" aria-label="Next page">›</a>
                            @endif
                        </nav>
                    @endif
                @else
                    <p style="color:var(--slate)">
                        @if (filled($search))
                            No products found for "{{ $search }}".
                        @else
                            No products found in this category.
                        @endif
                    </p>
                @endif
            </div>
        </section>
    </main>

    <footer class="foot">
        <div class="wrap">
            @include('sites.partials.legal-foot')
        </div>
    </footer>

    @include('sites.partials.motion')
</body>
</html>
