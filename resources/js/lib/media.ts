/**
 * Frontend mirror of App\Support\MediaUrl — see that class for why thumbnails
 * are resized at the edge rather than generated and stored.
 *
 * This lives on the client because the render size is a property of the *slot*,
 * not of the image: the very same listing payload feeds a 44px day thumbnail in
 * the trip plan, a 48px row in the swap modal and a full-bleed hero on the
 * detail page. Only the component knows which, so only the component can ask
 * for the right width.
 */

export interface MediaTransformConfig {
    enabled: boolean;
    origins: string[];
    widths: number[];
    quality: number;
    /**
     * Public base of the media bucket, unfiltered — distinct from `origins`,
     * which lists only hosts that can serve /cdn-cgi/image/. This is how an
     * image is recognised as ours at all, and it is what `/thumbs/` keys are
     * measured against.
     */
    mediaBase: string;
    ownThumbnails: boolean;
}

/**
 * Mirrors config/media.php. The defaults are what a page renders with before
 * (or without) the Inertia prop — transforms off, so every URL passes through
 * untouched and images still show up.
 */
let config: MediaTransformConfig = {
    enabled: false,
    origins: [],
    widths: [64, 128, 256, 400, 800, 1600],
    quality: 80,
    mediaBase: '',
    ownThumbnails: true,
};

/**
 * Called once from app.ts. There's no router.on('success') refresh here on
 * purpose, unlike locale/currency: this is deployment configuration, so it
 * cannot change from one page visit to the next.
 */
export function initializeMedia(
    initial: MediaTransformConfig | undefined,
): void {
    if (initial) {
        config = initial;
    }
}

/** Snap a requested width up to the configured ladder. See the PHP twin. */
export function snapWidth(width: number): number {
    if (config.widths.length === 0) {
        return width;
    }

    return (
        config.widths.find((rung) => width <= rung) ??
        config.widths[config.widths.length - 1]
    );
}

/**
 * Resize `url` for a slot `width` CSS pixels wide, or hand it back untouched
 * when it isn't ours to resize.
 */
export function thumb(url: string, width: number): string {
    if (url === '') {
        return url;
    }

    const snapped = snapWidth(width);

    // Already transformed — rewrapping would nest /cdn-cgi/image/ in itself.
    if (url.includes('/cdn-cgi/image/')) {
        return url;
    }

    if (url.startsWith('https://images.unsplash.com/')) {
        return resizeUnsplash(url, snapped);
    }

    // Already one of our own thumbnail URLs — same nesting problem.
    if (url.includes('/thumbs/')) {
        return url;
    }

    if (config.enabled) {
        const rewritten = rewriteThroughCloudflare(url, snapped);

        if (rewritten !== null) {
            return rewritten;
        }
    }

    // Cloudflare either isn't available or doesn't cover this URL, so fall back
    // to having the server resize it. This is the path production takes today.
    return ownThumbnail(url, snapped) ?? url;
}

/**
 * `/thumbs/{width}/{key}` for an image in our own media bucket, or null for
 * anything else — a scraped operator's website, a bundled fallback served by
 * nginx, a `blob:` preview in the listing editor. See the PHP twin.
 */
function ownThumbnail(url: string, width: number): string | null {
    if (!config.ownThumbnails || config.mediaBase === '') {
        return null;
    }

    if (!url.startsWith(`${config.mediaBase}/`)) {
        return null;
    }

    const key = url.slice(config.mediaBase.length).replace(/^\/+/, '');

    if (key === '' || key.startsWith('thumbs/')) {
        return null;
    }

    return `/thumbs/${width}/${key}`;
}

/**
 * `src` plus, where it buys anything, a retina `srcset` — spread straight onto
 * an <img> with `v-bind="thumbAttrs(item.image, 400)"`. `srcset` is left off
 * when both densities resolve to the same URL, so un-resizable external images
 * don't get a redundant attribute.
 */
export function thumbAttrs(
    url: string,
    width: number,
): { src: string; srcset?: string } {
    const single = thumb(url, width);
    const double = thumb(url, width * 2);

    if (single === double) {
        return { src: single };
    }

    return { src: single, srcset: `${single} 1x, ${double} 2x` };
}

/**
 * The bundled stand-in shown when a listing has no usable photo. Every category
 * has one, because a card with a broken image reads as a broken site — an
 * imperfect stock photo does not.
 */
const CATEGORY_FALLBACKS: Record<string, string> = {
    accommodation: '/images/explore/accommodation-1.jpg',
    activity: '/images/explore/activity-1.jpg',
    restaurant: '/images/explore/restaurant-1.jpg',
    vehicle: '/images/explore/vehicle-1.jpg',
};

/** Used for anything that isn't one of the four listing types (cities, destinations). */
const GENERIC_FALLBACK = '/images/explore/region-khomas.jpg';

export function categoryFallback(type?: string | null): string {
    return (
        (type !== null && type !== undefined && CATEGORY_FALLBACKS[type]) ||
        GENERIC_FALLBACK
    );
}

/**
 * `@error` handler for any <img> showing catalog media — swaps in the category
 * stand-in when the real photo fails to load.
 *
 * A `?? fallback` on the src only covers a *missing* URL. Plenty of rows have a
 * URL that is simply dead: a scraped operator's site that moved, a photo removed
 * from its origin, or a stored absolute URL whose host changed (2026-08-09).
 * Those render as alt text with an empty frame, which is exactly what this
 * catches — the browser telling us the URL didn't work is the only reliable
 * signal, since nothing about the string itself says so.
 *
 * `srcset` has to go before `src`: with both present the browser picks from
 * `srcset`, so reassigning `src` alone would change nothing.
 */
export function onImageError(event: Event, type?: string | null): void {
    const img = event.target as HTMLImageElement | null;

    if (img === null) {
        return;
    }

    // Should the fallback itself 404, this would otherwise fire forever.
    if (img.dataset.fallbackApplied === 'true') {
        return;
    }

    img.dataset.fallbackApplied = 'true';
    img.removeAttribute('srcset');
    img.src = categoryFallback(type);
}

function rewriteThroughCloudflare(url: string, width: number): string | null {
    const options = `format=auto,fit=scale-down,width=${width},quality=${config.quality}`;

    // Root-relative URLs (bundled fallbacks, legacy /storage uploads) are the
    // app origin, which is NOT Cloudflare-proxied — see config/media.php for
    // why only the media CDN origin qualifies.
    for (const origin of config.origins) {
        if (origin !== '' && url.startsWith(`${origin}/`)) {
            return `${origin}/cdn-cgi/image/${options}${url.slice(origin.length)}`;
        }
    }

    return null;
}

/**
 * Unsplash resizes off its own query parameters, so the placeholder heroes
 * carrying a hardcoded `?w=1200` shrink for free — no Cloudflare, no cost, and
 * it works even while transforms are switched off.
 */
function resizeUnsplash(url: string, width: number): string {
    try {
        const parsed = new URL(url);
        parsed.searchParams.set('w', String(width));

        return parsed.toString();
    } catch {
        return url;
    }
}
