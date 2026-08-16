<?php

namespace App\Sites\Rendering;

use App\Models\Site;
use App\Models\SiteBlock;
use App\Sites\ActionButtons;
use App\Sites\Blocks\EnquiryBlock;

/**
 * The buttons a page offers, resolved once and handed to the three places that
 * render them: the menu bar, the opening screen, and the strip fixed to the
 * foot of the screen.
 *
 * Two questions are answered here, and they are different questions:
 *
 * - **Where does each action point on this page?** The enquiry button needs an
 *   anchor, and which band it lands on depends on what the page has. The custom
 *   button needs one too, unless the business typed a link.
 * - **Is it wanted here, on this screen?** That is App\Sites\ActionButtons —
 *   the business's own choice, stored per action, area and device.
 *
 * A button survives only if both answers are yes. A WhatsApp button placed at
 * the foot of a phone screen on a site with no WhatsApp number is not rendered
 * at all: a button that opens WhatsApp on nobody is worse than no button, and
 * that must not depend on somebody remembering to switch it off.
 *
 * ## Where the enquiry button points, when there is nothing to book
 *
 * Booking, then enquiry, then contact. The booking band renders only where the
 * property has sellable inventory with us and the enquiry form only where we
 * hold a listing — so on most sites neither exists, and a template whose main
 * button is missing two thirds of the time is not a template. The label follows
 * the band, which is the business's own wording, unless it is too long to be a
 * button or the business has written one here.
 *
 * Anchors are the same `s{n}` numbers the page renders, counted the same way
 * (hero and footer are not sections). If that numbering ever changes, it
 * changes in both places or the buttons scroll to the wrong band.
 */
final class SiteActions
{
    /** Bands the enquiry button will point at, best first. */
    private const PRIMARY = ['booking', 'enquiry', 'contact'];

    private const LABELS = [
        'booking' => 'Book now',
        'enquiry' => 'Enquire',
        'contact' => 'Contact',
        'about' => 'About us',
        'whatsapp' => 'WhatsApp',
        'call' => 'Call',
        'map' => 'Map',
    ];

    /**
     * Longer than this and the band's own heading is a sentence rather than a
     * button. "Request availability" is the generated default for an enquiry
     * band and is exactly the case: right above the form, wrong inside a
     * 96px-wide button on a phone.
     */
    private const MAX_DERIVED_LABEL = 14;

    /**
     * @param  array<string, ActionButton>  $buttons  every action that has somewhere to go, keyed by action
     */
    private function __construct(
        private readonly ActionButtons $placement,
        private readonly array $buttons,
    ) {}

    /**
     * @param  iterable<int, SiteBlock>  $blocks  the page's renderable blocks, in render order
     */
    public static function for(Site $site, iterable $blocks): self
    {
        $bands = self::bands($blocks, $site);
        $placement = ActionButtons::for($site);
        $buttons = [];

        if ($primary = self::primaryBand($bands)) {
            $buttons['enquiry'] = new ActionButton(
                'enquiry',
                $placement->label('enquiry') ?? $primary['label'],
                '#'.$primary['anchor'],
                'both',
            );
        }

        if ($whatsapp = SafeLink::whatsapp($site->whatsapp)) {
            $buttons['whatsapp'] = new ActionButton(
                'whatsapp',
                $placement->label('whatsapp') ?? self::LABELS['whatsapp'],
                $whatsapp,
                'both',
                external: true,
                icon: 'whatsapp',
            );
        }

        if ($tel = self::tel($site->contact_phone)) {
            $buttons['call'] = new ActionButton(
                'call',
                $placement->label('call') ?? self::LABELS['call'],
                'tel:'.$tel,
                'both',
                icon: 'phone',
            );
        }

        if ($mapUrl = self::mapsUrl($site)) {
            $buttons['map'] = new ActionButton(
                'map',
                $placement->label('map') ?? self::LABELS['map'],
                $mapUrl,
                'both',
                external: true,
                icon: 'map',
            );
        }

        // The business's own button. It defaults to the About band, because
        // that is the one thing nearly every site has and the thing a visitor
        // on the opening screen most often wants next — but the label and the
        // link are theirs, and a typed link wins over the band.
        $typed = $placement->href('custom');
        $about = $bands['about'] ?? null;
        $href = $typed !== null ? SafeLink::href($typed) : ($about !== null ? '#'.$about['anchor'] : null);

        if ($href !== null && $href !== '#') {
            $buttons['custom'] = new ActionButton(
                'custom',
                // "About us", not the band's own heading. A heading introduces
                // a section and a button asks for something, and they are not
                // the same words: a band headed "Welcome" is fine above the
                // text and meaningless on a button.
                $placement->label('custom') ?? self::LABELS['about'],
                $href,
                'both',
                external: ! str_starts_with($href, '#') && ! str_starts_with($href, '/'),
            );
        }

        return new self($placement, $buttons);
    }

