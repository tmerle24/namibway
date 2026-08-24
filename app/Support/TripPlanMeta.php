<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The link preview a shared trip plan gets in WhatsApp, iMessage, Slack and
 * every other unfurler.
 *
 * A `/trip/{token}` link only ever exists to be passed to somebody, so the
 * card it produces has to say what it is: whoever receives it sees the link
 * before they see the page. The generic site card ("The smartest way to
 * experience Namibia") told the recipient nothing about the trip they were
 * being shown, so this builds the card out of the plan itself — how long it
 * is, when it runs, and where it goes.
 *
 * Everything here is derived defensively: `plan_json` is whatever the model
 * produced when the plan was saved, so every field is optional and a missing
 * one only shortens the card.
 */
class TripPlanMeta
{
    /** Stops listed in full before the route is abbreviated with an ellipsis. */
    private const MAX_ROUTE_STOPS = 5;

    /**
     * @param  array<string, mixed>  $plan
     * @return array{title: string, description: string, image: string|null, alt: string}
     */
    public static function for(array $plan, ?string $title, ?string $image = null): array
    {
        return [
            'title' => self::title($title),
            'description' => self::description($plan),
            // Null falls the root template back to the site card. See
            // App\Services\Trip\TripCardImage for what this points at.
            'image' => $image,
            'alt' => self::alt($plan),
        ];
    }

    /**
     * What the picture shows, for a reader who can't see it.
     *
     * @param  array<string, mixed>  $plan
     */
    private static function alt(array $plan): string
    {
        $route = self::route(self::days($plan));

        return $route === null
            ? 'A map of a trip through Namibia'
            : "A map of the route {$route}";
    }

    private static function title(?string $title): string
    {
        $title = trim((string) $title);

        return $title === ''
            ? 'Namibia trip plan'
            : 'Trip plan: '.Str::limit($title, 80);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private static function description(array $plan): string
    {
        $days = self::days($plan);

        // The one sentence the recipient needs before they tap: this is a plan
        // somebody made and sent them, not a marketing page.
        $parts = ['Shared with you on NamibWay'];

        if (($length = self::length($plan)) !== null) {
            $parts[] = $length;
        }

        if (($route = self::route($days)) !== null) {
            $parts[] = $route;
        }

        return implode(' · ', $parts).'. Open the link for the full day-by-day itinerary.';
    }

    /**
     * The first variant is the one the page opens on, so it is the one the
     * card describes.
     *
     * @param  array<string, mixed>  $plan
     * @return array<int, array<string, mixed>>
     */
    private static function days(array $plan): array
    {
        $days = $plan['variants'][0]['days'] ?? [];

        return is_array($days) ? array_values(array_filter($days, 'is_array')) : [];
    }

    /**
     * How long the trip runs — "12 nights, 25 Aug \u{2013} 6 Sep 2026". Public
     * because the share card draws the same line under the plan's name, and
     * a card and its description disagreeing about the dates would be worse
     * than either being absent.
     *
     * Nights, not days: a `days` entry is a night (see
     * ItineraryService::foldReturnDay), so counting them and calling the answer
     * days made the card contradict its own date range — twelve nights run
     * across thirteen dates, and the plan itself has always said "12 nights"
     * beside the same period.
     *
     * @param  array<string, mixed>  $plan
     */
    public static function length(array $plan): ?string
    {
        $days = self::days($plan);

        if ($days === []) {
            return null;
        }

        $count = count($days);
        $length = $count.' '.Str::plural('night', $count);

        $from = self::string($days[0]['date'] ?? null);
        $last = $days[$count - 1];
        $to = self::string($last['date_to'] ?? null) ?? self::string($last['date'] ?? null);

        return $from !== null && $to !== null
            ? "{$length}, ".self::range($from, $to)
            : $length;
    }

    /**
     * "25 Aug – 5 Sep 2026", not "25 Aug 2026 – 5 Sep 2026". The dates are the
     * model's own 'j M Y' strings, so the year is only dropped when both ends
     * literally carry the same one — anything unparseable is left alone.
     */
    private static function range(string $from, string $to): string
    {
        if (preg_match('/ (\d{4})$/', $to, $year) === 1 && str_ends_with($from, $year[0])) {
            $from = trim(substr($from, 0, -strlen($year[0])));
        }

        return "{$from} \u{2013} {$to}";
    }

    /**
     * Consecutive days at the same place are one stop on the route — a plan
     * with three nights in Etosha is not "Etosha → Etosha → Etosha".
     *
     * @param  array<int, array<string, mixed>>  $days
     */
    private static function route(array $days): ?string
    {
        $stops = [];

        foreach ($days as $day) {
            $location = self::string($day['location'] ?? null);

            if ($location !== null && $location !== end($stops)) {
                $stops[] = $location;
            }
        }

        if ($stops === []) {
            return null;
        }

        if (count($stops) > self::MAX_ROUTE_STOPS) {
            $stops = array_slice($stops, 0, self::MAX_ROUTE_STOPS - 1);
            $stops[] = '…';
        }

        return implode(" \u{2192} ", $stops);
    }

    private static function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
