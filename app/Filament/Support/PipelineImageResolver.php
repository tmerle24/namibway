<?php

namespace App\Filament\Support;

use Filament\Forms\Components\BaseFileUpload;
use Illuminate\Support\Facades\Storage;

/**
 * FileUpload's default file-info resolver assumes every stored value is a path
 * relative to its configured disk — but image/gallery values on Listing come from
 * several different writers that don't agree on that: the enrichment pipeline
 * (website scrape, Google Places) stores full R2 URLs directly
 * (Storage::disk('r2')->url($filename)), while the partner-facing Filament panel's
 * FileUpload is configured with disk('public'), so listings edited there hold paths
 * relative to 'public' instead. FileUpload always calls
 * Storage::disk($disk)->exists()/size()/mimeType()/url() on the raw value using only
 * its own configured disk, so both an already-absolute URL and a path that lives on
 * a different disk than the one this form's component happens to use silently
 * resolved to nothing — the Photos tab showed an empty dropzone even though
 * image/gallery genuinely had a value. Mirrors Controller::resolveMediaUrl(), which
 * the public site uses for the same fields and already handles all three shapes.
 */
class PipelineImageResolver
{
    /** Disks that Listing image/gallery values have been observed to be relative to. */
    private const FALLBACK_DISKS = ['public'];

    /** @return array{name: string, size: int, type: ?string, url: string}|null */
    public static function resolve(BaseFileUpload $component, string $file): ?array
    {
        if (filter_var($file, FILTER_VALIDATE_URL) !== false || str_starts_with($file, '/')) {
            return [
                'name' => basename(parse_url($file, PHP_URL_PATH) ?: $file),
                'size' => 0,
                'type' => null,
                'url' => $file,
            ];
        }

        foreach ([$component->getDiskName(), ...self::FALLBACK_DISKS] as $disk) {
            $storage = Storage::disk($disk);

            if (! $storage->exists($file)) {
                continue;
            }

            $type = $storage->mimeType($file);

            return [
                'name' => basename($file),
                'size' => $storage->size($file),
                'type' => $type === false ? null : $type,
                'url' => $storage->url($file),
            ];
        }

        return null;
    }
}
