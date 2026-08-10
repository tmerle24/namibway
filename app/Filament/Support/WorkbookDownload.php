<?php

namespace App\Filament\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Hands a generated .xlsx to the browser from inside a Filament action.
 *
 * Returning the response object straight from the action does not work: Livewire
 * puts a method's return value into the JSON payload of its update request, and
 * a Symfony Response isn't json-encodable ("Type is not supported") — and even
 * where it is captured as a download effect, the whole workbook would travel
 * base64-encoded inside that payload. So the action redirects to a one-shot
 * signed URL instead: the file stays on disk until the browser fetches it, and
 * the redirect doesn't leave the page because the response is an attachment.
 *
 * The token, not the path, is what the URL carries — nothing a request sends can
 * point the download at another file.
 */
class WorkbookDownload
{
    private const TTL_MINUTES = 10;

    /**
     * Where a workbook is built before it is handed out. Files are deleted when
     * downloaded; this also sweeps the ones nobody ever fetched, so a link that
     * was generated and ignored doesn't leak disk forever.
     */
    public static function path(string $name): string
    {
        $directory = storage_path('app/exports');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        foreach (glob("{$directory}/*.xlsx") ?: [] as $file) {
            if (filemtime($file) < now()->subDay()->getTimestamp()) {
                @unlink($file);
            }
        }

        return "{$directory}/{$name}";
    }

    public static function link(string $path, string $downloadName): string
    {
        $token = (string) Str::uuid();

        Cache::put(
            self::cacheKey($token),
            ['path' => $path, 'name' => $downloadName],
            now()->addMinutes(self::TTL_MINUTES),
        );

        return URL::temporarySignedRoute(
            'workbooks.download',
            now()->addMinutes(self::TTL_MINUTES),
            ['token' => $token],
        );
    }

    /**
     * @return array{path: string, name: string}|null
     */
    public static function claim(string $token): ?array
    {
        /** @var array{path: string, name: string}|null $entry */
        $entry = Cache::pull(self::cacheKey($token));

        return $entry;
    }

    private static function cacheKey(string $token): string
    {
        return "workbook-download:{$token}";
    }
}
