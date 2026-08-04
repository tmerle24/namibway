<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportR2UsageCommandTest extends TestCase
{
    public function test_it_groups_and_sums_size_by_prefix(): void
    {
        $disk = Storage::fake('r2');

        $disk->put('listings/website-crawl/a.jpg', str_repeat('x', 10));
        $disk->put('listings/website-crawl/b.jpg', str_repeat('x', 20));
        $disk->put('listings/gallery/c.jpg', str_repeat('x', 5));
        $disk->put('partners/logo.png', str_repeat('x', 1));

        $this->artisan('r2:usage-report')
            ->assertSuccessful()
            ->expectsOutputToContain('listings/website-crawl/*')
            ->expectsOutputToContain('listings/gallery/*')
            ->expectsOutputToContain('partners')
            ->expectsOutputToContain('Total: 4 files, 36 B');
    }
}
