{{-- Applies to every panel page via a HEAD_END render hook, so it also covers
     future resources without extra setup. Colors are hardcoded to Filament's
     default gray palette (Filament\Support\Colors\Color::Gray) since neither
     panel overrides the "gray" color — if that changes, these need updating. --}}
<style>
    .fi-header {
        position: sticky;
        top: 4rem; /* height of .fi-topbar (h-16) */
        z-index: 10;
        margin-inline: -1rem;
        padding: 1rem;
        background-color: rgb(255 255 255);
        border: 1px solid rgb(3 7 18 / 0.05);
        box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    }

    @media (min-width: 768px) {
        .fi-header {
            border-radius: 0.75rem;
        }
    }

    .dark .fi-header {
        background-color: rgb(17 24 39);
        border-color: rgb(255 255 255 / 0.1);
    }
</style>
