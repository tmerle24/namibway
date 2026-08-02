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
 *
 * Each run appends one "release" entry (this deploy's commits only, i.e.
 * everything since the previously snapshotted hash) to a rolling history,
 * so the Release Notes page can show past releases, not just the latest
 * deploy's commit window. The file also keeps its previous flat top-level
 * fields (version/build/hash/.../commits) mirroring the newest release,
 * for backward compatibility with anything reading the old shape.
 */
class ReleaseSnapshot extends Command
{
    protected $signature = 'release:snapshot';

    protected $description = 'Snapshot the current git commit + recent log to storage/app/release/version.json';

    // Keep this many past releases around before trimming the oldest.
    private const MAX_RELEASES = 30;

    public function handle(): int
    {
        $hash = trim(Process::path(base_path())->run('git rev-parse --short HEAD')->output());

        if ($hash === '') {
            $this->warn('Not a git checkout (or git unavailable) — skipping release snapshot.');

            return self::SUCCESS;
        }

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
        $previous = File::exists($path) ? json_decode(File::get($path), associative: true) : null;

        // Migrate the old flat shape (no "releases" key) into a single-entry history
        // so existing on-server snapshots aren't lost when this ships.
        $releases = collect($previous['releases'] ?? (
            $previous ? [array_intersect_key($previous, array_flip(['version', 'build', 'hash', 'date', 'time', 'deployed_at', 'commits']))] : []
        ));

        $previousHash = $previous['hash'] ?? null;

        if ($previousHash === $hash) {
            // Re-ran with no new commits (e.g. a re-deploy of the same commit) — don't
            // duplicate the release entry, just refresh the top-level snapshot below.
            $commits = $releases->first()['commits'] ?? [];
        } else {
            $commits = $this->commitsSince($previousHash);

            $releases->prepend([
                'version' => $version,
                'build' => $buildNumber,
                'hash' => $hash,
                'date' => $headDate,
                'time' => $headTime,
                'deployed_at' => now()->toIso8601String(),
                'commits' => $commits,
            ]);
        }

        $releases = $releases->take(self::MAX_RELEASES)->values();

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'version' => $version,
            'build' => $buildNumber,
            'hash' => $hash,
            'date' => $headDate,
            'time' => $headTime,
            'deployed_at' => now()->toIso8601String(),
            'commits' => $commits,
            'releases' => $releases->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->info("Release snapshot written: v{$version} ({$hash}), ".$releases->count().' release(s) in history');

        return self::SUCCESS;
    }

    /**
     * Commits for this release: everything since $previousHash (exclusive) if known,
     * otherwise (first snapshot ever, or the previous hash no longer exists in history
     * e.g. after a rebase/force-push) fall back to the most recent 60 commits.
     */
    private function commitsSince(?string $previousHash): array
    {
        $range = $previousHash !== null ? "{$previousHash}..HEAD" : null;

        $result = $range !== null
            ? Process::path(base_path())->run(['git', 'log', $range, '--pretty=format:%h%x1f%ad%x1f%s', '--date=short'])
            : null;

        if ($result === null || ! $result->successful()) {
            $result = Process::path(base_path())
                ->run(['git', 'log', '-n', '60', '--pretty=format:%h%x1f%ad%x1f%s', '--date=short']);
        }

        return collect(preg_split('/\R/', trim($result->output())))
            ->filter()
            ->map(function (string $line) {
                [$commitHash, $date, $subject] = array_pad(explode("\x1f", $line, 3), 3, '');

                return ['hash' => $commitHash, 'date' => $date, 'subject' => $subject];
            })
            ->values()
            ->all();
    }
}
