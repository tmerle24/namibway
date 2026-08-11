@php
    /** @var \App\Filament\Partner\Support\SelectedProperty $properties */
    $properties = app(\App\Filament\Partner\Support\SelectedProperty::class);
    $options = $properties->options();
    $current = $properties->current();
@endphp

{{--
    The property switcher, in the panel topbar on every page.

    A partner with one property never sees it — there is nothing to switch —
    and neither does a partner with none. It is a plain form post rather than a
    Livewire component so that it behaves identically on every page in the
    panel, including pages that are not Livewire-driven at all.
--}}
@if ($properties->hasChoice())
    <style>
        .nw-switcher {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nw-switcher__label {
            font-size: 0.75rem;
            font-weight: 500;
            color: rgb(var(--gray-500, 107 114 128));
            white-space: nowrap;
        }

        .dark .nw-switcher__label {
            color: rgb(var(--gray-400, 156 163 175));
        }

        .nw-switcher__select {
            max-width: 15rem;
            padding: 0.3rem 1.75rem 0.3rem 0.6rem;
            border: 1px solid rgb(var(--gray-300, 209 213 219));
            border-radius: 0.5rem;
            background-color: rgb(255 255 255);
            color: rgb(var(--gray-950, 3 7 18));
            font-size: 0.8125rem;
            font-weight: 500;
        }

        .dark .nw-switcher__select {
            background-color: rgb(255 255 255 / 0.05);
            border-color: rgb(255 255 255 / 0.2);
            color: rgb(255 255 255);
        }

        @media (max-width: 640px) {
            .nw-switcher__label {
                display: none;
            }
        }
    </style>

    <form method="POST" action="{{ route('filament.partner.property.select') }}" class="nw-switcher">
        @csrf

        <label for="nw-property" class="nw-switcher__label">Property</label>

        <select
            id="nw-property"
            name="property"
            class="nw-switcher__select"
            onchange="this.form.submit()"
        >
            @foreach ($options as $option)
                <option value="{{ $option->id }}" @selected($current?->is($option))>
                    {{ $option->name }}
                </option>
            @endforeach
        </select>

        {{-- Without JavaScript the select still works; it just needs a button. --}}
        <noscript>
            <button type="submit" class="nw-switcher__select">Switch</button>
        </noscript>
    </form>
@endif
