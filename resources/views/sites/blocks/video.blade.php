@php
    use Illuminate\Support\Facades\Storage;

    $clips = collect($data['items'] ?? [])->filter(fn ($item) => filled($item['key'] ?? null))->values();
@endphp
@if ($clips->isNotEmpty())
    <section class="section section--ink" id="{{ $anchor }}">
        <div class="wrap">
            @include('sites.partials.rule', ['label' => $definition->label()])

            @if (filled($data['heading'] ?? null))
                <h2 class="reveal">{{ $data['heading'] }}</h2>
            @endif

            @if (filled($data['intro'] ?? null))
                <p class="lead reveal">{{ $data['intro'] }}</p>
            @endif

            <div class="videos videos--{{ min($clips->count(), 3) }}">
                @foreach ($clips as $clip)
                    @php
                        $poster = $images->get($clip['poster_image_id'] ?? null);
                        $src = Storage::disk('r2')->url($clip['key']);
                    @endphp
                    <figure class="video reveal">
                        {{-- Straight from R2, never through PHP. With a poster nothing
                             loads until play; without one only the metadata and the
                             frame at 0.1s, so the slot is not black. No autoplay. --}}
                        <video controls playsinline
                               preload="{{ $poster ? 'none' : 'metadata' }}"
                               @if ($poster) poster="{{ $poster->thumb(800) }}" @endif
                               src="{{ $poster ? $src : $src.'#t=0.1' }}"></video>
                        @if (filled($clip['caption'] ?? null))
                            <figcaption>{{ $clip['caption'] }}</figcaption>
                        @endif
                    </figure>
                @endforeach
            </div>
        </div>
    </section>
@endif
