<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Whether a customer website is publication or research.
 *
 * That is the whole distinction, and it is a bigger one than it looks. A draft
 * is generated to show a business what a website of theirs could be, before
 * anybody has agreed to anything — so it is not indexable, not linkable without
 * its token, and may carry pictures that could never appear on a published
 * page. A published site is publication under that business's name, which only
 * they can consent to.
 *
 * The subscription states from WEBSITE_BUILDER.md §6 — trial, payment failed,
 * grace, suspended, cancelled — are not here. They describe whether a customer
 * is paying, not whether a page is publication, and inventing them before there
 * is any billing would be guessing at transitions nobody has decided.
 */
enum SiteStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Published = 'published';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Published => 'success',
        };
    }

    public function isPublished(): bool
    {
        return $this === self::Published;
    }
}
