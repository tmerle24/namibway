@php
    use App\Sites\Rendering\SafeLink;

    $social = collect($site->social_links ?? [])->filter(fn ($url) => filled($url));
@endphp
<footer class="foot">
    <div class="wrap">
        <div class="foot__grid">
            <div>
                <p class="foot__name">{{ ($data['legal_name'] ?? null) ?: $site->name }}</p>

                @if (filled($site->address))
                    <p style="white-space: pre-line">{{ $site->address }}</p>
                @endif

                @if (filled($data['note'] ?? null))
                    <p>{{ $data['note'] }}</p>
                @endif
            </div>

            <div>
                @if (filled($site->contact_phone))
                    <p><a href="tel:{{ preg_replace('/[^\d+]/', '', $site->contact_phone) }}">{{ $site->contact_phone }}</a></p>
                @endif

                @if (filled($site->contact_email))
                    <p><a href="mailto:{{ $site->contact_email }}">{{ $site->contact_email }}</a></p>
                @endif

                @foreach ($social as $label => $url)
                    <p><a href="{{ SafeLink::href($url) }}" target="_blank" rel="noopener">{{ ucfirst((string) $label) }}</a></p>
                @endforeach
            </div>
        </div>

        @include('sites.partials.legal-foot')
    </div>
</footer>
