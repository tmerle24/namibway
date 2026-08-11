<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * The two things a document in the team's own filing cabinet can be.
 *
 * A `file` is something produced elsewhere and kept here — a signed contract, a
 * logo, a flyer. Its content is a blob nobody edits in the browser.
 *
 * A `page` is written here and edited here: the internal wiki. Keeping both in
 * one table is deliberate, because the team looks for "the partner agreement"
 * without first deciding whether somebody uploaded it or typed it, and a folder
 * that shows only half its contents is the one people stop trusting.
 */
enum DocumentKind: string implements HasColor, HasIcon, HasLabel
{
    case File = 'file';
    case Page = 'page';

    public function getLabel(): string
    {
        return match ($this) {
            self::File => 'Uploaded file',
            self::Page => 'Written page',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::File => 'heroicon-o-paper-clip',
            self::Page => 'heroicon-o-document-text',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::File => 'gray',
            self::Page => 'primary',
        };
    }

    /** What the form says next to each choice, so the pick needs no guessing. */
    public function description(): string
    {
        return match ($this) {
            self::File => 'A PDF, image, logo or spreadsheet kept as it is.',
            self::Page => 'Text written and edited here — the internal wiki.',
        };
    }
}
