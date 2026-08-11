<?php

namespace App\Sites\Generation;

use App\Enums\ContentSource;
use App\Models\Site;
use App\Models\SiteImage;
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

        $sourceKey = $this->bucketKey($value);

        if ($sourceKey === null) {
            $this->report->skip(
                'photo',
                $this->isUnsplash($value)
                    ? 'stock placeholder, not the business\'s own photograph'
                    : 'stored outside our bucket, nothing to copy'
            );

            return null;
        }

        $disk = Storage::disk('r2');
        $targetKey = $site->mediaPrefix().'/'.Str::random(8).'-'.basename($sourceKey);

        try {
            if (! $disk->exists($sourceKey)) {
                $this->report->skip('photo', 'the stored file is missing from the bucket');

                return null;
            }

            $disk->copy($sourceKey, $targetKey);
        } catch (Throwable) {
            $this->report->skip('photo', 'the bucket refused the copy');

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

    private function isUnsplash(string $value): bool
    {
        return str_contains($value, 'images.unsplash.com');
    }
}
