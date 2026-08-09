<?php

namespace App\Console\Commands;

use App\Models\Listing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;

/**
 * Reports how much of what the scrapers (website crawl, Google Places, namibiayp)
 * have written to R2 is actually referenced by a listing today, vs. left behind by
 * the 30-day Google Places expiry cycle or a re-crawl that replaced an older photo.
 * Report-only by default — nothing is deleted unless --delete-orphaned is passed,
 * and only after showing the published/unpublished/pending breakdown first.
 */
class AuditR2Photos extends Command
{
    protected $signature = 'photos:audit-r2
                            {--delete-orphaned : Permanently delete files not referenced by any listing}
                            {--prefix=* : Limit to specific listings/* prefixes (default: all scraper prefixes)}';

    protected $description = 'Report (and optionally delete) scraped R2 photos no longer referenced by any listing';

    private const PREFIXES = [
        'listings/website-crawl',
        'listings/google-places',
        'listings/namibiayp',
    ];

    public function handle(): int
    {
        $prefixes = $this->option('prefix') ?: self::PREFIXES;
        $disk = Storage::disk('r2');

        [$referenced, $pending] = $this->buildReferenceMaps();

        foreach ($prefixes as $prefix) {
            $this->auditPrefix($disk, $prefix, $referenced, $pending);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, bool>  $referenced  filename => is_published
     * @param  array<string, bool>  $pending  filename => true
     */
    private function auditPrefix(mixed $disk, string $prefix, array $referenced, array $pending): void
    {
        $stats = [
            'published' => [0, 0],
            'unpublished' => [0, 0],
            'pending review' => [0, 0],
            'orphaned' => [0, 0],
        ];
        $orphanedKeys = [];
        $seen = 0;

        $this->info("=== {$prefix} ===");

        // Sizes come from the listing rather than a size() call per object —
        // see the same change in r2:usage-report.
        foreach ($disk->getDriver()->listContents($prefix, true) as $attributes) {
            if (! $attributes instanceof FileAttributes) {
                continue;
            }

            $key = $attributes->path();
            $name = basename($key);

            $bucket = match (true) {
                isset($referenced[$name]) => $referenced[$name] ? 'published' : 'unpublished',
                isset($pending[$name]) => 'pending review',
                default => 'orphaned',
            };

            if ($bucket === 'orphaned') {
                $orphanedKeys[] = $key;
            }

            $stats[$bucket][0]++;
            $stats[$bucket][1] += $attributes->fileSize() ?: 0;
            $seen++;
        }

        if ($seen === 0) {
            $this->line('(no files)');

            return;
        }

        $this->table(
            ['Status', 'Files', 'Size'],
            collect($stats)->map(fn ($v, $k) => [$k, $v[0], $this->formatBytes($v[1])])->values()
        );

        if ($this->option('delete-orphaned') && $orphanedKeys !== []) {
            if ($this->confirm(count($orphanedKeys)." orphaned file(s) under {$prefix} will be permanently deleted. Continue?")) {
                foreach (array_chunk($orphanedKeys, 100) as $chunk) {
                    $disk->delete($chunk);
                }
                $this->info('Deleted '.count($orphanedKeys)." orphaned file(s) under {$prefix}.");
            } else {
                $this->comment('Skipped deletion.');
            }
        }
    }

    /** @return array{0: array<string, bool>, 1: array<string, bool>} */
    private function buildReferenceMaps(): array
    {
        $referenced = [];
        $pending = [];

        Listing::query()
            ->select(['id', 'image', 'gallery', 'pending_image', 'pending_gallery', 'is_published'])
            ->orderBy('id')
            ->chunkById(500, function ($listings) use (&$referenced, &$pending): void {
                foreach ($listings as $listing) {
                    if ($listing->image) {
                        $referenced[$this->referenceKey($listing->image)] = (bool) $listing->is_published;
                    }
                    foreach ((array) $listing->gallery as $url) {
                        $referenced[$this->referenceKey($url)] = (bool) $listing->is_published;
                    }
                    if ($listing->pending_image) {
                        $pending[$this->referenceKey($listing->pending_image)] = true;
                    }
                    foreach ((array) $listing->pending_gallery as $url) {
                        $pending[$this->referenceKey($url)] = true;
                    }
                }
            });

        return [$referenced, $pending];
    }

    /**
     * Reduce a stored image value — absolute URL or disk-relative path — to just
     * its filename, which is what both sides of the comparison are matched on.
     *
     * This used to compare the raw DB value against Storage::url($key), which
     * only lined up as long as CLOUDFLARE_R2_URL never changed: scraper photos
     * are saved as absolute URLs built from that setting at download time. Point
     * it at a different host — a custom domain, say — and every referenced photo
     * would look orphaned, and --delete-orphaned would empty the live library.
     *
     * Matching the filename rather than the full path is deliberate: it is
     * immune to host, bucket-in-path and prefix differences, and every writer
     * here appends a random suffix (Str::random(8|10)) or a UUID, so distinct
     * photos don't share a name. Where it is imprecise it errs toward marking an
     * object as REFERENCED, which at worst leaves an orphan on disk — the
     * direction that cannot destroy anything.
     */
    private function referenceKey(string $value): string
    {
        $path = str_starts_with($value, 'http://') || str_starts_with($value, 'https://')
            ? (string) parse_url($value, PHP_URL_PATH)
            : $value;

        return basename($path);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = $bytes;
        $i = 0;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, 2)." {$units[$i]}";
    }
}
