<?php

namespace App\Enums;

/**
 * What a guest category is, as far as pricing is concerned.
 *
 * Not the same question as the age range, which is the property's to set and
 * varies between them — one lodge's child is 3–11, another's is 2–15. This is
 * the part the calculation needs: whether somebody is an adult (a single
 * supplement is about adults travelling alone), a child (charged as a share of
 * the adult amount) or an infant (usually free and usually not counted when
 * working out how full a room is).
 */
enum GuestKind: string
{
    case Adult = 'adult';

    case Child = 'child';

    case Infant = 'infant';

    public function label(): string
    {
        return match ($this) {
            self::Adult => 'Adult',
            self::Child => 'Child',
            self::Infant => 'Infant',
        };
    }
}
