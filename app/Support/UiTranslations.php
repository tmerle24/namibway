<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

/**
 * Reads the traveller-facing UI strings from `resources/js/lang/*.json` on the
 * server.
 *
 * Those files are vue-i18n's, and everything traveller-facing has so far been
 * Vue, so nothing in PHP had needed them. The payment pages are the first
 * exception and a real one: a guest arrives at them from a link in an email or
 * from a gateway's redirect, which is a plain page load rather than a step
 * inside the application — an Inertia page would mean booting the whole
 * traveller app to say "your deposit is paid".
 *
 * So they are Blade, and they read the same JSON the Vue side does. That keeps
 * PAYMENTS_BUILD.md guardrail 9 honest — one home for traveller-facing
 * strings, English first — without a second set of translation files that will
 * drift.
 *
 * English is the fallback for every missing key, and the key itself is the
 * fallback for that. A missing string shows as `payments.paid` rather than as
 * an empty page, which is a bug report instead of a mystery.
 */
class UiTranslations
{
    /** @var array<string, array<string, mixed>> */
    private static array $loaded = [];

    public static function get(string $key, ?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        $value = Arr::get(self::load($locale), $key);

        if (! is_string($value)) {
            $value = Arr::get(self::load('en'), $key);
        }

        return is_string($value) ? $value : $key;
    }

    /**
     * @param  array<string, string|int|float>  $replace
     */
    public static function replace(string $key, array $replace, ?string $locale = null): string
    {
        $line = self::get($key, $locale);

        foreach ($replace as $search => $value) {
            $line = str_replace([':'.$search, '{'.$search.'}'], (string) $value, $line);
        }

        return $line;
    }

    /**
     * @return array<string, mixed>
     */
    private static function load(string $locale): array
    {
        if (isset(self::$loaded[$locale])) {
            return self::$loaded[$locale];
        }

        $path = resource_path('js/lang/'.$locale.'.json');

        if (! File::exists($path)) {
            return self::$loaded[$locale] = [];
        }

        $decoded = json_decode(File::get($path), true);

        return self::$loaded[$locale] = is_array($decoded) ? $decoded : [];
    }

    /** Tests and long-running workers need to be able to drop the cache. */
    public static function flush(): void
    {
        self::$loaded = [];
    }
}
