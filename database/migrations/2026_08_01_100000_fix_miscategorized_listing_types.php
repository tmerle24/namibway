<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-off data-quality fix for listings whose `type` was wrongly assigned by the
 * scrape/import pipeline (see app/Console/Commands/ImportProviders.php resolveType(),
 * which silently defaulted unmapped source categories to "accommodation", e.g.
 * "ZGM Car Rentals" ended up filed under Accommodation instead of Vehicle).
 *
 * Reclassification is keyword-driven against the listing name (English translation)
 * rather than ID-based, so it works against any environment's data, not just the
 * snapshot this was authored against. Not reversible — there is no reliable way to
 * recover each row's prior (also wrong) type.
 */
return new class extends Migration
{
    private const VEHICLE_KEYWORDS = [
        'car rental', 'car rentals', 'car hire', 'car-hire',
        'vehicle hire', 'vehicle rental', 'vehicle lease',
        '4x4 hire', '4x4 rental', 'campervan hire', 'camper hire',
        'camper rental', 'equipment rental', 'equipment rentals', 'equipment hire',
        'rent a car',
    ];

    private const ACTIVITY_KEYWORDS = [
        'tours and safaris', 'tours & safaris', 'transfers & tours', 'transfers and tours',
        'safari', 'safaris',
    ];

    /** Names containing these are genuine accommodation businesses even if they also match an activity/vehicle keyword (e.g. "Etosha Safari Lodge"). */
    private const ACCOMMODATION_OVERRIDE_KEYWORDS = [
        'lodge', 'camp', 'guest house', 'guesthouse', 'hotel', 'resort', 'farm',
        'backpackers', 'cottage', 'chalet', 'b&b', 'bed and breakfast',
    ];

    /** Not a travel business at all — no correct ListingType exists, so unpublish rather than force a wrong category. */
    private const NON_TRAVEL_KEYWORDS = [
        'engineering', 'marine services', 'shipwrights', 'mart', 'maintenance',
        'service centre', 'service center',
    ];

    public function up(): void
    {
        $rows = DB::table('listings')
            ->whereIn('type', ['accommodation', 'activity', 'restaurant'])
            ->select('id', 'type', DB::raw("name->>'en' as name_en"))
            ->get();

        foreach ($rows as $row) {
            $name = (string) $row->name_en;
            $hasAccommodationOverride = $this->matchWord($name, self::ACCOMMODATION_OVERRIDE_KEYWORDS) !== null;

            if (! $hasAccommodationOverride
                && $row->type !== 'vehicle'
                && $this->matchWord($name, self::VEHICLE_KEYWORDS) !== null) {
                DB::table('listings')->where('id', $row->id)->update(['type' => 'vehicle']);

                continue;
            }

            if ($row->type !== 'accommodation') {
                continue;
            }

            if (! $hasAccommodationOverride && $this->matchWord($name, self::ACTIVITY_KEYWORDS) !== null) {
                DB::table('listings')->where('id', $row->id)->update(['type' => 'activity']);

                continue;
            }

            if (! $hasAccommodationOverride
                && $this->matchWord($name, self::NON_TRAVEL_KEYWORDS) !== null
                && $this->matchWord($name, self::VEHICLE_KEYWORDS) === null) {
                DB::table('listings')->where('id', $row->id)->update(['is_published' => false]);
            }
        }
    }

    public function down(): void
    {
        // Not reversible: original (also incorrect) type per row isn't recorded anywhere.
    }

    /** @param  array<int, string>  $keywords */
    private function matchWord(string $name, array $keywords): ?string
    {
        foreach ($keywords as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $name) === 1) {
                return $keyword;
            }
        }

        return null;
    }
};
