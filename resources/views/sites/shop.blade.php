@php
    /** @var \App\Models\Site $site */
    /** @var string $accent */
    /** @var \Illuminate\Support\Collection<int, \App\Models\ShopProduct> $products */
    /** @var \Illuminate\Support\Collection<int, \App\Models\SiteImage> $images */
    /** @var \Illuminate\Support\Collection<int, string> $categories */
    /** @var string|null $activeCategory */
    /** @var string $activeSort */
    /** @var \Illuminate\Support\Collection<int, \App\Models\SiteBlock> $navBlocks */
    /** @var \App\Models\SitePage|null $navPage */

    $shopUrl = $site->pageUrl('shop');
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
    ])

    <div class="shop-subbar">
        <nav class="shop-breadcrumb" aria-label="Breadcrumb">
            <a href="{{ $site->pageUrl() }}">{{ $site->name }}</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">Shop</span>
        </nav>
    </div>

    <main>
        <section class="section">
            <div class="wrap">
                <h1 style="font-family:var(--font-display);font-size:38px;margin:0 0 var(--s5);">Shop</h1>

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
                            @php $thumb = $images->get($product->image_ids[0] ?? null); @endphp
                            <a href="{{ $product->url() }}" class="product-card reveal">
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
                @else
                    <p style="color:var(--slate)">No products found in this category.</p>
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
