<?php

namespace App\Filament\Support;

use Filament\Forms\Components\BaseFileUpload;
use Illuminate\Support\Facades\Storage;

/**
 * FileUpload's default file-info resolver assumes every stored value is a path
 * relative to its configured disk — but the enrichment pipeline (website scrape,
 * Google Places) stores full R2 URLs directly (Storage::disk('r2')->url($filename)),
 * which FileUpload has no built-in handling for: it always calls
 * Storage::disk($disk)->exists()/size()/mimeType()/url() on the raw value, so an
 * already-absolute URL silently resolved to nothing and the Photos tab showed an
 * empty dropzone even though image/gallery genuinely had a value. Filament's table
 * ImageColumn already has this exact full-URL passthrough built in (see its
 * getImageUrl()); this replicates that for FileUpload's edit-form preview via
 * ->getUploadedFileUsing().
 */
class PipelineImageResolver
{
    /** @return array{name: string, size: int, type: ?string, url: string}|null */
    public static function resolve(BaseFileUpload $component, string $file): ?array
    {
        if (filter_var($file, FILTER_VALIDATE_URL) !== false) {
            return [
                'name' => basename(parse_url($file, PHP_URL_PATH) ?: $file),
                'size' => 0,
                'type' => null,
                'url' => $file,
            ];
        }

        $storage = Storage::disk($component->getDiskName());

        if (! $storage->exists($file)) {
            return null;
        }

        $type = $storage->mimeType($file);

        return [
            'name' => basename($file),
            'size' => $storage->size($file),
            'type' => $type === false ? null : $type,
            'url' => $storage->url($file),
        ];
    }
}
