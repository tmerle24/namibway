<?php

namespace App\Services\Region;

class PhoneNumberFormatter
{
    /**
     * Turn a free-text phone number (however a partner typed it, or a
     * scraper found it) into a clean display string and a matching tel:
     * href. Returns null for blank input.
     *
     * @return array{display: string, href: string}|null
     */
    public function format(?string $raw): ?array
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        $international = $this->toInternational($raw);

        if ($international === null) {
            return null;
        }

        return [
            'display' => $this->display($international),
            'href' => 'tel:'.$international,
        ];
    }

    /**
     * Reduce raw input to a leading-"+" international digit string.
     *
     * Public because it is also what "the same number" means when one is
     * stored to be searched on — "081 234 5678" and "+264 81 234 5678" are one
     * number, and only this method knows that.
     */
    public function toInternational(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        // "(264) 64 20 90 46" -> "+264 64 20 90 46": some scraped sources
        // wrap the country code in parens instead of using a "+".
        $raw = preg_replace('/^\(\s*(\d{1,4})\s*\)/', '+$1', $raw);

        // "+264 (0)61 308104" -> "+264 61 308104": drop a redundant trunk
        // marker left behind inside an already-international number.
        $raw = preg_replace('/^(\+\d{1,4})\s*\(0\)\s*/', '$1', $raw);

        $digits = preg_replace('/[^\d+]/', '', $raw ?? '');

        if ($digits === null || $digits === '' || $digits === '+') {
            return null;
        }

        if (str_starts_with($digits, '+')) {
            return $digits;
        }

        $default = $this->defaultCountry();
        $trunkPrefix = $default['trunk_prefix'];

        if ($trunkPrefix !== '' && str_starts_with($digits, $trunkPrefix)) {
            $digits = substr($digits, strlen($trunkPrefix));
        }

        return $default['dial_code'].$digits;
    }

    /**
     * Group an international digit string for display. Uses the matching
     * configured country's dial code length when recognized, and falls back
     * to the plain "+digits" string otherwise — safer than guessing a
     * grouping for a country we don't have settings for yet.
     */
    private function display(string $international): string
    {
        foreach (config('region.countries', []) as $country) {
            $dialCode = $country['dial_code'];

            if (str_starts_with($international, $dialCode)) {
                $local = substr($international, strlen($dialCode));
                $head = substr($local, 0, 2);
                $tail = substr($local, 2);

                return trim($dialCode.' '.$head.' '.implode(' ', $this->groupDigits($tail)));
            }
        }

        return $international;
    }

    /**
     * Split a digit string into 3-digit groups, folding a lonely trailing
     * digit into the final group instead of leaving it on its own (e.g. a
     * 9-digit Namibian mobile number groups as "XXX XXXX", not "XXX X").
     *
     * @param  positive-int  $size
     * @return list<string>
     */
    private function groupDigits(string $digits, int $size = 3): array
    {
        if (strlen($digits) % $size === 1 && strlen($digits) > $size) {
            $groups = str_split(substr($digits, 0, -($size + 1)), $size);
            $groups[] = substr($digits, -($size + 1));

            return $groups;
        }

        return str_split($digits, $size);
    }

    /**
     * @return array{name: string, dial_code: string, trunk_prefix: string}
     */
    private function defaultCountry(): array
    {
        $code = config('region.default_country');

        return config("region.countries.{$code}");
    }
}
