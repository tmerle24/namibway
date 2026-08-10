<?php

namespace Tests\Feature\Kaia;

use App\Services\Kaia\ItineraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The vehicle class and the daily budget are the two trip params the picker
 * added, and both are optional — every plan made before it existed has
 * neither, and the picker itself only fills them in when the traveler
 * bothers. What must hold is that an absent value plans exactly as it did
 * before, and that a present class is never contradicted by the legacy
 * "car"|"camper" binary it supersedes.
 */
class VehicleTripParamsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $tripParams
     * @return array<string, mixed>
     */
    private function extract(array $tripParams): array
    {
        $method = new ReflectionMethod(ItineraryService::class, 'extractTripParams');

        /** @var array<string, mixed> $result */
        $result = $method->invoke(app(ItineraryService::class), $tripParams);

        return $result;
    }

    public function test_a_class_derives_the_legacy_binary_it_supersedes(): void
    {
        $params = $this->extract([
            'vehicle_type' => 'car',
            'vehicle_class' => 'motorhome',
        ]);

        // The traveler picked a motorhome, so "car" — whatever the interview
        // established earlier — must not survive next to it.
        $this->assertSame('camper', $params['vehicle_type']);
        $this->assertSame('motorhome', $params['vehicle_class']);
    }

    public function test_a_non_camper_class_derives_car(): void
    {
        $params = $this->extract([
            'vehicle_type' => 'camper',
            'vehicle_class' => 'suv',
        ]);

        $this->assertSame('car', $params['vehicle_type']);
        $this->assertSame('suv', $params['vehicle_class']);
    }

    public function test_without_a_class_the_binary_is_left_exactly_as_it_was(): void
    {
        $params = $this->extract(['vehicle_type' => 'camper']);

        $this->assertSame('camper', $params['vehicle_type']);
        $this->assertNull($params['vehicle_class']);
        $this->assertNull($params['vehicle_daily_budget']);
    }

    public function test_an_unrecognised_class_is_ignored_rather_than_stored(): void
    {
        $params = $this->extract([
            'vehicle_type' => 'car',
            'vehicle_class' => 'hovercraft',
        ]);

        $this->assertNull($params['vehicle_class']);
        $this->assertSame('car', $params['vehicle_type']);
    }

    public function test_the_daily_budget_takes_positive_numbers_only(): void
    {
        $this->assertSame(1200, $this->extract(['vehicle_daily_budget' => '1200'])['vehicle_daily_budget']);
        $this->assertNull($this->extract(['vehicle_daily_budget' => 0])['vehicle_daily_budget']);
        $this->assertNull($this->extract(['vehicle_daily_budget' => -50])['vehicle_daily_budget']);
        $this->assertNull($this->extract(['vehicle_daily_budget' => 'lots'])['vehicle_daily_budget']);
    }

    public function test_regenerate_rejects_an_unknown_vehicle_class(): void
    {
        $this->postJson('/kaia/regenerate', [
            ...$this->validRegeneratePayload(),
            'vehicle_class' => 'hovercraft',
        ])->assertJsonValidationErrorFor('vehicle_class');
    }

    public function test_regenerate_rejects_a_non_numeric_daily_budget(): void
    {
        $this->postJson('/kaia/regenerate', [
            ...$this->validRegeneratePayload(),
            'vehicle_daily_budget' => 'as much as it takes',
        ])->assertJsonValidationErrorFor('vehicle_daily_budget');
    }

    public function test_regenerate_passes_the_vehicle_pick_through_to_generation(): void
    {
        $captured = $this->captureGeneratedParams();

        $this->postJson('/kaia/regenerate', [
            ...$this->validRegeneratePayload(),
            'vehicle_class' => 'camper_4x4',
            'vehicle_daily_budget' => 1500,
        ])->assertOk();

        $this->assertSame('camper_4x4', $captured->params['vehicle_class'] ?? null);
        $this->assertSame(1500, $captured->params['vehicle_daily_budget'] ?? null);
    }

    public function test_regenerate_still_works_for_a_plan_that_predates_the_picker(): void
    {
        // Neither field is required: the picker is a refinement, and the "edit
        // trip details" popup has to keep working on a plan whose params never
        // had these keys.
        $captured = $this->captureGeneratedParams();

        $this->postJson('/kaia/regenerate', $this->validRegeneratePayload())
            ->assertOk();

        $this->assertArrayNotHasKey('vehicle_class', $captured->params);
        $this->assertArrayNotHasKey('vehicle_daily_budget', $captured->params);
    }

    /**
     * Stands in for the real generation call, which would go out to the Claude
     * API, and hands back the params the controller actually forwarded.
     */
    private function captureGeneratedParams(): object
    {
        $captured = new class
        {
            /** @var array<string, mixed> */
            public array $params = [];
        };

        $this->mock(ItineraryService::class, function (MockInterface $mock) use ($captured) {
            $mock->shouldReceive('generate')
                ->once()
                ->andReturnUsing(function (array $params) use ($captured): array {
                    $captured->params = $params;

                    return ['trip_summary' => 'A test plan.', 'variants' => []];
                });
        });

        return $captured;
    }

    /** @return array<string, mixed> */
    private function validRegeneratePayload(): array
    {
        return [
            'nights' => 7,
            'travel_period' => '14 August 2026',
            'interests' => 'wildlife',
            'budget_tier' => 'mid-range',
            'adults' => 2,
            'children_under_13' => 0,
            'vehicle_type' => 'car',
        ];
    }
}
