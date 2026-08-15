@php
    /** @var \App\Models\Site $site */
    /** @var string $accent */
    /** @var \App\Models\ShopProduct $product */
    /** @var \Illuminate\Support\Collection<int, \App\Models\SiteImage> $images */
    /** @var \Illuminate\Support\Collection<int, \App\Models\ShopProduct> $related */
    /** @var string $enquiryAction */

    $productImages = collect($product->image_ids ?? [])
        ->map(fn ($id) => $images->get((int) $id))
        ->filter()
        ->values();
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
<body>
    <script>document.documentElement.classList.add('js');</script>

    <header class="nav nav--solid" id="nav">
        <div class="nav__inner">
            @include('sites.partials.brand', ['href' => $site->pageUrl()])
            <nav class="nav__links">
                <a href="{{ $site->pageUrl('shop') }}">← Shop</a>
            </nav>
        </div>
    </header>

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
                            <div class="product-detail__main-img" style="display:flex;align-items:center;justify-content:center;font-size:64px;">
                                📦
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

                        @if (filled($product->price))
                            <p class="product-detail__price">{{ $product->price }}</p>
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
                                   class="btn" target="_blank" rel="noopener">
                                    Order via WhatsApp
                                </a>
                            @endif

                            @if (filled($site->contact_email))
                                @php
                                    $mailSubject = urlencode('Enquiry: '.$product->title);
                                @endphp
                                <a href="mailto:{{ $site->contact_email }}?subject={{ $mailSubject }}"
                                   class="btn btn--ghost">
                                    Send an email
                                </a>
                            @elseif ($site->sourceListing !== null)
                                <a href="{{ $site->pageUrl().'#enquiry' }}" class="btn btn--ghost">
                                    Make an enquiry
                                </a>
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
                                @php $relThumb = $images->get($rel->image_ids[0] ?? null); @endphp
                                <a href="{{ $rel->url() }}" class="product-card reveal">
                                    <div class="product-card__img">
                                        @if ($relThumb)
                                            <img src="{{ $relThumb->thumb(480) }}"
                                                 alt="{{ $relThumb->alt ?? $rel->title }}"
                                                 loading="lazy" decoding="async">
                                        @else
                                            <span class="product-card__img--empty">📦</span>
                                        @endif
                                    </div>
                                    <div class="product-card__body">
                                        @if (filled($rel->category))
                                            <span class="product-card__category">{{ $rel->category }}</span>
                                        @endif
                                        <h3 class="product-card__title">{{ $rel->title }}</h3>
                                        @if (filled($rel->price))
                                            <p class="product-card__price">{{ $rel->price }}</p>
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
