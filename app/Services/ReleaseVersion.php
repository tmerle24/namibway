<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * Reads the release snapshot written by `php artisan release:snapshot`
 * (see deploy.sh). Falls back gracefully in local dev where no deploy
 * has run yet.
 *
 * @phpstan-type ReleaseCommit array{hash: string, date: string, subject: string}
 * @phpstan-type ReleaseEntry array{
 *     version: string|null,
 *     build: int|null,
 *     hash: string|null,
 *     date: string|null,
 *     time: string|null,
 *     deployed_at: string|null,
 *     commits: array<int, ReleaseCommit>,
 * }
 * @phpstan-type ReleaseData array{
 *     version: string|null,
 *     build: int|null,
 *     hash: string|null,
 *     date: string|null,
 *     time: string|null,
 *     deployed_at: string|null,
 *     commits: array<int, ReleaseCommit>,
 *     releases: array<int, ReleaseEntry>,
 * }
 */
class ReleaseVersion
{
    /**
     * @return ReleaseData
     */
    public static function current(): array
    {
        $path = storage_path('app/release/version.json');

        if (! File::exists($path)) {
            return ['version' => null, 'build' => null, 'hash' => null, 'date' => null, 'time' => null, 'deployed_at' => null, 'commits' => [], 'releases' => []];
        }

        $data = json_decode(File::get($path), associative: true);

        // Older snapshots (written before release history existed) have no "releases"
        // key — fall back to a single release built from the flat top-level fields.
        $releases = $data['releases'] ?? (
            isset($data['version']) ? [[
                'version' => $data['version'] ?? null,
                'build' => $data['build'] ?? null,
                'hash' => $data['hash'] ?? null,
                'date' => $data['date'] ?? null,
                'time' => $data['time'] ?? null,
                'deployed_at' => $data['deployed_at'] ?? null,
                'commits' => $data['commits'] ?? [],
            ]] : []
        );

        return [
            'version' => $data['version'] ?? null,
            'build' => $data['build'] ?? null,
            'hash' => $data['hash'] ?? null,
            'date' => $data['date'] ?? null,
            'time' => $data['time'] ?? null,
            'deployed_at' => $data['deployed_at'] ?? null,
            'commits' => $data['commits'] ?? [],
            'releases' => $releases,
        ];
    }
}
