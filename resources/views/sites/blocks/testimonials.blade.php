@php
    $quotes = $data['items'] ?? [];
@endphp
<section class="section section--quotes" id="{{ $anchor }}">
    <div class="wrap">
        @include('sites.partials.rule', ['label' => $definition->label()])

        @if (filled($data['heading'] ?? null))
            <h2 class="reveal">{{ $data['heading'] }}</h2>
        @endif

        <div class="quotes {{ count($quotes) === 1 ? 'quotes--one' : '' }}">
            @foreach ($quotes as $quote)
                <figure class="quote reveal">
                    <blockquote>&ldquo;{{ $quote['quote'] }}&rdquo;</blockquote>
                    <figcaption>
                        <strong>{{ $quote['name'] }}</strong>
                        @if (filled($quote['origin'] ?? null))
                            <span>{{ $quote['origin'] }}</span>
                        @endif
                        {{-- A placeholder says so on the page; PublishGate keeps it off a live site. --}}
                        @if (! empty($quote['sample']))
                            <em class="quote__sample">Sample</em>
                        @endif
                    </figcaption>
                </figure>
            @endforeach
        </div>
    </div>
</section>
