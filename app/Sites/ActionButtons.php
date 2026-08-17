<?php

namespace App\Sites;

use App\Models\Site;

/**
 * Which action button appears where, and on which screen.
 *
 * Four actions — enquiry, WhatsApp, call, and one the business writes itself —
 * each of which can be placed in three areas (the menu bar, the opening screen,
 * the strip at the foot of the screen), separately for a phone and for a
 * desktop. Twelve switches per action, and they are all switches rather than
 * rules because the right answer is a business decision that changes per
 * business: WhatsApp is how most of this market answers and is close to useless
 * on a desktop; a booking button in the menu is redundant beside a "Request
 * availability" band and pushes a long name onto a second line; the same button
 * at the foot of a phone screen is exactly right.
 *
 * ## What is stored, and what a missing value means
 *
 * `sites.action_buttons` holds one entry per action: the places it is shown,
 * its label, and — for the custom button — where it points. **Null means the
 * defaults below**, so every site built so far reads correctly without being
 * migrated, and so a later change of mind about what a good default is reaches
 * the sites nobody has configured.
 *
 * A label is a *number of pixels* as much as a word: these sit in a 64px bar
 * and in a strip divided by three on a 375px screen. Hence the cap, and hence
 * a null label meaning "work it out" rather than an empty button.
 *
 * ## What this deliberately does not decide
 *
 * Whether the target exists. A WhatsApp button on a site with no WhatsApp
 * number, or an enquiry button on a site with nothing to enquire about, is not
 * rendered however it is placed — that is App\Sites\Rendering\SiteActions,
 * which knows the page. This class knows only what the business asked for.
 */
final class ActionButtons
{
    public const ACTIONS = ['enquiry', 'whatsapp', 'call', 'map', 'custom'];

    public const AREAS = ['menu', 'hero', 'footer'];

    public const DEVICES = ['phone', 'desktop'];

    /** Longer than this stops being a button and starts being a sentence. */
    public const MAX_LABEL = 24;

    /**
     * @param  array<string, array{places: array<int, string>, label: string|null, href: string|null}>  $config
     */
    private function __construct(private readonly array $config) {}

    public static function for(Site $site): self
    {
        return new self(self::normalise($site->action_buttons));
    }

    /**
     * The placement a site gets when nobody has said otherwise.
     *
     * Read it as a sentence: the enquiry button is under the headline on a
     * desktop and at the foot of the screen on a phone; WhatsApp is at the
     * foot of the screen on both (this market answers on WhatsApp regardless
     * of device); the call and map buttons are phone-only; the business's own
     * button — "About us" until they change it — is under the headline on both.
     * Nothing is in the menu, which is a list of places on the page and not a
     * place for a button.
     *
     * @return array<string, array{places: array<int, string>, label: string|null, href: string|null}>
     */
    public static function defaults(): array
    {
        return [
            'enquiry' => ['places' => ['hero.desktop', 'footer.phone'], 'label' => null, 'href' => null],
            'whatsapp' => ['places' => ['footer.phone', 'footer.desktop'], 'label' => null, 'href' => null],
            'call' => ['places' => ['footer.phone'], 'label' => null, 'href' => null],
            'map' => ['places' => ['footer.phone'], 'label' => null, 'href' => null],
            'custom' => ['places' => ['hero.phone', 'hero.desktop'], 'label' => null, 'href' => null],
        ];
    }

    /**
     * Whatever was stored, reduced to something the renderer can trust.
     *
     * Unknown actions, unknown areas and unknown devices are dropped rather
     * than carried: this column is written by a form today and could be written
     * by an import tomorrow, and a page is not the place to find out that
     * "footer.tablet" was a typo. An action missing from the payload takes its
     * default, so adding a fifth action later does not leave every stored site
     * without it.
     *
     * @return array<string, array{places: array<int, string>, label: string|null, href: string|null}>
     */
    public static function normalise(mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $config = [];

        foreach (self::defaults() as $action => $default) {
            $entry = is_array($stored[$action] ?? null) ? $stored[$action] : null;

            if ($entry === null) {
                $config[$action] = $default;

                continue;
            }

            $places = [];

            foreach (self::places() as $place) {
                if (in_array($place, (array) ($entry['places'] ?? []), true)) {
                    $places[] = $place;
                }
            }

            $config[$action] = [
                'places' => $places,
                'label' => self::text($entry['label'] ?? null, self::MAX_LABEL),
                'href' => $action === 'custom' ? self::text($entry['href'] ?? null, 2048) : null,
            ];
        }

        return $config;
    }

    /**
     * Every "area.device" there is, in the order the panel offers them.
     *
     * @return array<int, string>
     */
    public static function places(): array
    {
        $places = [];

        foreach (self::AREAS as $area) {
            foreach (self::DEVICES as $device) {
                $places[] = $area.'.'.$device;
            }
        }

        return $places;
    }

    /**
     * @return array<string, array{places: array<int, string>, label: string|null, href: string|null}>
     */
    public function all(): array
    {
        return $this->config;
    }

    /**
     * Which screens this action is shown on in this area — 'both', 'phone',
     * 'desktop', or null for "not here at all".
     *
     * A string rather than two booleans because it is about to become a CSS
     * class: the page is rendered once and the stylesheet decides, since a
     * server that guesses the screen from a user agent guesses wrong.
     */
    public function visibility(string $action, string $area): ?string
    {
        $phone = $this->shows($action, $area, 'phone');
        $desktop = $this->shows($action, $area, 'desktop');

        return match (true) {
            $phone && $desktop => 'both',
            $phone => 'phone',
            $desktop => 'desktop',
            default => null,
        };
    }

    public function shows(string $action, string $area, string $device): bool
    {
        return in_array($area.'.'.$device, $this->config[$action]['places'] ?? [], true);
    }

    public function label(string $action): ?string
    {
        return $this->config[$action]['label'] ?? null;
    }

    public function href(string $action): ?string
    {
        return $this->config[$action]['href'] ?? null;
    }

    private static function text(mixed $value, int $max): ?string
    {
        $value = trim((string) (is_scalar($value) ? $value : ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
