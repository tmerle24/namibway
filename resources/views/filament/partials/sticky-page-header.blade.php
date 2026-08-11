{{-- Applies to every panel page via a HEAD_END render hook, so it also covers
     future resources without extra setup.

     position: fixed was tried first but positions relative to the full
     viewport, not the content column next to the sidebar (Filament's own
     sidebar switches to `sticky` at desktop widths for the same reason) —
     that pushed the right-aligned header actions off past the visible
     area. `sticky` stays inside the normal flex layout, so it already
     respects the sidebar's width with no extra left/width math needed.

     The gap between .fi-header and the content below it (Tailwind's
     gap-y-8 on the parent <section>) sits *outside* the header's own box,
     so it was never covered by its background — whatever scrolled into
     that gap (e.g. a form section's top border) showed through behind the
     sticky header. Moving that spacing into the header's own padding
     (and zeroing the section's competing padding-top/gap) keeps it inside
     the opaque box instead.

     .fi-header's own box also doesn't reliably reach the true left/right
     edge of the content column (some fields render slightly wider than
     the header, e.g. a rich-text preview), leaving a sliver where content
     peeked through at the sides. A first attempt at this used a
     `box-shadow: 0 0 0 100vmax` bleed to paint past the box on both
     sides — but that bleeds across the *entire* viewport width, including
     over the sidebar, which sits at a lower z-index (Filament's own
     sidebar CSS: z-0 on desktop) than this header (z-10), so the shadow
     painted right over the sidebar logo. Widening the box itself by a
     small, bounded amount (negative margin balanced by matching padding,
     so the visible content position doesn't shift) fixes the same sliver
     without ever reaching that far. --}}
<style>
    .fi-page > section {
        padding-top: 0;
        row-gap: 0;
    }

    .fi-main {
        margin-left: 0;
        margin-right: 0;
    }

    .fi-header {
        position: sticky;
        top: 4rem; /* height of .fi-topbar (h-16) */
        z-index: 10;
        padding-top: 10px;
        padding-bottom: 10px;
        margin-inline: -20px;
        padding-inline: 20px;

        /* The page background, not white. The header only has to be *opaque*
           so nothing scrolls into view behind it — it does not have to be a
           different colour, and being one made it read as a card sitting on
           the page rather than as part of it. That matters more now that
           partners see this panel and not only the team.

           These two values track the classes Filament puts on <body>
           (bg-gray-50 dark:bg-gray-950). Filament publishes its palette as
           space-separated RGB channels; the literals are the fallback for if
           it ever stops. */
        background-color: rgb(var(--gray-50, 249 250 251));

        /* Soft enough to read as depth rather than as an edge, but still
           there — without it, content scrolling underneath appears to end in
           mid-air. */
        box-shadow: 0 4px 6px -4px rgb(0 0 0 / 0.08);
    }

    .dark .fi-header {
        background-color: rgb(var(--gray-950, 3 7 18));
        box-shadow: 0 4px 6px -4px rgb(0 0 0 / 0.4);
    }
</style>
