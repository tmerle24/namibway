<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What kind of thing an attraction is.
 *
 * Deliberately coarse. This exists so a traveler asking "what is there to see
 * around here?" can be answered by category and so a day gets some variety —
 * not to classify Namibia. A vocabulary somebody has to think about before
 * filing a rock-art site is a vocabulary that gets filed wrongly, and the
 * expensive part of this whole layer is the content, not the taxonomy.
 *
 * The overlaps are real and intentional: Kolmanskop is History even though the
 * desert took it back, and the Petrified Forest is Palaeontology rather than
 * Geology. Where two fit, the one a traveler would search for wins.
 */
enum AttractionType: string implements HasColor, HasLabel
{
    case NaturalFeature = 'natural_feature';
    case Geology = 'geology';
    case Wildlife = 'wildlife';
    case RockArt = 'rock_art';
    case Palaeontology = 'palaeontology';
    case History = 'history';
    case Culture = 'culture';
    case Museum = 'museum';
    case Viewpoint = 'viewpoint';

    /**
     * A marker or a photo stop that is none of the above — a park gate, the
     * Tropic of Capricorn sign. Small, and people stop anyway.
     *
     * Shares a word with PlaceType::Landmark and means something else: that
     * one is an area a lodge can stand in, this one is a thing you photograph.
     */
    case Landmark = 'landmark';

    public function getLabel(): string
    {
        return match ($this) {
            self::NaturalFeature => 'Natural feature',
            self::Geology => 'Geology',
            self::Wildlife => 'Wildlife',
            self::RockArt => 'Rock art',
            self::Palaeontology => 'Palaeontology',
            self::History => 'History',
            self::Culture => 'Culture & heritage',
            self::Museum => 'Museum',
            self::Viewpoint => 'Viewpoint',
            self::Landmark => 'Landmark',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NaturalFeature, self::Viewpoint => 'success',
            self::Landmark => 'gray',
            self::Geology, self::Palaeontology => 'warning',
            self::Wildlife => 'primary',
            self::RockArt, self::Culture => 'danger',
            self::History, self::Museum => 'info',
        };
    }

    /**
     * The stored value of every case, for validation and column checks.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
