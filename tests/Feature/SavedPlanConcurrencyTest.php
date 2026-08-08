<?php

namespace Tests\Feature;

use App\Models\SavedPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The plan document is overwritten wholesale on every autosave, so a stale
 * write has to be rejected rather than silently winning — see the comment on
 * KaiaController::updatePlan.
 */
class SavedPlanConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function plan(string $summary): array
    {
        return ['trip_summary' => $summary, 'variants' => []];
    }

    private function makePlan(): SavedPlan
    {
        return SavedPlan::create([
            'title' => 'Original',
            'plan_json' => $this->plan('Original'),
        ]);
    }

    public function test_a_new_plan_starts_at_version_one_and_load_returns_it(): void
    {
        $saved = $this->makePlan();

        $this->assertSame(1, $saved->version);

        $this->getJson(route('kaia.plans.load', $saved->token))
            ->assertOk()
            ->assertJsonPath('version', 1);
    }

    public function test_an_update_with_the_current_version_succeeds_and_bumps_it(): void
    {
        $saved = $this->makePlan();

        $this->patchJson(route('kaia.plans.update', $saved->token), [
            'variant' => $this->plan('Edited'),
            'version' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('version', 2);

        $saved->refresh();
        $this->assertSame(2, $saved->version);
        $this->assertSame('Edited', $saved->plan_json['trip_summary']);
    }

    public function test_a_stale_update_is_rejected_with_409_and_leaves_the_plan_untouched(): void
    {
        $saved = $this->makePlan();

        // Someone else saves first, moving the plan to version 2.
        $this->patchJson(route('kaia.plans.update', $saved->token), [
            'variant' => $this->plan('First writer'),
            'version' => 1,
        ])->assertOk();

        // A second editor still holding version 1 must not overwrite that.
        $this->patchJson(route('kaia.plans.update', $saved->token), [
            'variant' => $this->plan('Stale writer'),
            'version' => 1,
        ])
            ->assertStatus(409)
            ->assertJsonPath('version', 2)
            ->assertJsonPath('variant.trip_summary', 'First writer');

        $saved->refresh();
        $this->assertSame('First writer', $saved->plan_json['trip_summary']);
        $this->assertSame(2, $saved->version);
    }

    public function test_an_update_without_a_version_still_works_and_bumps_the_counter(): void
    {
        $saved = $this->makePlan();

        $this->patchJson(route('kaia.plans.update', $saved->token), [
            'variant' => $this->plan('Legacy client'),
        ])
            ->assertOk()
            ->assertJsonPath('version', 2);

        $saved->refresh();
        $this->assertSame('Legacy client', $saved->plan_json['trip_summary']);
        $this->assertSame(2, $saved->version);
    }

    public function test_a_versionless_write_makes_a_concurrent_versioned_client_conflict(): void
    {
        $saved = $this->makePlan();

        $this->patchJson(route('kaia.plans.update', $saved->token), [
            'variant' => $this->plan('Legacy client'),
        ])->assertOk();

        $this->patchJson(route('kaia.plans.update', $saved->token), [
            'variant' => $this->plan('Stale versioned client'),
            'version' => 1,
        ])->assertStatus(409);

        $this->assertSame('Legacy client', $saved->refresh()->plan_json['trip_summary']);
    }

    public function test_version_cannot_be_forced_through_mass_assignment(): void
    {
        $saved = SavedPlan::create([
            'title' => 'Original',
            'plan_json' => $this->plan('Original'),
            'version' => 99,
        ]);

        $this->assertSame(1, $saved->version);
    }

    public function test_a_plan_gets_a_share_token_distinct_from_its_edit_token(): void
    {
        $saved = $this->makePlan();

        $this->assertNotNull($saved->share_token);
        $this->assertNotSame($saved->token, $saved->share_token);
    }

    public function test_the_share_token_can_read_the_plan_but_is_marked_read_only(): void
    {
        $saved = $this->makePlan();

        $this->getJson(route('kaia.plans.load', $saved->token))
            ->assertOk()
            ->assertJsonPath('can_edit', true);

        $this->getJson(route('kaia.plans.load', $saved->share_token))
            ->assertOk()
            ->assertJsonPath('can_edit', false)
            ->assertJsonPath('variant.trip_summary', 'Original');
    }

    public function test_the_share_token_cannot_write(): void
    {
        $saved = $this->makePlan();

        $this->patchJson(route('kaia.plans.update', $saved->share_token), [
            'variant' => $this->plan('Read-only viewer'),
            'version' => 1,
        ])->assertNotFound();

        $saved->refresh();
        $this->assertSame('Original', $saved->plan_json['trip_summary']);
        $this->assertSame(1, $saved->version);
    }

    public function test_the_trip_page_renders_for_both_tokens_and_only_shares_the_read_only_one(): void
    {
        $saved = $this->makePlan();

        $this->get(route('trip.show', $saved->token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canEdit', true)
                ->where('token', $saved->token)
                // Even the creator's own page hands out the read-only link.
                ->where('shareUrl', route('trip.show', $saved->share_token))
            );

        $this->get(route('trip.show', $saved->share_token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canEdit', false)
                // A read-only visitor must never be handed the edit token.
                ->where('token', $saved->share_token)
            );
    }
}
