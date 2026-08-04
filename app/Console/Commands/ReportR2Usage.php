<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

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

        $this->info('Listing all objects in R2 (this walks the whole bucket, may take a while)...');
        $files = $disk->allFiles();

        if ($files === []) {
            $this->info('Bucket is empty.');

            return self::SUCCESS;
        }

        $groups = []; // prefix => [count, bytes]
        $grandTotalBytes = 0;

        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        foreach ($files as $key) {
            $segments = explode('/', $key);
            $prefix = count($segments) > $depth
                ? implode('/', array_slice($segments, 0, $depth)).'/*'
                : implode('/', array_slice($segments, 0, -1) ?: ['(root)']);

            $size = $disk->size($key) ?: 0;

            $groups[$prefix] ??= [0, 0];
            $groups[$prefix][0]++;
            $groups[$prefix][1] += $size;
            $grandTotalBytes += $size;

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        uasort($groups, fn ($a, $b) => $b[1] <=> $a[1]);

        $this->table(
            ['Prefix', 'Files', 'Size'],
            collect($groups)->map(fn ($v, $prefix) => [$prefix, $v[0], $this->formatBytes($v[1])])->values()
        );

        $this->info('Total: '.count($files).' files, '.$this->formatBytes($grandTotalBytes));

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
