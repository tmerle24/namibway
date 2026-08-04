<?php

namespace App\Console\Commands;

use App\Models\Listing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

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
     * @param  array<string, bool>  $referenced  url => is_published
     * @param  array<string, bool>  $pending  url => true
     */
    private function auditPrefix(mixed $disk, string $prefix, array $referenced, array $pending): void
    {
        $files = $disk->files($prefix);

        if ($files === []) {
            $this->info("=== {$prefix} === (no files)");

            return;
        }

        $stats = [
            'published' => [0, 0],
            'unpublished' => [0, 0],
            'pending review' => [0, 0],
            'orphaned' => [0, 0],
        ];
        $orphanedKeys = [];

        $this->info("=== {$prefix} ===");
        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        foreach ($files as $key) {
            $url = $disk->url($key);
            $size = $disk->size($key) ?: 0;

            $bucket = match (true) {
                isset($referenced[$url]) => $referenced[$url] ? 'published' : 'unpublished',
                isset($pending[$url]) => 'pending review',
                default => 'orphaned',
            };

            if ($bucket === 'orphaned') {
                $orphanedKeys[] = $key;
            }

            $stats[$bucket][0]++;
            $stats[$bucket][1] += $size;

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

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
                        $referenced[$listing->image] = (bool) $listing->is_published;
                    }
                    foreach ((array) $listing->gallery as $url) {
                        $referenced[$url] = (bool) $listing->is_published;
                    }
                    if ($listing->pending_image) {
                        $pending[$listing->pending_image] = true;
                    }
                    foreach ((array) $listing->pending_gallery as $url) {
                        $pending[$url] = true;
                    }
                }
            });

        return [$referenced, $pending];
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
