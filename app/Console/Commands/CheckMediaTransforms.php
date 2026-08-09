<?php

namespace App\Console\Commands;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Support\MediaUrl;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Verifies that Cloudflare Image Transformations actually serve resized images,
 * by fetching real catalog photos twice — once as stored, once through
 * /cdn-cgi/image/ — and comparing status, type and bytes.
 *
 * Deliberately probes the transformed URL even while
 * media.transforms.enabled is false, so the Cloudflare side (custom domain on
 * the bucket + Transformations on for the zone) can be confirmed working
 * BEFORE the flag is flipped in production. See DEPLOYMENT.md → "Bild-Thumbnails".
 *
 * Also reports images stored as an absolute URL on a host that is no longer the
 * configured media origin: those are ours, but they'd be served untransformed
 * (see checkLegacyOrigins()).
 */
class CheckMediaTransforms extends Command
{
    protected $signature = 'namibway:check-media-transforms
                            {--url= : Probe this exact image URL instead of sampling the catalog}
                            {--width=400 : Render width to request}
                            {--samples=5 : How many catalog images to sample}';

    protected $description = 'Check that Cloudflare Image Transformations are serving resized images';

    public function handle(): int
    {
        $width = (int) $this->option('width');
        $enabled = (bool) config('media.transforms.enabled');

        $this->reportConfig($enabled);

        $urls = $this->option('url')
            ? [(string) $this->option('url')]
            : $this->sampleCatalogUrls((int) $this->option('samples'));

        if ($urls === []) {
            $this->error('No image to probe — the catalog has no listing with an image, and no --url was given.');

            return self::FAILURE;
        }

        $transformed = 0;
        $probed = 0;

        foreach ($urls as $url) {
            $variant = $this->transformedUrl($url, $width);

            if ($variant === $url) {
                $this->line('');
                $this->warn('~ not rewritable: '.$this->shorten($url));
                $this->line('  Not on a configured media origin, so it is served as stored.');

                continue;
            }

            $probed++;

            if ($this->probePair($url, $variant, $width)) {
                $transformed++;
            }
        }

        $this->checkLegacyOrigins();

        return $this->verdict($enabled, $probed, $transformed);
    }

    private function reportConfig(bool $enabled): void
    {
        $this->info('=== config/media.php ===');
        $this->line('  enabled : '.($enabled ? 'true' : 'false — the app is currently serving originals'));
        $this->line('  origins : '.(implode(', ', MediaUrl::clientConfig()['origins']) ?: '(none)'));
        $this->line('  widths  : '.implode('/', MediaUrl::clientConfig()['widths']));
        $this->line('  quality : '.MediaUrl::clientConfig()['quality']);

        // The specific trap: CLOUDFLARE_R2_URL is set, but to a host that can
        // never serve /cdn-cgi/image/. Without saying so, "origins: (none)"
        // looks like the variable is missing rather than rejected.
        foreach ((array) config('media.transforms.origins', []) as $candidate) {
            if (! MediaUrl::canTransform((string) $candidate)) {
                $this->warn("  ! {$candidate} cannot serve /cdn-cgi/image/ and is ignored.");
                $this->line('    R2\'s own hostnames are not Cloudflare-proxied zones. Attach a');
                $this->line('    custom domain to the bucket and point CLOUDFLARE_R2_URL at it.');
            }
        }
    }

    /**
     * Build the transformed URL as if the feature were switched on. Probing it
     * while disabled is the whole point: it proves the Cloudflare side works
     * before production starts depending on it.
     */
    private function transformedUrl(string $url, int $width): string
    {
        $was = config('media.transforms.enabled');
        config(['media.transforms.enabled' => true]);

        try {
            return (string) MediaUrl::thumb($url, $width);
        } finally {
            config(['media.transforms.enabled' => $was]);
        }
    }

    /** Fetch both variants and compare. True when the transform genuinely worked. */
    private function probePair(string $original, string $variant, int $width): bool
    {
        $this->line('');
        $this->info('→ '.$this->shorten($original));

        $before = $this->fetch($original);
        $after = $this->fetch($variant);

        $this->line(sprintf(
            '  original    %s  %s  %s',
            $this->statusLabel($before['status']),
            str_pad($before['type'], 12),
            $this->bytes($before['bytes']),
        ));
        $this->line(sprintf(
            '  width=%-5d %s  %s  %s',
            $width,
            $this->statusLabel($after['status']),
            str_pad($after['type'], 12),
            $this->bytes($after['bytes']),
        ));

        if ($after['status'] !== 200) {
            $this->error('  ✗ the transformed URL did not return 200 — custom domain or Transformations missing');
            $this->line('    '.$this->shorten($variant));

            return false;
        }

        if ($before['status'] === 200 && $after['bytes'] >= $before['bytes']) {
            // A 200 that is no smaller means Cloudflare passed the original
            // through rather than resizing it — the usual cause is a bucket URL
            // that is public but not proxied by the zone.
            $this->warn('  ! 200, but no smaller than the original — looks like a pass-through, not a resize');

            return false;
        }

        if ($before['bytes'] > 0) {
            $saved = 100 - (int) round($after['bytes'] / $before['bytes'] * 100);
            $this->line("  ✓ resized, {$saved}% smaller");
        } else {
            $this->line('  ✓ transformed URL returned an image');
        }

        return true;
    }

