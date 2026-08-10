<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a listing's text or photos came from — and what we may do with them.
 *
 * Two questions are answered here, and they are not the same question:
 *
 *  - **May we publish it?** (`publishable()`) A directory's prose and
 *    photography belong to that directory. Editing them does not make them
 *    ours, so they are kept as internal reference material and can never reach
 *    the live `image`/`gallery`/`description` of a public page. Everything else
 *    is either the owner's (given to us knowingly), licensed, or written by us.
 *
 *  - **Is it better than what we have?** (`rank()`) A listing that starts life
 *    as a directory record should upgrade itself the moment a better source
 *    appears — the owner's own website is crawled, an admin writes copy, the
 *    partner claims the listing. Writers compare ranks instead of only filling
 *    blanks, so that upgrade happens on its own.
 */
enum ContentSource: string implements HasColor, HasLabel
{
    /** Entered by the partner or an admin — the highest authority we have. */
    case Partner = 'partner';

    /**
     * Legacy value for the same thing: photos_source used 'manual' before this
     * enum existed. Kept so old rows still cast.
     */
    case Manual = 'manual';

    /** The listing's own website. The owner's material — needs their approval to publish. */
    case WebsiteScrape = 'website_scrape';

    /** Copy we wrote ourselves, from facts (see AIDescriptionGeneratorService). */
    case AiGenerated = 'ai_generated';

    /** Google Places, under their terms — publishable, but expires (google_photos_expire_at). */
    case GooglePlaces = 'google_places';

    /** A third-party directory (namibweb, namibiayp, NTB…). Reference only. */
    case Directory = 'directory';

    public function getLabel(): string
    {
        return match ($this) {
            self::Partner => 'Partner-supplied',
            self::Manual => 'Entered by hand',
            self::WebsiteScrape => "Listing's own website",
            self::AiGenerated => 'Written by us',
            self::GooglePlaces => 'Google Places',
            self::Directory => 'Directory (reference only)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Partner, self::Manual => 'success',
            self::WebsiteScrape => 'info',
            self::AiGenerated => 'primary',
            self::GooglePlaces => 'warning',
            self::Directory => 'danger',
        };
    }

    /**
     * May content from this source appear on a public page?
     *
     * The one `false` is the whole point of this enum: scraped directory text
     * and photography stay internal no matter how the pipeline is wired later.
     */
    public function publishable(): bool
    {
        return $this !== self::Directory;
    }

    /** Higher wins. Only used to compare sources, never persisted. */
    public function rank(): int
    {
        return match ($this) {
            self::Partner, self::Manual => 100,
            self::WebsiteScrape => 80,
            self::AiGenerated => 60,
            self::GooglePlaces => 50,
            self::Directory => 20,
        };
    }

    /**
     * Should content from this source replace what a listing already holds?
     *
     * Unknown provenance (`null`) is treated as "at least as good as anything" —
     * a value someone put there before we tracked sources is not ours to
     * overwrite. Callers handle the genuinely empty case themselves, since an
     * empty field is always safe to fill.
     */
    public function outranks(?self $current): bool
    {
        return $current !== null && $this->rank() > $current->rank();
    }
}
