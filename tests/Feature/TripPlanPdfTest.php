<?php

namespace Tests\Feature;

use App\Models\SavedPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The exported plan is the copy that leaves the platform — printed, mailed on,
 * handed to a fellow traveller. Two things about it are easy to break without
 * noticing, because neither shows up until somebody downloads one: the render
 * itself, and the Kaia wordmark in the footer, which is a file on disk rather
 * than markup (dompdf cannot draw the outlined SVG — see BRAND.md).
 */
class TripPlanPdfTest extends TestCase
{
    use RefreshDatabase;

    private function makePlan(): SavedPlan
    {
        return SavedPlan::create([
            'title' => 'Six days, ending at Sossusvlei',
            'plan_json' => [
                'trip_summary' => 'Six days, ending at Sossusvlei',
                'variants' => [[
                    'name' => 'Classic',
                    'days' => [
                        ['day' => 1, 'location' => 'Windhoek', 'date' => '25 Aug 2026', 'date_to' => '26 Aug 2026'],
                        ['day' => 2, 'location' => 'Sesriem', 'date' => '26 Aug 2026', 'date_to' => '27 Aug 2026'],
                    ],
                ]],
            ],
        ]);
    }

    public function test_a_plan_downloads_as_a_pdf(): void
    {
        $saved = $this->makePlan();

        $response = $this->get(route('trip.pdf', $saved->share_token))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_the_wordmark_the_footer_prints_is_actually_on_disk(): void
    {
        // The PDF renders with or without it — dompdf simply drops a missing
        // image — so the plan would quietly start going out unsigned.
        $path = resource_path('images/kaia-wordmark.png');

        $this->assertFileExists($path);
        $this->assertStringStartsWith("\x89PNG", (string) file_get_contents($path));
    }
}
