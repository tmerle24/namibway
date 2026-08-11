<?php

namespace Tests\Feature\Commands;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The Release Notes page promises the last 30 days. A busy day holds a dozen
 * deploys, so the snapshot has to trim by age rather than by count — with a
 * floor, so a quiet month doesn't leave the page nearly empty.
 */
class ReleaseSnapshotHistoryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('app/release/version.json');
        File::ensureDirectoryExists(dirname($this->path));
    }

    protected function tearDown(): void
    {
        File::delete($this->path);

        parent::tearDown();
    }

    /** @param array<int, int> $ageInDays */
    private function writeHistory(array $ageInDays): void
    {
        $releases = array_map(fn (int $days, int $index): array => [
            'version' => '1.0.'.(1000 - $index),
            'build' => 1000 - $index,
            'hash' => 'abc'.$index,
            'date' => now()->subDays($days)->toDateString(),
            'time' => '12:00',
            'deployed_at' => now()->subDays($days)->toIso8601String(),
            'commits' => [],
        ], $ageInDays, array_keys($ageInDays));

        File::put($this->path, (string) json_encode([
            'version' => $releases[0]['version'],
            'build' => $releases[0]['build'],
            'hash' => 'previous-head',
            'date' => $releases[0]['date'],
            'time' => '12:00',
            'deployed_at' => $releases[0]['deployed_at'],
            'commits' => [],
            'releases' => $releases,
        ]));
    }

    /** @return array<int, array<string, mixed>> */
    private function releases(): array
    {
        return json_decode(File::get($this->path), associative: true)['releases'];
    }

    public function test_releases_older_than_thirty_days_are_dropped_once_the_floor_is_met(): void
    {
        // 40 old releases: everything past the 30-entry floor is out of the window.
        $this->writeHistory(array_fill(0, 40, 45));

        $this->artisan('release:snapshot')->assertExitCode(0);

        $releases = $this->releases();
        $this->assertCount(30, $releases);
        $this->assertSame('abc0', $releases[1]['hash'], 'this deploy is prepended, the rest shift down');
        $this->assertNotSame('abc0', $releases[0]['hash']);
    }

    public function test_a_busy_month_keeps_every_deploy_inside_the_window(): void
    {
        // 40 deploys, all within the last 30 days — a realistic fortnight here.
        $this->writeHistory(array_map(static fn (int $i): int => intdiv($i, 2), range(0, 39)));

        $this->artisan('release:snapshot')->assertExitCode(0);

        $this->assertCount(41, $this->releases());
    }
}
