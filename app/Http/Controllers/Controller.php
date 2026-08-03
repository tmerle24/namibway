<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Throwable;

abstract class Controller
{
    public static function resolveMediaUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        // Every FileUpload field (admin + partner Listing image/gallery, partner logo,
        // region image) writes to 'r2' now, but rows uploaded before that switch still
        // hold paths relative to 'public'. Check r2 first since it's the current
        // default; fall back to public for those pre-existing rows.
        try {
            return Storage::disk('r2')->exists($path)
                ? Storage::disk('r2')->url($path)
                : Storage::disk('public')->url($path);
        } catch (Throwable) {
            // R2 unreachable or unconfigured (e.g. missing credentials) —
            // assume the current-default disk rather than 500ing every page
            // that renders an image over it. A wrong guess just breaks one
            // <img>, not the whole page.
            return Storage::disk('r2')->url($path);
        }
    }
}
