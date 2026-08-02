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
     the opaque box instead. --}}
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
    }

    .dark .fi-header {
        background-color: #000;
    }
</style>
