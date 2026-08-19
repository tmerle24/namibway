<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The area a place belongs to — what a traveler names when they say where they
 * want to go, and what they are shown instead of the political region.
 *
 * A place already carries a region, and that region is the wrong thing to put
 * in front of a traveler: "Onguma Nature Reserve (Oshikoto)" tells them
 * nothing, while "Onguma Nature Reserve (Etosha)" tells them the whole story.
 * The two answer different questions and both are needed — the region carries
 * the country and is what an administration works in (see CountrySettings),
 * the area is what a trip is planned in — so this is a second, nullable link
 * beside the first rather than a replacement for it.
 *
 * The areas are the existing `destinations` rows: Etosha, Sossusvlei,
 * Swakopmund, Fish River Canyon are already there with a name, blurb and
 * photo, and are already what the homepage cards offer. Grouping places under
 * them makes the card mean what it says — the Etosha card filtered on the
 * political region until now, which showed Skeleton Coast and left Onguma out.
 *
 * Nullable on purpose: Windhoek is simply Windhoek, and a settlement in the
 * far north is in no tourism area at all. Then the place is shown alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->foreignId('destination_id')->nullable()->after('region_id')->constrained()->nullOnDelete();
        });

        $destinations = DB::table('destinations')->pluck('id', 'slug');

        foreach (self::areas() as $destinationSlug => $placeSlugs) {
            if (! isset($destinations[$destinationSlug])) {
                continue;
            }

            // Only fills blanks — an area somebody has already set by hand in
            // /admin is a decision, not something for a migration to redo.
            DB::table('cities')
                ->whereIn('slug', $placeSlugs)
                ->whereNull('destination_id')
                ->update(['destination_id' => $destinations[$destinationSlug]]);
        }
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('destination_id');
        });
    }

    /**
     * Which places sit in which area, keyed by the destination's slug.
     *
     * Deliberately only the ones that are not in doubt. Outjo is a town in its
     * own right that happens to be the gateway to Etosha South; whether it
     * counts as "Etosha" is a content decision, and a migration guessing at it
     * would be putting words in the content team's mouth.
     *
     * @return array<string, array<int, string>>
     */
    private static function areas(): array
    {
        return [
            'etosha' => ['etosha-national-park', 'onguma-nature-reserve', 'ongava-game-reserve'],
            'sossusvlei' => ['sossusvlei', 'sesriem', 'solitaire', 'namibrand-nature-reserve'],
            'twyfelfontein' => ['twyfelfontein'],
            'skeleton-coast' => ['skeleton-coast-national-park', 'cape-cross'],
            'spitzkoppe' => ['spitzkoppe'],
            'sandwich-harbour' => ['sandwich-harbour'],
            'fish-river-canyon' => ['fish-river-canyon', 'ai-ais'],
            'kolmanskop' => ['kolmanskop'],
            'waterberg' => ['waterberg-plateau-park'],
            'windhoek' => ['windhoek'],
            'swakopmund' => ['swakopmund'],
            'luderitzbucht' => ['luderitz'],
        ];
    }
};