    /**
     * The buttons for one area, in a fixed order, each carrying the screens it
     * is wanted on.
     *
     * The order is fixed rather than following the configuration, because a
     * strip whose buttons move about from page to page is a strip nobody
     * learns. It reads differently in the strip on purpose: reading order puts
     * the important thing first, but a row of equal thumb targets across the
     * bottom of a phone puts it last, under the thumb rather than under the
     * hand holding the phone.
     *
     * @return array<int, ActionButton>
     */
    public function buttons(string $area): array
    {
        $out = [];
        $order = $area === 'footer'
            ? ['call', 'whatsapp', 'map', 'custom', 'enquiry']
            : ActionButtons::ACTIONS;

        foreach ($order as $action) {
            $button = $this->buttons[$action] ?? null;
            $visibility = $this->placement->visibility($action, $area);

            if ($button === null || $visibility === null) {
                continue;
            }

            $out[] = new ActionButton(
                $button->key,
                $button->label,
                $button->href,
                $visibility,
                $button->external,
                $button->icon,
            );
        }

        return $out;
    }

    /**
     * The enquiry button the bar shows once the page has scrolled.
     *
     * The opening screen carries this button and then scrolls away with it, and
     * from that moment the visitor has nothing to press without scrolling back
     * or finding the burger. So the bar picks it up — on a wide screen in place
     * of the menu item it duplicates, and on a burger-width screen simply
     * beside the burger.
     *
     * Not shown where the business has *placed* the enquiry button in the menu:
     * they asked for it there from the first pixel, and two of the same button
     * in one bar is not what anybody meant. A phone gets nothing either — the
     * strip at the foot of the screen is already carrying it, and the bar up
     * there has a name and a burger and no room.
     */
    public function scrollButton(): ?ActionButton
    {
        $button = $this->buttons['enquiry'] ?? null;

        return $button !== null && $this->placement->visibility('enquiry', 'menu') === null
            ? $button
            : null;
    }

    /**
     * Which screens an area has any button on — 'both', 'phone', 'desktop', or
     * null for none at all.
     *
     * The strip at the foot of the page needs this for itself: it is a fixed
     * element with a background and a border, so an empty one is a bar of
     * nothing across the bottom of the screen rather than nothing at all.
     */
    public function visibility(string $area): ?string
    {
        $phone = false;
        $desktop = false;

        foreach ($this->buttons($area) as $button) {
            $phone = $phone || $button->visibility !== 'desktop';
            $desktop = $desktop || $button->visibility !== 'phone';
        }

        return match (true) {
            $phone && $desktop => 'both',
            $phone => 'phone',
            $desktop => 'desktop',
            default => null,
        };
    }

