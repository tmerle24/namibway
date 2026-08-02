<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ReleaseNotesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function writeSnapshot(): void
    {
        $path = storage_path('app/release/version.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'version' => '1.0.187',
            'build' => 187,
            'hash' => 'abc1234',
            'date' => now()->toDateString(),
            'time' => '14:23',
            'deployed_at' => now()->toIso8601String(),
            'commits' => [
                ['hash' => 'abc1234', 'date' => now()->toDateString(), 'subject' => 'Add release notes page'],
            ],
        ]));
    }

    public function test_admin_header_shows_version_badge_linking_to_release_notes(): void
    {
        $this->writeSnapshot();

        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertOk()
            ->assertSee('v1.0.187 ('.now()->toDateString().' 14:23)')
            ->assertSee('/admin/release-notes', escape: false);
    }

    public function test_release_notes_page_renders_the_snapshotted_log(): void
    {
        $this->writeSnapshot();

        $this->actingAs($this->admin())
            ->get('/admin/release-notes')
            ->assertOk()
            ->assertSee('Release Notes')
            ->assertSee('v1.0.187')
            ->assertSee('abc1234')
            ->assertSee('Add release notes page');
    }

    public function test_release_notes_page_handles_missing_snapshot_gracefully(): void
    {
        File::delete(storage_path('app/release/version.json'));

        $this->actingAs($this->admin())
            ->get('/admin/release-notes')
            ->assertOk()
            ->assertSee('No release snapshot yet');
    }
}
