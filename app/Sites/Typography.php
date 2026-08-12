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
 * The bar is a fixed height so the hero can be pulled up under it, which means
 * the business's name has exactly one line to live on. Set too large it is
 * clipped; set too small it disappears against a photograph. So the size is
 * computed from the length of the name by default — and overridable, because a
 * name is a piece of branding and nobody should have to accept our arithmetic
 * about it.
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
            '18' => '18px (the usual answer)',
            '20' => '20px',
            '22' => '22px',
            '24' => '24px',
        ];
    }

    /**
     * How large the name is set, in pixels.
     *
     * Automatic unless somebody said otherwise. The steps are the widths the
     * bar can actually give a name: it has one line, and beside a six-item menu
     * on a laptop there is not much of it. Erring small is deliberate — a name
     * one step smaller than ideal still reads, and a clipped one does not.
     */
    public static function brandSize(Site $site): int
    {
        if ($site->brand_size !== null) {
            return $site->brand_size;
        }

        // Calibrated against a real one: "Ongombo West #56 Hunting Safari" is
        // 30 characters and wants 18px — smaller than that and it disappears
        // into the photograph, larger and it crowds a six-item menu.
        return match (true) {
            mb_strlen($site->name) > 36 => 16,
            mb_strlen($site->name) > 24 => 18,
            default => 20,
        };
    }
}
