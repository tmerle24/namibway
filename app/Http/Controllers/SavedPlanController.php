<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Destination;
use App\Models\SavedPlan;
use App\Services\Pdf\RouteMapImageService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SavedPlanController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'array'],
            'plan.trip_summary' => ['required', 'string', 'max:500'],
            'plan.variants' => ['required', 'array', 'min:1'],
            'plan.start_location' => ['sometimes', 'string', 'max:255'],
            'plan.end_location' => ['sometimes', 'string', 'max:255'],
            'plan.trip_params' => ['sometimes', 'array'],
        ]);

        $plan = $validated['plan'];
        $title = Str::limit($plan['trip_summary'], 80, '');

        $saved = SavedPlan::create([
            'title' => $title,
            'plan_json' => $plan,
            'user_id' => $request->user()?->id,
            'session_id' => $request->session()->getId(),
        ]);

        return response()->json([
            'token' => $saved->token,
            'url' => route('trip.show', $saved->token),
        ]);
    }

    public function show(string $token): Response
    {
        $resolved = SavedPlan::resolveByAnyToken($token);

        abort_if($resolved === null, 404);

        [$saved, $canEdit] = $resolved;

        return Inertia::render('TripPlan', [
            'plan' => $saved->plan_json,
            'title' => $saved->title,
            // Only ever the token this visitor actually arrived on: handing a
            // read-only viewer the edit token would defeat the whole split, and
            // the frontend uses this for its update calls.
            'token' => $canEdit ? $saved->token : $saved->share_token,
            'canEdit' => $canEdit,
            'shareToken' => $saved->share_token,
            // Seeds the conflict check for edits made from this page — see
            // KaiaController::updatePlan.
            'version' => $saved->version,
            // Always the read-only link, whichever way the viewer got here —
            // "share" should never pass on write access.
            'shareUrl' => route('trip.show', $saved->share_token),
        ]);
    }

    public function pdf(Request $request, string $token, RouteMapImageService $mapImages): HttpResponse
    {
        // Either token: downloading the PDF is a read operation, so a
        // read-only share link is entitled to it.
        $resolved = SavedPlan::resolveByAnyToken($token);

        abort_if($resolved === null, 404);

        [$saved] = $resolved;
        $plan = $saved->plan_json;

        $regionCoords = Destination::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['name', 'lat', 'lng'])
            ->mapWithKeys(fn (Destination $d) => [
                mb_strtolower($d->getTranslation('name', 'en')) => [
                    'lat' => $d->lat,
                    'lng' => $d->lng,
                ],
            ]);

        // A day's "location" is now always a city (see ItineraryService) — this is
        // what actually resolves most days' map markers/labels today. Never
        // overrides a destination-name key (checked above). Unlike
        // KaiaController::regionCoords(), not restricted to published-listing
        // cities: a saved PDF can be for a plan generated before a listing's city
        // was unpublished/reassigned, and the map should still place it.
        City::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['name', 'lat', 'lng'])
            ->each(function (City $city) use ($regionCoords) {
                $key = mb_strtolower($city->name);

                if (! $regionCoords->has($key)) {
                    $regionCoords[$key] = ['lat' => $city->lat, 'lng' => $city->lng];
                }
            });

        $regionCoords = $regionCoords->toArray();

        $routeMaps = [];

        foreach ($plan['variants'] ?? [] as $i => $variant) {
            $routeMaps[$i] = $mapImages->render($variant['days'] ?? [], $regionCoords);
        }

        // enable_font_subsetting: without it, adding the page-number text
        // after render() (see below) forces dompdf to embed full, unsubset
        // fonts (~1MB) instead of just the glyphs actually used (~100KB).
        $pdf = Pdf::setOptions(['enable_font_subsetting' => true])->loadView('pdf.trip-plan', [
            'plan' => $plan,
            'title' => $saved->title,
            'routeMaps' => $routeMaps,
            // The printed link is for passing around, so it's the read-only
            // one even when the PDF was downloaded from the edit link.
            'shareUrl' => route('trip.show', $saved->share_token),
            'logoDataUri' => $this->logoDataUri(),
            'dateRange' => $this->tripDateRange($plan),
        ]);

        // CSS paged-media page counters aren't reliably supported by dompdf,
        // and an inline <script type="text/php"> block runs mid-layout —
        // before pagination is finalized — so it only ever sees page 1.
        // page_text() has to be called after render() completes, directly
        // on the canvas, once every page actually exists.
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $fontMetrics = $pdf->getDomPDF()->getFontMetrics();
        $font = $fontMetrics->getFont('DejaVu Sans');
        $size = 8;
        $text = 'Page {PAGE_NUM} of {PAGE_COUNT}';

        // page_text() substitutes {PAGE_NUM}/{PAGE_COUNT} with the actual
        // digits at render time, but centering has to be computed *now* —
        // measuring the placeholder string itself (32 chars) instead of the
        // rendered text (e.g. "Page 2 of 4", 11 chars) is what threw the
        // centering off. Measure against the real page count instead — its
        // digit width is what {PAGE_COUNT} will actually render as, and
        // {PAGE_NUM} is never wider than it.
        $pageCount = $canvas->get_page_count();
        $sample = "Page {$pageCount} of {$pageCount}";
        $width = $fontMetrics->getTextWidth($sample, $font, $size);

        $canvas->page_text(($canvas->get_width() - $width) / 2, $canvas->get_height() - 26, $text, $font, $size, [0.63, 0.44, 0.35]);

        $filename = Str::slug($saved->title ?: 'namibway-trip').'.pdf';

        return $pdf->download($filename);
    }

    private function logoDataUri(): string
    {
        // The built asset filename is content-hashed by Vite, so read the
        // source image directly instead — stable regardless of build output.
        $contents = file_get_contents(resource_path('images/logo-dark.png'));

        return 'data:image/png;base64,'.base64_encode((string) $contents);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function tripDateRange(array $plan): ?string
    {
        $days = $plan['variants'][0]['days'] ?? [];

        if ($days === []) {
            return null;
        }

        $from = $days[0]['date'] ?? null;
        $last = $days[count($days) - 1];
        $to = $last['date_to'] ?? $last['date'] ?? null;

        return $from && $to ? "{$from} \u{2013} {$to}" : null;
    }
}
