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
}