    /** @return array{status: int, type: string, bytes: int} */
    private function fetch(string $url): array
    {
        // A GET rather than a HEAD: the byte count is the thing being measured,
        // and a HEAD can come back without a usable Content-Length.
        try {
            $response = Http::timeout(30)->get($url);

            return [
                'status' => $response->status(),
                'type' => str_replace('image/', '', (string) $response->header('Content-Type')) ?: '?',
                'bytes' => strlen($response->body()),
            ];
        } catch (Throwable $e) {
            $this->line('  request failed: '.$e->getMessage());

            return ['status' => 0, 'type' => '?', 'bytes' => 0];
        }
    }

    /**
     * Google Places photos are stored as an ABSOLUTE bucket URL built from
     * whatever CLOUDFLARE_R2_URL was at download time (GooglePlacesPhotoFinder
     * returns Storage::disk('r2')->url(...)). Point that env var at a new custom
     * domain and those rows still carry the old host, so neither
     * resolveMediaUrl nor MediaUrl::thumb touches them — they keep being served
     * full-size from the old URL. The object itself is still in our bucket,
     * which is exactly how this detects them.
     */
    private function checkLegacyOrigins(): void
    {
        $origins = MediaUrl::clientConfig()['origins'];
        $stale = 0;
        $sampleUrl = null;

        Listing::query()
            ->whereNotNull('image')
            ->where('image', 'like', 'http%')
            ->orderBy('id')
            ->chunkById(500, function (Collection $listings) use ($origins, &$stale, &$sampleUrl): void {
                foreach ($listings as $listing) {
                    $url = (string) $listing->image;

                    if ($this->isOnConfiguredOrigin($url, $origins)) {
                        continue;
                    }

                    if ($this->existsInBucket($url)) {
                        $stale++;
                        $sampleUrl ??= $url;
                    }
                }
            });

        $this->line('');
        $this->info('=== stored on a stale media origin ===');

        if ($stale === 0) {
            $this->line('  none — every bucket-hosted image sits on a configured origin.');

            return;
        }

        $this->warn("  {$stale} listing image(s) are stored as an absolute URL on a host that is");
        $this->warn('  no longer a configured origin, but the object IS in our bucket. These are');
        $this->warn('  served full-size and will not be transformed.');
        $this->line('  e.g. '.$this->shorten((string) $sampleUrl));
    }

    /** @param  list<string>  $origins */
    private function isOnConfiguredOrigin(string $url, array $origins): bool
    {
        foreach ($origins as $origin) {
            if ($origin !== '' && str_starts_with($url, $origin.'/')) {
                return true;
            }
        }

        return false;
    }

    private function existsInBucket(string $url): bool
    {
        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        if ($path === '') {
            return false;
        }

        try {
            return Storage::disk('r2')->exists($path);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    private function sampleCatalogUrls(int $limit): array
    {
        $urls = Listing::query()
            ->whereNotNull('image')
            ->where('is_published', true)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('image')
            ->map(fn (string $image): string => Controller::resolveMediaUrl($image))
            ->all();

        // array_values rather than Collection::values(): phpstan can prove the
        // former returns a list, which is what this method promises.
        return array_values($urls);
    }

    private function verdict(bool $enabled, int $probed, int $transformed): int
    {
        $this->line('');

        if ($probed === 0) {
            $this->error('Nothing probed — none of the sampled images is on a configured origin.');

            return self::FAILURE;
        }

        if ($transformed === 0) {
            $this->error("✗ 0/{$probed} transformed. Check DEPLOYMENT.md → \"Bild-Thumbnails\":");
            $this->line('  1. custom domain attached to the media bucket');
            $this->line('  2. Transformations enabled for the namibway.com zone');
            $this->line('  3. CLOUDFLARE_R2_URL pointing at that custom domain');

            return self::FAILURE;
        }

        $this->info("✓ {$transformed}/{$probed} transformed.");

        if (! $enabled) {
            $this->warn('  Transformations work, but MEDIA_TRANSFORMS_ENABLED is still false —');
            $this->warn('  set it to true and run `php artisan config:cache` to actually serve them.');
        }

        return $transformed === $probed ? self::SUCCESS : self::FAILURE;
    }

    private function statusLabel(int $status): string
    {
        return $status === 200 ? '200' : str_pad((string) ($status ?: 'ERR'), 3);
    }

    private function bytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? sprintf('%.1f MB', $bytes / 1024 / 1024)
            : sprintf('%d KB', (int) round($bytes / 1024));
    }

    private function shorten(string $url): string
    {
        return strlen($url) <= 110 ? $url : substr($url, 0, 70).'…'.substr($url, -35);
    }
}
