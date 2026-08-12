<?php

namespace App\Sites;

use App\Models\Site;

/**
 * The two typefaces a customer website is set in, and the size of its name.
 *
 * ## Why these are stacks and not webfonts
 *
 * Every option here is a font the reader already has. That is the same
 * deferral the stylesheet has always made: the typography costs zero bytes,
 * paints on the first frame instead of swapping, and works on the old phone
 * over the slow connection the flyer promises. A licensed WOFF2 behind one of
 * these keys stays a one-line change for the day somebody chooses and pays for
 * a face.
 *
 * ## Why the name has its own size
 *
 * The bar is a fixed height so the hero can be pulled up under it, and inside
 * that height the name gets at most two lines. Set too small it disappears
 * against a photograph; set too large it wraps where it did not need to. So the
 * size is computed from the length of the name by default — separately for a
 * phone and for a wide screen, because one number cannot serve a 360px bar and
 * a 1280px one — and every part of it is overridable, because a name is a piece
 * of branding and nobody should have to accept our arithmetic about it.
 */
class Typography
{
    public const DEFAULT_DISPLAY = 'serif';

    public const DEFAULT_BODY = 'sans';

    /**
     * The faces on offer, and what each one is for.
     *
     * @return array<string, array{label: string, stack: string, note: string}>
     */
    public static function faces(): array
    {
        return [
            'serif' => [
                'label' => 'Editorial serif',
                'stack' => "'Iowan Old Style', 'Palatino Linotype', Palatino, Georgia, ui-serif, serif",
                'note' => 'The house default for headings. Warm and magazine-like.',
            ],
            'georgia' => [
                'label' => 'Georgia',
                'stack' => "Georgia, 'Times New Roman', ui-serif, serif",
                'note' => 'A sturdier serif — holds up better at small sizes than the editorial one.',
            ],
            'sans' => [
                'label' => 'System sans',
                'stack' => "system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
                'note' => 'The house default for reading. Looks native on whatever the visitor is using.',
            ],
            'grotesk' => [
                'label' => 'Helvetica / Arial',
                'stack' => "'Helvetica Neue', Helvetica, Arial, sans-serif",
                'note' => 'Neutral and a little heavier. A safe choice for a name over a photograph.',
            ],
            'verdana' => [
                'label' => 'Verdana',
                'stack' => "Verdana, Geneva, 'DejaVu Sans', sans-serif",
                'note' => 'The most legible of these at small sizes, and the widest.',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_map(fn (array $face): string => $face['label'], self::faces());
    }

    public static function stack(?string $key, string $fallback): string
    {
        $faces = self::faces();

        return $faces[$key ?? '']['stack'] ?? $faces[$fallback]['stack'];
    }

    public static function displayStack(Site $site): string
    {
        return self::stack($site->font_display, self::DEFAULT_DISPLAY);
    }

    public static function bodyStack(Site $site): string
    {
        return self::stack($site->font_body, self::DEFAULT_BODY);
    }

    /**
     * The face the name in the bar is set in.
     *
     * Defaults to the body face rather than the display one, and that is a
     * change of mind rather than an oversight: an editorial serif at 18px over
     * an arbitrary photograph is the one place on these pages where the house
     * face reads as thin instead of as characterful.
     */
    public static function brandStack(Site $site): string
    {
        return self::stack($site->brand_font ?? $site->font_body, self::DEFAULT_BODY);
    }

    /**
     * Sizes offered for the name, in pixels. The blank one is the automatic.
     *
     * The key type is not a typo: PHP turns a numeric string key into an
     * integer on the way into the array, so '14' arrives as 14 while '' stays a
     * string. The action that reads this casts back to string before comparing.
     *
     * @return array<int|string, string>
     */
    public static function brandSizeOptions(): array
    {
        return [
            '' => 'Automatic — from the length of the name',
            '14' => '14px',
            '16' => '16px',
            '17' => '17px',
            '18' => '18px',
            '20' => '20px',
            '22' => '22px',
            '24' => '24px',
        ];
    }

    /**
     * How large the name is set on a wide screen, in pixels.
     *
     * A phone and a laptop cannot share one number. The size that keeps a long
     * name readable beside a burger on a 360px screen is visibly undersized on
     * a laptop, where the same bar is three times as wide — so there are two,
     * and the stylesheet switches between them at 640px.
     */
    public static function brandSize(Site $site): int
    {
        if ($site->brand_size !== null) {
            return $site->brand_size;
        }

        return match (true) {
            mb_strlen($site->name) > 36 => 18,
            mb_strlen($site->name) > 24 => 20,
            default => 22,
        };
    }

    /**
     * And how large on a phone.
     *
     * Measured rather than guessed: "Ongombo West #56 Hunting Safari" set at
     * 18px needs about 350px, and beside the burger on a 360px screen there are
     * 252. No size in this range keeps a name that long on one line, which is
     * why the bar lets it wrap to a second rather than cutting it. These steps
     * decide how often that happens, not whether the name survives.
     */
    public static function brandSizeMobile(Site $site): int
    {
        if ($site->brand_size_mobile !== null) {
            return $site->brand_size_mobile;
        }

        // An explicit desktop size with no mobile one steps down by two rather
        // than being taken literally: somebody who chose 22 for a laptop did
        // not choose 22 for a phone, and a floor of 14 keeps it legible.
        if ($site->brand_size !== null) {
            return max(14, $site->brand_size - 2);
        }

        return match (true) {
            mb_strlen($site->name) > 36 => 15,
            mb_strlen($site->name) > 24 => 17,
            default => 20,
        };
    }
}
