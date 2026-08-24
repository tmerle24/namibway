<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A `days` entry in a trip plan is a NIGHT — the plan counts a stage's nights
 * by counting its days, and the checkout morning rides on the last night as
 * departure_activities/departure_restaurants. Kaia was nevertheless asked for
 * one entry per calendar day, departure day included, so every plan generated
 * before this ran carries one night too many: a trip running 1-18 January 2027
 * shows 18 nights and a last stage dated "17-19 Jan", checking out on a day the
 * traveler is already home.
 *
 * ItineraryService::foldReturnDay() fixes that for new plans. This does the
 * same to the ones already saved, rather than leaving every existing link
 * showing a day the traveler never booked. It is the identical fold: the
 * trailing entry is not deleted but merged into the night before it, whose
 * `date_to` is already that entry's date, so anything planned on the departure
 * morning stays exactly where the plan renders it.
 *
 * Only a plan that is exactly one entry longer than its own night count is
 * touched — that is the shape Kaia produced. A plan the traveler has since
 * added nights to has drifted further than that and is left alone, because at
 * that point nothing here can tell a phantom night from one somebody chose.
 * Running before any traveler can have edited a post-fix plan is what makes
 * that guard trustworthy; this is a one-off repair, not a rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('saved_plans')->orderBy('id')->chunk(100, function (Collection $plans): void {
            foreach ($plans as $saved) {
                $plan = json_decode((string) $saved->plan_json, true);

                if (! is_array($plan)) {
                    continue;
                }

                $folded = $this->fold($plan);

                if ($folded === null) {
                    continue;
                }

                // The stored plan really did change under anyone who has it
                // open, and `version` is exactly how that is announced: their
                // next autosave gets a 409 and the conflict banner, instead of
                // quietly writing the phantom night back.
                DB::table('saved_plans')
                    ->where('id', $saved->id)
                    ->update([
                        'plan_json' => json_encode($folded),
                        'version' => (int) $saved->version + 1,
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>|null  null when nothing needed folding
     */
    private function fold(array $plan): ?array
    {
        $params = $plan['trip_params'] ?? null;
        $variants = $plan['variants'] ?? null;
        $nights = is_array($params) ? ($params['nights'] ?? null) : null;

        if (! is_numeric($nights) || (int) $nights < 1 || ! is_array($variants)) {
            return null;
        }

        $nights = (int) $nights;
        $changed = false;

        foreach ($variants as $i => $variant) {
            $days = is_array($variant) ? ($variant['days'] ?? null) : null;

            if (! is_array($days) || count($days) !== $nights + 1) {
                continue;
            }

            $days = array_values(array_filter($days, 'is_array'));
            $returnDay = array_pop($days);

            if ($returnDay === null || $days === []) {
                continue;
            }

            $last = count($days) - 1;

            $days[$last]['departure_activities'] = [
                ...$this->entryList($days[$last], 'departure_activities'),
                ...$this->entries($returnDay, 'activity'),
            ];
            $days[$last]['departure_restaurants'] = [
                ...$this->entryList($days[$last], 'departure_restaurants'),
                ...$this->entries($returnDay, 'restaurant'),
            ];

            $plan['variants'][$i]['days'] = $days;
            $changed = true;
        }

        return $changed ? $plan : null;
    }

    /**
     * Older plans carry a day's single `activity`/`restaurant`; ones that have
     * been through the trip-plan UI carry the plural arrays. Read both, so the
     * fold never drops what was planned on the departure morning.
     *
     * @param  array<string, mixed>  $day
     * @return array<int, array<string, mixed>>
     */
    private function entries(array $day, string $field): array
    {
        $plural = $this->entryList($day, $field.'s');

        if ($plural !== []) {
            return $plural;
        }

        $single = $day[$field] ?? null;

        return is_array($single) ? [$single] : [];
    }

    /**
     * @param  array<string, mixed>  $day
     * @return array<int, array<string, mixed>>
     */
    private function entryList(array $day, string $key): array
    {
        $value = $day[$key] ?? null;

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
};
