@php
    // Resolved here rather than passed in, because this is a render hook and
    // has no page behind it. Cheap: the user is already in memory and the
    // partner is one relation off it.
    $demoPartner = auth()->user()?->partner;
    $isDemo = $demoPartner?->is_demo === true;
@endphp

@if ($isDemo)
    <div class="nw-demo-banner" role="status">
        <span class="nw-demo-banner__tag">Demonstration</span>
        <span class="nw-demo-banner__text">
            This is a sandbox. Every booking, guest and rate you see here was made up to show how the
            system works, and nothing in it belongs to a real stay.
        </span>
    </div>

    {{-- Striped rather than merely coloured: this has to survive a projector,
         a monochrome screenshot and a colour-blind reader, because the whole
         job of the banner is that nobody mistakes seeded data for a real
         booking. The panel carries no custom Filament theme, so the styles are
         plain CSS on Filament's own palette variables with literal
         fallbacks — the same approach as the calendar's lodge-styles. --}}
    <style>
        .nw-demo-banner {
            position: sticky;
            top: 0;
            z-index: 40;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem 0.75rem;
            padding: 0.5rem 1rem;
            font-size: 0.8125rem;
            line-height: 1.35;
            color: rgb(69 26 3);
            background-color: rgb(var(--warning-100, 254 243 199));
            background-image: repeating-linear-gradient(
                135deg,
                transparent 0 10px,
                rgba(180, 83, 9, 0.14) 10px 20px
            );
            border-bottom: 2px solid rgb(var(--warning-500, 245 158 11));
        }

        .nw-demo-banner__tag {
            flex: none;
            padding: 0.125rem 0.5rem;
            font-size: 0.6875rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgb(255 255 255);
            background-color: rgb(180 83 9);
            border-radius: 0.25rem;
        }

        .nw-demo-banner__text {
            min-width: 0;
        }

        .dark .nw-demo-banner {
            color: rgb(254 243 199);
            background-color: rgb(69 26 3);
            background-image: repeating-linear-gradient(
                135deg,
                transparent 0 10px,
                rgba(245, 158, 11, 0.18) 10px 20px
            );
        }

        /* A printed arrivals list from a demo must still say so — it is the
           copy most likely to be carried out of the meeting. */
        @media print {
            .nw-demo-banner {
                position: static;
                border-bottom: 2px solid #000;
            }
        }
    </style>
@endif
