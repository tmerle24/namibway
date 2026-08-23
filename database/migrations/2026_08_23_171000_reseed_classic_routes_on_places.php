<?php

use Database\Seeders\RouteTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Rewrites the classic routes as sequences of places.
 *
 * Built from four published Namibia self-drive guides, which describe the same
 * anticlockwise loop with the same stops in the same order — the shape is not
 * a matter of taste, it is what the roads and the distances allow. The routes
 * we had named regions instead, and "visit Kunene" is not a route: a real
 * generated trip ran Windhoek → Otjiwarongo → the coast → Etosha → Khorixas
 * with three legs over eight hours.
 *
 * Three things this fixes beyond the names:
 *
 *  - **No leg over six hours.** Every consecutive pair below is a drive a
 *    person can do in a morning with a margin for a puncture, which is the
 *    rule the country actually imposes: gravel roads, no driving after dark,
 *    and help a long way off. Where two highlights are further apart than that
 *    there is a night in between (Keetmanshoop before Fish River Canyon,
 *    Solitaire before Swakopmund).
 *  - **Two nights where the point is to stay.** Sossusvlei, Swakopmund,
 *    Twyfelfontein and Etosha are not stopovers, and a one-night stand at
 *    Sossusvlei means arriving after the dunes close and leaving before they
 *    open.
 *  - **Etosha is crossed west to east**, which is how the park is driven and
 *    what puts a camp at each end to the traveller's advantage rather than
 *    doubling back.
 *
 * A stop still carries its region for the model's macro guidance; what is new
 * is that it also carries the place, so the day's location is decided by the
 * route rather than guessed from a region the size of Portugal.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The seeder is the one list — a migration carrying its own copy of
        // five routes is two lists that drift, and this one has to converge
        // with what a fresh database gets. RouteTemplateSeeder deletes and
        // rewrites a template's stops, so running it again is safe.
        (new RouteTemplateSeeder)->run();
    }

    public function down(): void
    {
        // The region-named stops this replaces were themselves written by the
        // seeder, so there is nothing here to restore that is not wrong in the
        // way this migration exists to fix.
    }
};
