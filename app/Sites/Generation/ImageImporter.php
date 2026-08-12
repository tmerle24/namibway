<?php

namespace App\Sites\Generation;

use App\Enums\ContentSource;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Copies a listing's photographs into a site's own prefix on the bucket.
 *
 * ## Copied, never referenced
 *
 * The customer is promised the content is theirs. A picture that lives in
 * somebody else's row and disappears when we delete that row is not theirs, and
 * every future cleanup that decides what is referenced by reading listing
 * columns is one bad WHERE clause away from blanking a live customer site.
 *
 * ## What a listing photo actually is
 *
 * Three different things, and only one of them can be copied:
 *
 *  - a bucket key (`listings/{slug}/x.jpg`) written by the Excel importer;
 *  - an absolute URL built from CLOUDFLARE_R2_URL by the scrapers, which is the
 *    same object with a host in front of it;
 *  - an Unsplash URL — a stock placeholder AssignListingPhotos put there.
 *
 * The third is skipped, deliberately and loudly. Putting a stock photograph on
 * a paying customer's website implies it is theirs and it is not; a missing
 * hero is an obvious gap somebody fills, and a plausible wrong one is not.
 * Anything else external is skipped too: it is not ours to fetch.
 */
class ImageImporter
{
    /** Bigger than any photograph belongs on a page built for a slow connection. */
    private const MAX_BYTES = 12 * 1024 * 1024;

    public function __construct(private readonly GenerationReport $report) {}

    /**
     * @param  array<int, string>  $values  listing image column values, in order
     * @return array<int, SiteImage>
     */
    public function copyAll(Site $site, array $values, ContentSource $source, ?string $alt = null): array
    {
        $images = [];
        $sort = 0;

        foreach ($values as $value) {
            $image = $this->copy($site, (string) $value, $source, $alt, $sort);

            if ($image !== null) {
                $images[] = $image;
                $sort++;
            }
        }

        return $images;
    }

    public function copy(Site $site, string $value, ContentSource $source, ?string $alt, int $sort): ?SiteImage
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if ($this->isUnsplash($value)) {
            $this->report->skip('photo', 'stock placeholder, not the business\'s own photograph');

            return null;
        }

        $targetKey = $site->mediaPrefix().'/'.Str::random(8).'-'.$this->filename($value);

        // Two ways in, and the second is why this works at all. A copy inside
        // the bucket is free and instant, so it is tried first — but it needs
        // the stored value to resolve to a key that exists, and this column
        // holds at least four shapes: a bare key from the Excel importer, an
        // absolute URL built by a scraper from whatever CLOUDFLARE_R2_URL said
        // that day, a legacy path on the old public disk, and an operator's own
        // website. Classifying them was how the first version got it wrong and
        // produced sites with no pictures while the listing page showed them
        // perfectly well.
        //
        // So the fallback is to fetch it over HTTP from the same URL the
        // listing page renders — Controller::resolveMediaUrl is the app's own
        // answer to "where does this actually live", and if that URL works for
        // a traveller it works for us.
        if (! $this->copyWithinBucket($value, $targetKey) && ! $this->fetch($value, $targetKey)) {
            return null;
        }

        $this->report->imagesCopied++;

        return SiteImage::create([
            'site_id' => $site->id,
            'key' => $targetKey,
            'alt' => $alt,
            'content_source' => $source,
            // Publishable on namibway.com under Google's terms is not the same
            // as ours to hand a customer, and these expire besides
            // (google_photos_expire_at). Good enough to win a meeting with,
            // never good enough to publish — PublishGate enforces that.
            'prospect_only' => $source === ContentSource::GooglePlaces,
            'source_listing_id' => $site->source_listing_id,
            'sort' => $sort,
        ]);
    }

    /** The cheap path: the object is already in our bucket, so copy it there. */
    private function copyWithinBucket(string $value, string $targetKey): bool
    {
        $sourceKey = $this->bucketKey($value);

        if ($sourceKey === null) {
            return false;
        }

        try {
            $disk = Storage::disk('r2');

            if (! $disk->exists($sourceKey)) {
                return false;
            }

            $disk->copy($sourceKey, $targetKey);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The fallback: download it from wherever the listing page gets it.
     *
     * Bounded on purpose. A response that is not an image, or is implausibly
     * large, is somebody's error page or somebody's raw camera file, and
     * neither belongs on a page sold as loading over a weak connection.
     */
    private function fetch(string $value, string $targetKey): bool
    {
        try {
            $url = Controller::resolveMediaUrl($value);

            if (str_starts_with($url, '/')) {
                $url = rtrim((string) config('app.url'), '/').$url;
            }

            $response = Http::timeout(20)->get($url);

            if (! $response->successful()) {
                $this->report->skip('photo', "the stored photograph could not be fetched (HTTP {$response->status()})");

                return false;
            }

            $type = (string) $response->header('Content-Type');

            if (! str_starts_with($type, 'image/')) {
                $this->report->skip('photo', 'what is stored at that address is not an image');

                return false;
            }

            $body = $response->body();

            if (strlen($body) > self::MAX_BYTES) {
                $this->report->skip('photo', 'the photograph is larger than '.(self::MAX_BYTES / 1024 / 1024).' MB');

                return false;
            }

            Storage::disk('r2')->put($targetKey, $body);

            return true;
        } catch (Throwable) {
            $this->report->skip('photo', 'the stored photograph could not be fetched');

            return false;
        }
    }

    /**
     * The bucket key behind a stored image value, or null where there is not
     * one we may copy.
     */
    private function bucketKey(string $value): ?string
    {
        if (! Str::startsWith($value, ['http://', 'https://', '/'])) {
            // A bare key, which is what the Excel importer writes.
            return $value;
        }

        $base = rtrim((string) config('filesystems.disks.r2.url'), '/');

        if ($base !== '' && str_starts_with($value, $base.'/')) {
            return ltrim(substr($value, strlen($base)), '/');
        }

        return null;
    }

    /** A sane filename for the copy, whatever shape the source took. */
    private function filename(string $value): string
    {
        $path = (string) (parse_url($value, PHP_URL_PATH) ?: $value);
        $name = basename($path);

        return preg_match('/\.(jpe?g|png|webp|gif|avif)$/i', $name) === 1
            ? $name
            : ($name === '' ? 'photo.jpg' : $name.'.jpg');
    }

    private function isUnsplash(string $value): bool
    {
        return str_contains($value, 'images.unsplash.com');
    }
}
