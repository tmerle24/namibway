<?php

namespace App\Sites;

/**
 * Which package a website is sold as.
 *
 * Standard is the one-page site built to load on an old phone. Enterprise is
 * the showcase: a full-screen opening with video, larger type, more motion and
 * pages of its own. Same blocks, same renderer, same enquiry path - the edition
 * only adds a stylesheet and a script on top (sites.partials.showcase), and has
 * its own byte budget (config/sites.php).
 */
enum SiteEdition: string
{
    case Standard = 'standard';
    case Enterprise = 'enterprise';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::Enterprise => 'Enterprise - showcase with video and subpages',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
