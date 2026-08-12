<?php

namespace App\Sites\Rendering;

use App\Models\Site;
use App\Models\SiteBlock;

/**
 * The three things a visitor is ever asked to do on a customer's website:
 * book, call, or message on WhatsApp.
 *
 * Resolved once and used by three places that must agree — the button in the
 * bar, the buttons under the hero headline, and the action bar fixed to the
 * foot of the page on a phone. They are the same actions in three positions,
 * so working out what they are is not a job any of the three should be doing
 * for itself: a hero offering "Book now" while the bar offers "Enquire" is one
 * site disagreeing with itself about what it sells.
 *
 * ## What "book" means when there is nothing to book
 *
 * Most sites have no booking block: it only renders where the property has
 * sellable inventory with us (see SiteController::shouldRender). The enquiry
 * form is the next best thing and, failing that, the contact details. So the
 * primary action degrades — booking, then enquiry, then contact — rather than
 * disappearing, because a website whose main button is missing on two thirds
 * of the customers is not a template, it is a special case.
 *
 * Anchors are the same `s{n}` numbers the page renders, counted the same way
 * (hero and footer are not sections). If that numbering ever changes, it
 * changes in both places or the buttons scroll to the wrong band.
 *
 * ## Switching one off
 *
 * Each of the three has a switch on the site, on by default. This is the one
 * place they are read, so a button that is off is off everywhere — the bar,
 * the hero and the action bar all ask this object, and none of them asks the
 * site. What the switch does not do is hide the detail: a business with the
 * Call button off still has its number in the contact section and the footer,
 * because that is a contact detail rather than a button.
 */
final class SiteActions
{
    /** Blocks that can be the primary action, best first. */
    private const PRIMARY = ['booking', 'enquiry', 'contact'];

    private const LABELS = [
        'booking' => 'Book now',
        'enquiry' => 'Enquire',
        'contact' => 'Contact',
    ];

    /**
     * Longer than this and the owner's own heading is a sentence rather than a
     * button. "Request availability" is the generated default for an enquiry
     * block and is exactly the case: right above the form, wrong inside a
     * 96px-wide button on a phone.
     */
    private const MAX_LABEL = 14;

    private function __construct(
        public readonly ?string $primaryAnchor,
        public readonly string $primaryLabel,
        /** Which of self::PRIMARY the anchor came from, or null when there is none. */
        public readonly ?string $primaryType,
        public readonly ?string $whatsapp,
        public readonly ?string $tel,
    ) {}

    /**
     * @param  iterable<int, SiteBlock>  $blocks  the page's renderable blocks, in render order
     */
    public static function for(Site $site, iterable $blocks): self
    {
        $found = [];
        $n = 0;

        foreach ($blocks as $block) {
            if (in_array($block->type, ['hero', 'footer'], true)) {
                continue;
            }

            $n++;

            if (in_array($block->type, self::PRIMARY, true) && ! isset($found[$block->type])) {
                $found[$block->type] = [
                    'anchor' => 's'.$n,
                    'label' => self::label($block),
                ];
            }
        }

        $primary = null;
        $type = null;

        foreach (self::PRIMARY as $candidate) {
            if (isset($found[$candidate])) {
                $primary = $found[$candidate];
                $type = $candidate;

                break;
            }
        }

        $on = $site->show_book_button;

        return new self(
            $on ? ($primary['anchor'] ?? null) : null,
            $primary['label'] ?? self::LABELS['booking'],
            $on ? $type : null,
            $site->show_whatsapp_button ? SafeLink::whatsapp($site->whatsapp) : null,
            $site->show_call_button ? self::tel($site->contact_phone) : null,
        );
    }

    /**
     * Whether there is anything to put in the action bar at all. A site with no
     * phone, no WhatsApp and nothing to book gets no bar rather than an empty
     * strip across the bottom of every page.
     */
    public function any(): bool
    {
        return $this->showsPrimaryInBar() || $this->whatsapp !== null || $this->tel !== null;
    }

    /**
     * Whether the strip at the foot of the page carries the primary button.
     *
     * It does, unless the primary is only the contact section and Call or
     * WhatsApp is already there: a third button meaning "the same two things,
     * further down the page" is noise on the one surface with least room for
     * it. The bar at the top and the hero still offer it — there it is the only
     * action on the screen, and it means "get in touch".
     */
    public function showsPrimaryInBar(): bool
    {
        if ($this->primaryAnchor === null) {
            return false;
        }

        return $this->primaryType !== 'contact' || ($this->tel === null && $this->whatsapp === null);
    }

    private static function label(SiteBlock $block): string
    {
        $default = self::LABELS[$block->type] ?? self::LABELS['booking'];

        // Only where the heading is about the action. A contact section is
        // commonly headed "Find us", which on a button reads as directions
        // rather than as an invitation to get in touch.
        if ($block->type === 'contact') {
            return $default;
        }

        $heading = trim((string) ($block->data['heading'] ?? ''));

        return $heading !== '' && mb_strlen($heading) <= self::MAX_LABEL ? $heading : $default;
    }

    /**
     * A `tel:` target, or null when what was typed cannot be dialled. Same
     * shape as SafeLink::whatsapp and for the same reason: a button that opens
     * the dialler on nothing is worse than no button.
     */
    private static function tel(?string $phone): ?string
    {
        $value = preg_replace('/[^\d+]/', '', (string) $phone) ?? '';

        return strlen(preg_replace('/\D+/', '', $value) ?? '') < 6 ? null : $value;
    }
}
