@php
    /** @var \App\Models\Site $site */
    /** @var string $accent */
    /** @var \Illuminate\Support\Collection<int, \App\Models\ShopProduct> $products */
    /** @var \Illuminate\Support\Collection<int, \App\Models\SiteImage> $images */
    /** @var \Illuminate\Support\Collection<int, string> $categories */
    /** @var string|null $activeCategory */
    /** @var string $activeSort */

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
<body>
    <script>document.documentElement.classList.add('js');</script>

    <header class="nav nav--solid" id="nav">
        <div class="nav__inner">
            @include('sites.partials.brand', ['href' => $site->pageUrl()])
            <nav class="nav__links">
                <a href="{{ $site->pageUrl() }}">← Back</a>
            </nav>
        </div>
    </header>

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
                                        <span class="product-card__img--empty">📦</span>
                                    @endif
                                </div>
                                <div class="product-card__body">
                                    @if (filled($product->category))
                                        <span class="product-card__category">{{ $product->category }}</span>
                                    @endif
                                    <h2 class="product-card__title">{{ $product->title }}</h2>
                                    @if (filled($product->price))
                                        <p class="product-card__price">{{ $product->price }}</p>
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
