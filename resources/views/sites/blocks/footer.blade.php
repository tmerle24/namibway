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

        {{-- Slots, not text. The system frames the legal foot and fills it with
             the owner's own particulars; it writes no legal wording, because
             generated wording that reads like advice is advice with nobody
             behind it. See WEBSITE_BUILDER.md §5c. --}}
        <div class="foot__legal">
            <span>&copy; {{ now()->year }} {{ ($data['legal_name'] ?? null) ?: $site->name }}</span>

            @if (filled($data['registration'] ?? null))
                <span>{{ $data['registration'] }}</span>
            @endif

            @if (filled($data['responsible_person'] ?? null))
                <span>Responsible: {{ $data['responsible_person'] }}</span>
            @endif

            @foreach ($data['links'] ?? [] as $link)
                <a href="{{ SafeLink::href($link['href']) }}">{{ $link['label'] }}</a>
            @endforeach
        </div>
    </div>
</footer>
