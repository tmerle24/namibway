<?php

namespace App\Services\Pricing;

use App\Enums\BoardBasis;
use App\Enums\PricingStrategy;
use App\Enums\RatePlanEligibility;
use App\Models\Listing;
use App\Models\RatePlan;

/**
 * Ready-made rate plans for the three ways properties here actually sell.
 *
 * "Profiles, not configuration" — see BOOKING_SYSTEM.md. A lodge setting up
 * should recognise its own business in a sentence and press one button, not
 * work out what a pricing strategy is before it can enter a price. Everything
 * created here is editable afterwards, and nothing is created until somebody
 * asks.
 *
 * The three are not an opinion about what a property *should* sell. They are
 * what a Namibian rate sheet looks like: a guesthouse quotes a room, a lodge
 * quotes per person sharing with dinner, and anything selling to both markets
 * quotes residents and international guests side by side.
 *
 * A code that already exists is left alone rather than updated. Pressing the
 * button twice is therefore harmless, and a plan somebody has edited is never
 * quietly reset to the profile's idea of it.
 */
final class RatePlanProfiles
{
    /**
     * @return array<string, array{name: string, description: string, plans: array<int, array<string, mixed>>}>
     */
    public static function all(): array
    {
        return [
            'per_room' => [
                'name' => 'Guesthouse — one price per room',
                'description' => 'A single rate including breakfast, whoever is in the room. Self-catering units, guest farms and campsites work this way too.',
                'plans' => [
                    [
                        'name' => 'Standard rate',
                        'code' => 'STD',
                        'board_basis' => BoardBasis::BedAndBreakfast,
                        'pricing_strategy' => PricingStrategy::PerUnit,
                        'eligibility' => RatePlanEligibility::Public,
                        'is_default' => true,
                    ],
                ],
            ],

            'per_person' => [
                'name' => 'Lodge — per person sharing',
                'description' => 'Dinner, bed and breakfast as the main rate, with a bed-and-breakfast rate beside it. How most lodges and safari camps quote.',
                'plans' => [
                    [
                        'name' => 'Dinner, bed and breakfast',
                        'code' => 'DBB',
                        'board_basis' => BoardBasis::HalfBoard,
                        'pricing_strategy' => PricingStrategy::PerPersonSharing,
                        'eligibility' => RatePlanEligibility::Public,
                        'is_default' => true,
                        'sort' => 10,
                    ],
                    [
                        'name' => 'Bed and breakfast',
                        'code' => 'BB',
                        'board_basis' => BoardBasis::BedAndBreakfast,
                        'pricing_strategy' => PricingStrategy::PerPersonSharing,
                        'eligibility' => RatePlanEligibility::Public,
                        'sort' => 20,
                    ],
                ],
            ],

            'resident' => [
                'name' => 'Resident and international rates',
                'description' => 'Namibian resident, SADC and international prices side by side, all dinner, bed and breakfast. What state-run camps and most lodges selling into both markets publish.',
                'plans' => [
                    [
                        'name' => 'International',
                        'code' => 'INT',
                        'board_basis' => BoardBasis::HalfBoard,
                        'pricing_strategy' => PricingStrategy::PerPersonSharing,
                        'eligibility' => RatePlanEligibility::Public,
                        'is_default' => true,
                        'sort' => 10,
                    ],
                    [
                        'name' => 'Namibian resident',
                        'code' => 'RES',
                        'board_basis' => BoardBasis::HalfBoard,
                        'pricing_strategy' => PricingStrategy::PerPersonSharing,
                        'eligibility' => RatePlanEligibility::Resident,
                        'sort' => 20,
                    ],
                    [
                        'name' => 'SADC',
                        'code' => 'SADC',
                        'board_basis' => BoardBasis::HalfBoard,
                        'pricing_strategy' => PricingStrategy::PerPersonSharing,
                        'eligibility' => RatePlanEligibility::Sadc,
                        'sort' => 30,
                    ],
                ],
            ],
        ];
    }

    /**
     * Create the plans of one profile that the property does not have yet.
     *
     * @return int how many were created
     */
    public static function apply(Listing $listing, string $profile): int
    {
        $definition = self::all()[$profile] ?? null;

        if ($definition === null) {
            return 0;
        }

        $existing = [];

        foreach (RatePlan::query()->where('listing_id', $listing->id)->get() as $plan) {
            $existing[] = strtoupper($plan->code);
        }

        // A property that already sells something keeps its default. Handing a
        // second product the default flag would change what an existing
        // booking form offers first, which is not what "add" means.
        $hasPlans = $existing !== [];
        $created = 0;

        foreach ($definition['plans'] as $plan) {
            if (in_array(strtoupper((string) $plan['code']), $existing, true)) {
                continue;
            }

            RatePlan::create([
                'listing_id' => $listing->id,
                'is_active' => true,
                ...$plan,
                'is_default' => ! $hasPlans && (bool) ($plan['is_default'] ?? false),
            ]);

            $created++;
        }

        return $created;
    }
}
