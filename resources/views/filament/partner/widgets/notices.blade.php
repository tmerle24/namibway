@php
    /** @var array<int, \App\Filament\Partner\Support\Notice> $notices */
@endphp

{{--
    One card, one line per notice, each with the screen that answers it.

    Colour carries the level but never on its own: every notice states its
    level in words as well, because the whole board is read on a laptop in a
    lodge office with the sun on it, and "the orange one" is not something a
    reader with any kind of colour blindness can act on.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Worth knowing</x-slot>

        <div class="nw-notices">
            @foreach ($notices as $notice)
                <div class="nw-notice nw-notice--{{ $notice->level->value }}">
                    <x-filament::icon
                        :icon="$notice->level->icon()"
                        class="nw-notice__icon"
                    />

                    <div class="nw-notice__body">
                        <p class="nw-notice__title">
                            <span class="nw-notice__level">{{ $notice->level->label() }}</span>
                            {{ $notice->title }}
                        </p>

                        <p class="nw-notice__text">{{ $notice->body }}</p>
                    </div>

                    @if ($notice->hasAction())
                        <a class="nw-notice__action" href="{{ $notice->actionUrl }}">
                            {{ $notice->actionLabel }} &rarr;
                        </a>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Inside the widget, not after it: a Livewire component may have
             exactly one root element, and a trailing <style> is a second one.
             Plain CSS on Filament's own palette variables with literal
             fallbacks, the same approach as the calendar's lodge styles — the
             panel carries no custom Filament theme, so a Tailwind class
             invented here would never be compiled. --}}
        <style>
    .nw-notices {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .nw-notice {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        gap: 0.5rem 0.75rem;
        padding: 0.75rem 0.9rem;
        border-radius: 0.5rem;
        border: 1px solid rgb(228 228 231);
        background-color: rgb(250 250 250);
    }

    .nw-notice__icon {
        flex: none;
        width: 1.25rem;
        height: 1.25rem;
        margin-top: 0.1rem;
        color: rgb(113 113 122);
    }

    .nw-notice__body {
        flex: 1 1 18rem;
        min-width: 0;
    }

    .nw-notice__title {
        font-size: 0.875rem;
        font-weight: 600;
        line-height: 1.3;
        color: rgb(24 24 27);
    }

    .nw-notice__level {
        display: inline-block;
        margin-right: 0.4rem;
        padding: 0.05rem 0.4rem;
        font-size: 0.625rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        vertical-align: 0.08rem;
        border-radius: 0.25rem;
        color: rgb(255 255 255);
        background-color: rgb(113 113 122);
    }

    .nw-notice__text {
        margin-top: 0.2rem;
        font-size: 0.8125rem;
        line-height: 1.4;
        color: rgb(82 82 91);
    }

    .nw-notice__action {
        flex: none;
        align-self: center;
        padding: 0.3rem 0.6rem;
        font-size: 0.8125rem;
        font-weight: 600;
        white-space: nowrap;
        border-radius: 0.375rem;
        border: 1px solid rgb(212 212 216);
        color: rgb(39 39 42);
        background-color: rgb(255 255 255);
    }

    .nw-notice__action:hover {
        background-color: rgb(244 244 245);
    }

    .nw-notice--warning {
        border-color: rgb(var(--warning-400, 251 191 36));
        background-color: rgb(var(--warning-50, 255 251 235));
    }

    .nw-notice--warning .nw-notice__icon {
        color: rgb(180 83 9);
    }

    .nw-notice--warning .nw-notice__level {
        background-color: rgb(180 83 9);
    }

    .nw-notice--action {
        border-color: rgb(var(--primary-300, 217 180 140));
        background-color: rgb(var(--primary-50, 253 246 238));
    }

    .nw-notice--action .nw-notice__icon {
        color: rgb(146 82 24);
    }

    .nw-notice--action .nw-notice__level {
        background-color: rgb(146 82 24);
    }

    .nw-notice--info .nw-notice__icon {
        color: rgb(2 132 199);
    }

    .nw-notice--info .nw-notice__level {
        background-color: rgb(2 132 199);
    }

    .dark .nw-notice {
        border-color: rgb(63 63 70);
        background-color: rgb(39 39 42);
    }

    .dark .nw-notice__title {
        color: rgb(244 244 245);
    }

    .dark .nw-notice__text {
        color: rgb(212 212 216);
    }

    .dark .nw-notice__action {
        border-color: rgb(82 82 91);
        color: rgb(244 244 245);
        background-color: rgb(24 24 27);
    }

    .dark .nw-notice--warning {
        border-color: rgb(146 64 14);
        background-color: rgb(69 26 3);
    }

    .dark .nw-notice--action {
        border-color: rgb(146 82 24);
        background-color: rgb(59 36 24);
    }

    /* A dashboard printed for a handover meeting should not carry advice
       about screens nobody is looking at. */
    @media print {
        .nw-notices {
            display: none;
        }
    }
        </style>
    </x-filament::section>
</x-filament-widgets::widget>
