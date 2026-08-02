{{-- Applies to every panel page via a HEAD_END render hook, so it also covers
     future resources without extra setup.

     position: fixed was tried first but positions relative to the full
     viewport, not the content column next to the sidebar (Filament's own
     sidebar switches to `sticky` at desktop widths for the same reason) —
     that pushed the right-aligned header actions off past the visible
     area. `sticky` stays inside the normal flex layout, so it already
     respects the sidebar's width with no extra left/width math needed.

     Background matches the white/gray-900 card surface color that table
     rows and form sections use (not the page body color) since that's
     what's actually behind the header most of the time while scrolling. --}}
<style>
    .fi-main {
        margin-left: 0;
        margin-right: 0;
    }

    .fi-header {
        position: sticky;
        top: 4rem; /* height of .fi-topbar (h-16) */
        z-index: 10;
        background-color: rgb(255 255 255);
    }

    .dark .fi-header {
        background-color: rgb(17 24 39);
    }
</style>
