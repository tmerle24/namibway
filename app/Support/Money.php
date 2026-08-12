<?php

namespace App\Support;

/**
 * Renders an amount in the currency it is actually in.
 *
 * Only the symbol table in config/currencies.php is read here — never its
 * default, and never its conversion rates. Those exist to show a traveller a
 * price in their own money; a lodge-facing screen shows what the property
 * charges, in the currency the row already carries (see CountrySettings).
 *
 * An unknown code falls back to the code itself, which is correct rather than
 * pretty: "USD 1,850" tells a lodge manager more than a guessed symbol would.
 */
class Money
{
    public static function symbol(string $currency): string
    {
        /** @var array<string, string> $symbols */
        $symbols = config('currencies.symbols', []);

        return $symbols[strtoupper($currency)] ?? strtoupper($currency);
    }

    public static function format(float $amount, string $currency, int $decimals = 2): string
    {
        return self::symbol($currency).' '.number_format($amount, $decimals);
    }

    /**
     * For a grid cell, where the decimals are always ".00" and the column is
     * forty pixels wide.
     */
    public static function compact(float $amount, string $currency): string
    {
        return self::symbol($currency).' '.number_format($amount, 0);
    }

    /**
     * An amount as a whole number of cents.
     *
     * Every decision about money — is this stay paid, does this reversal net
     * to zero, does the folio balance — is made on these integers and never on
     * the floats. The columns are decimal(12,2) and the house convention casts
     * them to float in PHP, which is fine for carrying a value around and
     * wrong for comparing two of them: a stay that shows as unpaid because of
     * a 0.00000001 difference is the kind of bug a guest finds at check-out.
     *
     * round() before the cast, not after: (int) 4.999999 is 4.
     */
    public static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /** Whether two amounts are the same money. */
    public static function equals(float $a, float $b): bool
    {
        return self::cents($a) === self::cents($b);
    }

    /** The amount a whole number of cents represents. */
    public static function fromCents(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
