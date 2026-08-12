<?php

namespace App\Sites\Rendering;

/**
 * One button, ready to render: what it says, where it goes, and which screens
 * it is for.
 *
 * The views take these and do no deciding of their own — the bar, the opening
 * screen and the strip at the foot of the page all render the same shape, so a
 * change of policy happens in one place instead of three that can drift.
 */
final class ActionButton
{
    public function __construct(
        /** One of App\Sites\ActionButtons::ACTIONS. */
        public readonly string $key,
        public readonly string $label,
        public readonly string $href,
        /** 'both', 'phone' or 'desktop' — becomes a class, see the stylesheet. */
        public readonly string $visibility,
        /** Leaves the site, so it opens in its own tab and disowns us in the referrer. */
        public readonly bool $external = false,
        /** A mark from partials/action-icon, where the word alone would not be recognised. */
        public readonly ?string $icon = null,
    ) {}

    /**
     * The enquiry button is the one the site exists for, so it is the filled
     * one wherever it appears and everything else is quieter. Deliberately not
     * "whichever comes first": which button comes first differs between a phone
     * and a desktop, and the page is rendered once for both.
     */
    public function isPrimary(): bool
    {
        return $this->key === 'enquiry';
    }

    /** The class that hides it on the screens it is not meant for. */
    public function deviceClass(): string
    {
        return match ($this->visibility) {
            'phone' => 'at-phone',
            'desktop' => 'at-desktop',
            default => '',
        };
    }
}
