@php
    /**
     * One of the business's own photographs behind a band, faint.
     *
     * For the bands at the foot of a long page, which are text and a form and
     * otherwise end the site on a flat colour. Deliberately the owner's own
     * picture rather than stock: on a safari site a bought photograph is
     * noticed exactly where trust is being asked for.
     *
     * Behind the content (z-index) and never in the way of reading it - the
     * opacity is set in the stylesheet, lower on a light band than on a dark
     * one, and the picture is marked decorative for a screen reader.
     */
    $photo = $images->get($data['background_image_id'] ?? null);
@endphp
@if ($photo)
    <div class="section__photo" aria-hidden="true">
        <img src="{{ $photo->thumb(1600) }}"
             @if ($srcset = $photo->srcset(1600)) srcset="{{ $srcset }}" @endif
             sizes="100vw" alt="" loading="lazy" decoding="async">
    </div>
@endif