    /**
     * The bands this page has that a button can point at, keyed by type.
     *
     * @param  iterable<int, SiteBlock>  $blocks
     * @return array<string, array{anchor: string, label: string}>
     */
    private static function bands(iterable $blocks, Site $site): array
    {
        $found = [];
        $n = 0;

        foreach ($blocks as $block) {
            if (in_array($block->type, ['hero', 'footer'], true)) {
                continue;
            }

            $n++;

            if (in_array($block->type, [...self::PRIMARY, 'about'], true) && ! isset($found[$block->type])) {
                $found[$block->type] = ['anchor' => 's'.$n, 'label' => self::label($block, $site)];
            }
        }

        return $found;
    }

    /**
     * What the menu calls the contact form.
     *
     * The menu bar used to label the item with the band's own heading while the
     * button beside it used this — so one site read "Request availability" in
     * the menu and "Book a table" on the button, pointing at the same form. The
     * nav asks here now, so there is one answer.
     *
     * @param  iterable<int, SiteBlock>  $blocks
     */
    public static function enquiryLabel(Site $site, iterable $blocks): ?string
    {
        foreach ($blocks as $block) {
            if ($block->type === 'enquiry') {
                return self::label($block, $site);
            }
        }

        return null;
    }

    /**
     * @param  array<string, array{anchor: string, label: string}>  $bands
     * @return array{anchor: string, label: string}|null
     */
    private static function primaryBand(array $bands): ?array
    {
        foreach (self::PRIMARY as $type) {
            if (isset($bands[$type])) {
                return $bands[$type];
            }
        }

        return null;
    }

    private static function label(SiteBlock $block, Site $site): string
    {
        // The contact form says what it is for, and the button follows it: a
        // site taking orders should not have a menu button reading "Enquire".
        // The owner's own wording wins, then the type's short default — which
        // is a different string from the heading above the form on purpose,
        // because "Request availability" is right there and far too long here.
        if ($block->type === 'enquiry') {
            $typed = trim((string) ($block->data['button_label'] ?? ''));

            if ($typed !== '') {
                return $typed;
            }

            // Then the band's own heading, where it is short enough to be a
            // button — a site headed "Ask us" has already chosen its word, and
            // replacing it with a generic one would be this change taking
            // something away rather than adding a default. Resolved rather than
            // read raw, so a heading left over from a form type the business no
            // longer offers does not outlive it.
            $type = EnquiryBlock::formTypeFor($site, $block->data);
            $heading = EnquiryBlock::heading($type, $block->data);

            return mb_strlen($heading) <= self::MAX_DERIVED_LABEL
                ? $heading
                : $type->buttonLabel();
        }

        $default = self::LABELS[$block->type] ?? self::LABELS['booking'];

        // Only where the heading is about the action. A contact band is
        // commonly headed "Find us", which on a button reads as directions
        // rather than as an invitation to get in touch.
        if ($block->type === 'contact') {
            return $default;
        }

        $heading = trim((string) ($block->data['heading'] ?? ''));

        return $heading !== '' && mb_strlen($heading) <= self::MAX_DERIVED_LABEL ? $heading : $default;
    }

    /**
     * A `tel:` target, or null when what was typed cannot be dialled. Same
     * shape as SafeLink::whatsapp and for the same reason: a button that opens
     * the dialler on nobody is worse than no button.
     */
    private static function tel(?string $phone): ?string
    {
        $value = preg_replace('/[^\d+]/', '', (string) $phone) ?? '';

        return strlen(preg_replace('/\D+/', '', $value) ?? '') < 6 ? null : $value;
    }

    /**
     * A Google Maps search URL, or null where the site has no location at all.
     * Same approach as the location block: coordinates first, address as
     * fallback, and nothing when there is nothing to navigate to.
     */
    private static function mapsUrl(Site $site): ?string
    {
        $coords = filled($site->latitude) && filled($site->longitude);
        $query = $coords ? $site->latitude.','.$site->longitude : (string) $site->address;

        return filled($query)
            ? 'https://www.google.com/maps/search/?api=1&query='.urlencode($query)
            : null;
    }
}
