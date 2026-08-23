<?php

namespace App\Http\Controllers;

use App\Models\SavedPlan;
use App\Services\Trip\PlanWaypoints;
use App\Services\Trip\TripCardImage;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * The image a shared trip link unfurls to — see App\Services\Trip\TripCardImage.
 *
 * Public and unauthenticated because the only client that matters is a
 * crawler: WhatsApp, iMessage and Slack fetch og:image with no cookies, so
 * anything behind a session would show the recipient a broken card. The token
 * is the entitlement, exactly as it is for the page itself, and the URL is
 * always minted from the read-only token so the picture can never carry write
 * access into a chat thread.
 */
class TripCardImageController extends Controller
{
    public function __invoke(string $token, TripCardImage $card, PlanWaypoints $waypoints): Response
    {
        $resolved = SavedPlan::resolveByAnyToken($token);

        abort_if($resolved === null, 404);

        [$saved] = $resolved;

        // Drawing the card costs the better part of a second, and an unfurler
        // fetches it once per person the link reaches — so it is drawn once
        // per version of the plan and served from memory after that. Keyed on
        // the version, so an edited plan redraws without anything having to
        // remember to clear this.
        $png = Cache::remember(
            "trip-card:{$saved->id}:{$saved->version}",
            now()->addDay(),
            fn (): string => $card->render($saved->plan_json, $saved->title, $waypoints->coordinates()),
        );

        // A plan is edited far more often than its card is fetched, and an
        // unfurler caches by URL — so the freshness that matters is the CDN's,
        // and an hour is short enough that a re-shared link redraws.
        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
