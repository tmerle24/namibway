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

     .fi-header's own box still doesn't reliably reach the true left/right
     edge of the content column (some fields render slightly wider than
     the header, e.g. a rich-text preview), leaving a sliver where content
     peeked through at the sides. Rather than chase that per field, the
     box-shadow + clip-path pair below bleeds the header's background out
     to any width — a solid color painted 100vmax past the box on each
     side, clipped back so it doesn't create a horizontal scrollbar.

     The drop-shadow (for a bit of visible depth below the header) has to
     be listed *before* that opaque bleed shadow in the box-shadow list —
     CSS paints earlier shadows on top of later ones, so listing it after
     would leave it fully hidden behind the opaque fill. clip-path's
     vertical inset is widened slightly (-16px instead of 0) so the
     drop-shadow's blur has room to actually render below the box instead
     of being clipped off flush with the edge. --}}
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
        background-color: #fff;
        box-shadow: 0 2px 4px 0 rgb(0 0 0 / 0.1), 0 0 0 100vmax #fff;
        clip-path: inset(-16px -100vmax);
    }

    .dark .fi-header {
        background-color: #000;
        box-shadow: 0 2px 4px 0 rgb(0 0 0 / 0.1), 0 0 0 100vmax #000;
    }
</style>
