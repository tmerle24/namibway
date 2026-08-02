<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * Reads the release snapshot written by `php artisan release:snapshot`
 * (see deploy.sh). Falls back gracefully in local dev where no deploy
 * has run yet.
 */
class ReleaseVersion
{
    public static function current(): array
    {
        $path = storage_path('app/release/version.json');

        if (! File::exists($path)) {
            return ['version' => null, 'build' => null, 'hash' => null, 'date' => null, 'time' => null, 'deployed_at' => null, 'commits' => []];
        }

        $data = json_decode(File::get($path), associative: true);

        return [
            'version' => $data['version'] ?? null,
            'build' => $data['build'] ?? null,
            'hash' => $data['hash'] ?? null,
            'date' => $data['date'] ?? null,
            'time' => $data['time'] ?? null,
            'deployed_at' => $data['deployed_at'] ?? null,
            'commits' => $data['commits'] ?? [],
        ];
    }
}
