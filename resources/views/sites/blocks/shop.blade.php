@php
    /** @var \App\Models\Site $site */
    /** @var \App\Sites\Blocks\ShopBlock $definition */
    /** @var array<string, mixed> $data */
    /** @var \Illuminate\Support\Collection<int, \App\Models\ShopProduct> $shopProducts */
    /** @var bool $shopHasMore */
@endphp
<section class="section section--tint" id="{{ $anchor }}">
    <div class="wrap">
        @include('sites.partials.rule', ['label' => $data['heading'] ?? $definition->label()])

        @if (filled($data['heading'] ?? null))
            <h2 class="reveal">{{ $data['heading'] }}</h2>
        @endif

        <div class="grid-shop">
            @foreach ($shopProducts as $product)
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
                        <h3 class="product-card__title">{{ $product->title }}</h3>
                        @if (filled($product->priceLabel()))
                            <p class="product-card__price">{{ $product->priceLabel() }}</p>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>

        @if ($shopHasMore ?? false)
            <p class="shop-more">
                <a href="{{ $site->pageUrl('shop') }}" class="btn btn--ghost">View all products</a>
            </p>
        @endif
    </div>
</section>
