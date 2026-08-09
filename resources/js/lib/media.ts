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

    if (!config.enabled) {
        return url;
    }

    return rewriteThroughCloudflare(url, snapped) ?? url;
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
