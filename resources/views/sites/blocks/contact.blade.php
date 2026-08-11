@php
    use App\Sites\Rendering\SafeLink;

    $whatsapp = SafeLink::whatsapp($site->whatsapp);
@endphp
<section class="section section--tint" id="{{ $anchor }}">
    <div class="wrap">
        @include('sites.partials.rule', ['label' => $definition->label()])

        <div class="reveal">
            @if (filled($data['heading'] ?? null))
                <h2>{{ $data['heading'] }}</h2>
            @endif

            @if (filled($data['intro'] ?? null))
                <p class="prose">{{ $data['intro'] }}</p>
            @endif

            <div class="channels" style="margin-top: var(--s5)">
                @if (filled($site->contact_phone))
                    <div class="channel">
                        <span class="channel__label">Telephone</span>
                        <a href="tel:{{ preg_replace('/[^\d+]/', '', $site->contact_phone) }}">{{ $site->contact_phone }}</a>
                    </div>
                @endif

                @if (filled($site->contact_email))
                    <div class="channel">
                        <span class="channel__label">Email</span>
                        <a href="mailto:{{ $site->contact_email }}">{{ $site->contact_email }}</a>
                    </div>
                @endif

                @if ($whatsapp)
                    <div class="channel">
                        <span class="channel__label">WhatsApp</span>
                        <a href="{{ $whatsapp }}" target="_blank" rel="noopener">{{ $site->whatsapp }}</a>
                    </div>
                @endif
            </div>

            @if ($whatsapp)
                <p style="margin-top: var(--s5)">
                    <a class="btn" href="{{ $whatsapp }}" target="_blank" rel="noopener">Message us on WhatsApp</a>
                </p>
            @endif
        </div>
    </div>
</section>
