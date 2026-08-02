<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Writes storage/app/release/version.json from the current git state.
 * Run at the end of every deploy (see deploy.sh) so the admin header
 * version badge and the Release Notes page reflect what's actually live,
 * without PHP-FPM needing read access to .git at request time.
 */
class ReleaseSnapshot extends Command
{
    protected $signature = 'release:snapshot';

    protected $description = 'Snapshot the current git commit + recent log to storage/app/release/version.json';

    public function handle(): int
    {
        $hash = trim(Process::path(base_path())->run('git rev-parse --short HEAD')->output());

        if ($hash === '') {
            $this->warn('Not a git checkout (or git unavailable) — skipping release snapshot.');

            return self::SUCCESS;
        }

        $log = Process::path(base_path())
            ->run(['git', 'log', '-n', '60', '--pretty=format:%h%x1f%ad%x1f%s', '--date=short'])
            ->output();

        $commits = collect(preg_split('/\R/', trim($log)))
            ->filter()
            ->map(function (string $line) {
                [$commitHash, $date, $subject] = array_pad(explode("\x1f", $line, 3), 3, '');

                return ['hash' => $commitHash, 'date' => $date, 'subject' => $subject];
            })
            ->values()
            ->all();

        // Build number = total commit count on this branch. No git tags are maintained
        // in this repo, so this is the only value that's both a "real" looking version
        // (v1.0.<n>) and guaranteed to strictly increase on every deploy without any
        // manual tagging step.
        $buildNumber = (int) trim(Process::path(base_path())->run('git rev-list --count HEAD')->output());
        $version = "1.0.{$buildNumber}";

        $headDateTime = trim(Process::path(base_path())
            ->run(['git', 'log', '-1', '--pretty=format:%ad', '--date=format:%Y-%m-%d %H:%M'])
            ->output());
        [$headDate, $headTime] = $headDateTime !== ''
            ? array_pad(explode(' ', $headDateTime, 2), 2, '')
            : [now()->toDateString(), now()->format('H:i')];

        $path = storage_path('app/release/version.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'version' => $version,
            'build' => $buildNumber,
            'hash' => $hash,
            'date' => $headDate,
            'time' => $headTime,
            'deployed_at' => now()->toIso8601String(),
            'commits' => $commits,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->info("Release snapshot written: v{$version} ({$hash})");

        return self::SUCCESS;
    }
}
