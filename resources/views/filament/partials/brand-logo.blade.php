{{-- Keeps the brand logo's aspect ratio intact in every panel.

     Filament's logo component writes the panel's brandLogoHeight as an inline
     `style="height: …"` on the <img>. Filament's own stylesheet ships Tailwind
     preflight, which caps images at `max-width: 100%` and would set
     `height: auto` — but the inline height wins over the stylesheet. So in any
     container narrower than the logo's natural width at that height, the width
     gets clamped while the height stays put, and the wordmark is squeezed
     horizontally (i.e. it reads as stretched vertically).

     That is exactly what the sidebar header does: it is 272px of content width
     (a 20rem sidebar less its px-6 padding), and the 733×171 logo needs 343px
     to stand 80px tall — it was rendering at 272×80.

     `object-fit` is the actual fix: it preserves the ratio inside whatever box
     the layout ends up giving the image, so no future sidebar width or panel
     can distort it again. `object-position: left` keeps the mark hard against
     the left edge of the header instead of floating in the middle of a box
     wider than itself; on the login screen the box equals the image, so it has
     no visible effect there. The brandLogoHeight values are still chosen to
     fit (see the panel providers) — this is the guard, not the layout. --}}
<style>
    .fi-logo {
        width: auto;
        object-fit: contain;
        object-position: left center;
    }
</style>
