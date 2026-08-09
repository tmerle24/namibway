<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;

/**
 * Buckets every object in the R2 disk by its first N path segments and sums size,
 * so storage growth can be attributed to a directory (scraper output, Filament
 * uploads, etc.) instead of guessing from the writers in code alone.
 */
class ReportR2Usage extends Command
{
    protected $signature = 'r2:usage-report {--depth=2 : Number of path segments to group by}';

    protected $description = 'Break down R2 storage usage by top-level directory';

    public function handle(): int
    {
        $depth = max(1, (int) $this->option('depth'));
        $disk = Storage::disk('r2');

        $this->info('Listing all objects in R2...');

        $groups = []; // prefix => [count, bytes]
        $grandTotalBytes = 0;
        $fileCount = 0;

        // The size comes out of the listing itself. Asking the bucket for each
        // object's size separately (allFiles() + size()) is one HTTP request per
        // object, which on a bucket of this size is the difference between
        // seconds and hours.
        foreach ($disk->getDriver()->listContents('', true) as $attributes) {
            if (! $attributes instanceof FileAttributes) {
                continue;
            }

            $key = $attributes->path();
            $segments = explode('/', $key);
            $prefix = count($segments) > $depth
                ? implode('/', array_slice($segments, 0, $depth)).'/*'
                : implode('/', array_slice($segments, 0, -1) ?: ['(root)']);

            $size = $attributes->fileSize() ?: 0;

            $groups[$prefix] ??= [0, 0];
            $groups[$prefix][0]++;
            $groups[$prefix][1] += $size;
            $grandTotalBytes += $size;
            $fileCount++;
        }

        if ($fileCount === 0) {
            $this->info('Bucket is empty.');

            return self::SUCCESS;
        }

        uasort($groups, fn ($a, $b) => $b[1] <=> $a[1]);

        $this->table(
            ['Prefix', 'Files', 'Size'],
            collect($groups)->map(fn ($v, $prefix) => [$prefix, $v[0], $this->formatBytes($v[1])])->values()
        );

        $this->info("Total: {$fileCount} files, ".$this->formatBytes($grandTotalBytes));

        return self::SUCCESS;
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
