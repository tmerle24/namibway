<?php

namespace App\Enums;

/**
 * What kind of thing is being added to a price.
 *
 * Purely a label and a grouping — nothing calculates differently because of
 * it. It exists because an invoice line reading "Levy" and one reading "Tax"
 * mean different things to a guest and to an accountant, and because a lodge
 * reconciling its NTB return needs to find the levy without reading every
 * charge's name.
 */
enum ChargeKind: string
{
    /** VAT, and anything else a revenue authority collects on the sale. */
    case Tax = 'tax';

    /** The tourism levy, a bed levy — collected for somebody else. */
    case Levy = 'levy';

    /** A conservancy fee, a park permit, a resort fee: the property's own. */
    case Fee = 'fee';

    public function label(): string
    {
        return match ($this) {
            self::Tax => 'Tax',
            self::Levy => 'Levy',
            self::Fee => 'Fee',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Tax => 'Collected for the revenue authority — VAT.',
            self::Levy => 'Collected for somebody else — the NTB tourism levy, a bed levy.',
            self::Fee => 'The property’s own — a conservancy fee, a park permit, a community levy.',
        };
    }
}
