<?php

namespace App\Sites\Rendering;

/**
 * Link targets, filtered down to the ones that cannot execute.
 *
 * The kit's security model is that an owner cannot introduce code — no HTML
 * block, no script, no embed. A link target is the one field left where a typed
 * string could still become code: `javascript:` and `data:` URLs both run in
 * the page's own origin, on a domain we host under our own certificate.
 *
 * So the scheme is checked against an allow-list rather than a deny-list, and
 * anything unrecognised becomes '#'. A link that quietly goes nowhere is a
 * small failure; the alternative is not.
 */
class SafeLink
{
    private const SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function href(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '#';
        }

        // Same-page anchors and root-relative paths are ours by construction.
        if (str_starts_with($value, '#') || str_starts_with($value, '/')) {
            return $value;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        if ($scheme === '') {
            // A bare "example.com" typed into a link field. Treating it as
            // https is what the person meant; treating it as a relative path
            // would point at a page of their own site that does not exist.
            return 'https://'.ltrim($value, '/');
        }

        return in_array($scheme, self::SCHEMES, true) ? $value : '#';
    }

    /**
     * A wa.me link from whatever the owner typed — a local number, an
     * international one, spaces and dashes and a leading plus.
     *
     * Returns null rather than a broken link when nothing digit-like is left,
     * so the button disappears instead of opening WhatsApp on nobody.
     */
    public static function whatsapp(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number) ?? '';

        return strlen($digits) < 8 ? null : 'https://wa.me/'.$digits;
    }
}
