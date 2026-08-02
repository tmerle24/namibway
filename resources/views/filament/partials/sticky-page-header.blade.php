{{-- Applies to every panel page via a HEAD_END render hook, so it also covers
     future resources without extra setup. Colors are hardcoded to Filament's
     default gray palette (Filament\Support\Colors\Color::Gray) since neither
     panel overrides the "gray" color — if that changes, these need updating. --}}
<style>
    .fi-header {
        position: sticky;
        top: 4rem; /* height of .fi-topbar (h-16) */
        z-index: 10;
        /* Matches .fi-body's own background (not a card surface color) so
           content scrolling underneath doesn't show through. */
        background-color: rgb(249 250 251);
    }

    .dark .fi-header {
        background-color: rgb(3 7 18);
    }
</style>
