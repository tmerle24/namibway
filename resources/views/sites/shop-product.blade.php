@php
    /** @var \App\Models\Site $site */
    /** @var string $accent */
    /** @var \App\Models\ShopProduct $product */
    /** @var \Illuminate\Support\Collection<int, \App\Models\ShopProduct> $related */
    /** @var string $enquiryAction */
    /** @var \Illuminate\Support\Collection<int, \App\Models\SiteBlock> $navBlocks */
    /** @var \App\Models\SitePage|null $navPage */

    $productImages = $product->images;

    $enquirySent = request()->query('sent');
    $showEnquiry = $enquirySent !== null;
@endphp
<!DOCTYPE html>
<html lang="{{ $site->default_locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $product->title }} — {{ $site->name }}</title>
    @if ($productImages->isNotEmpty())
        <meta property="og:image" content="{{ $productImages->first()->thumb(800) }}">
    @endif
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
                <a href="{{ $site->pageUrl('shop') }}">Shop</a>
                <span aria-hidden="true">›</span>
                <span aria-current="page">{{ $product->title }}</span>
            </nav>
            <a href="{{ $site->pageUrl('shop') }}" class="shop-back">← Back to shop</a>
        </div>
    </div>

    <main>
        <section class="section">
            <div class="wrap">
                <div class="product-detail">

                    {{-- Images --}}
                    <div class="product-detail__images">
                        @if ($productImages->isNotEmpty())
                            <div class="product-detail__main-img" id="main-img">
                                <img id="main-img-el"
                                     src="{{ $productImages->first()->thumb(800) }}"
                                     @if ($srcset = $productImages->first()->srcset(800)) srcset="{{ $srcset }}" @endif
                                     sizes="(min-width: 760px) 50vw, 100vw"
                                     alt="{{ $productImages->first()->alt ?? $product->title }}"
                                     loading="eager" decoding="async">
                            </div>

                            @if ($productImages->count() > 1)
                                <div class="product-detail__thumbs">
                                    @foreach ($productImages as $i => $img)
                                        <button class="product-detail__thumb"
                                                onclick="document.getElementById('main-img-el').src='{{ $img->thumb(800) }}'"
                                                style="{{ $i === 0 ? 'border-color:var(--accent)' : '' }}">
                                            <img src="{{ $img->thumb(120) }}"
                                                 alt="{{ $img->alt ?? $product->title }}"
                                                 loading="lazy" decoding="async">
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        @else
                            <div class="product-detail__main-img product-detail__main-img--empty">
                                @include('sites.partials.product-placeholder')
                            </div>
                        @endif
                    </div>

                    {{-- Info --}}
                    <div class="product-detail__info">
                        @if (filled($product->category))
                            <p class="product-detail__cat">
                                <a href="{{ $site->pageUrl('shop').'?category='.urlencode($product->category) }}"
                                   style="color:inherit;text-decoration:none;">{{ $product->category }}</a>
                            </p>
                        @endif

                        <h1 class="product-detail__title">{{ $product->title }}</h1>

                        @if (filled($product->priceLabel()))
                            <p class="product-detail__price">{{ $product->priceLabel() }}</p>
                        @endif

                        @if (filled($product->description))
                            <div class="product-detail__desc">
                                {!! nl2br(e($product->description)) !!}
                            </div>
                        @endif

                        {{-- Contact CTA --}}
                        <div style="display:flex;flex-wrap:wrap;gap:var(--s3);margin-top:var(--s2);">
                            @if (filled($site->whatsapp))
                                @php
                                    $waText = urlencode('Hi, I\'m interested in: '.$product->title);
                                    $waNumber = preg_replace('/\D/', '', $site->whatsapp);
                                @endphp
                                <a href="https://wa.me/{{ $waNumber }}?text={{ $waText }}"
                                   class="btn btn--whatsapp" target="_blank" rel="noopener">
                                    Order via WhatsApp
                                </a>
                            @endif

                            @unless ($showEnquiry)
                                <button type="button"
                                        class="btn btn--ghost btn--desktop-only"
                                        onclick="document.getElementById('enquiry').hidden=false;this.hidden=true">
                                    Send an enquiry
                                </button>
                            @endunless
                        </div>

                        {{-- Desktop enquiry form (inline, hidden until the button is tapped) --}}
                        <div id="enquiry" class="product-enquiry" {{ $showEnquiry ? '' : 'hidden' }}>
                            @if ($enquirySent === '1')
                                <p class="product-enquiry__sent">Thanks — we'll be in touch soon.</p>
                            @else
                                @if ($enquirySent === '0')
                                    <p class="product-enquiry__error">Something went wrong. Please try again.</p>
                                @endif
                                <form method="POST" action="{{ route('sites.enquiry', $site) }}"
                                      class="product-enquiry__form">
                                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                                    <input class="enquiry__trap" type="text" name="website"
                                           tabindex="-1" autocomplete="off" aria-hidden="true">
                                    <div class="field">
                                        <label for="pq-name">Your name</label>
                                        <input id="pq-name" type="text" name="name" required autocomplete="name">
                                    </div>
                                    <div class="field">
                                        <label for="pq-email">Email address</label>
                                        <input id="pq-email" type="email" name="email" required autocomplete="email">
                                    </div>
                                    <div class="field">
                                        <label for="pq-message">Message (optional)</label>
                                        <textarea id="pq-message" name="message" rows="3"
                                                  placeholder="Any questions about {{ $product->title }}?"></textarea>
                                    </div>
                                    <div style="display:flex;gap:var(--s3);align-items:center">
                                        <button type="submit" class="btn">Send enquiry</button>
                                        <button type="button" class="product-enquiry__cancel"
                                                onclick="this.closest('#enquiry').hidden=true;document.querySelector('.btn--desktop-only').hidden=false">
                                            Cancel
                                        </button>
                                    </div>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Related products --}}
                @if ($related->isNotEmpty())
                    <div style="margin-top:var(--s7);">
                        <h2 style="font-size:20px;margin:0 0 var(--s4);">
                            @if (filled($product->category)) More {{ $product->category }} @else More products @endif
                        </h2>
                        <div class="grid-shop">
                            @foreach ($related as $rel)
                                @php $relThumb = $rel->images->first(); @endphp
                                <a href="{{ $rel->url() }}" class="product-card reveal">
                                    <div class="product-card__img">
                                        @if ($relThumb)
                                            <img src="{{ $relThumb->thumb(480) }}"
                                                 alt="{{ $relThumb->alt ?? $rel->title }}"
                                                 loading="lazy" decoding="async">
                                        @else
                                            @include('sites.partials.product-placeholder')
                                        @endif
                                    </div>
                                    <div class="product-card__body">
                                        @if (filled($rel->category))
                                            <span class="product-card__category">{{ $rel->category }}</span>
                                        @endif
                                        <h3 class="product-card__title">{{ $rel->title }}</h3>
                                        @if (filled($rel->priceLabel()))
                                            <p class="product-card__price">{{ $rel->priceLabel() }}</p>
                                        @endif
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>
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
