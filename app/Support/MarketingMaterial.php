<?php

namespace App\Support;

/**
 * Resolves the print material listed in config/marketing.php against the files
 * committed in marketing/out/.
 *
 * Both lookups go through the config array rather than through anything a request
 * carries: a caller names a key and a variant, and if either is unknown the answer
 * is null. That is what keeps the download route from being a file reader — there
 * is no code path where a request-supplied string reaches the filesystem.
 *
 * @phpstan-type MaterialEntry array{title: string, audience: string, description: string, basename: string, source: string}
 * @phpstan-type VariantEntry array{label: string, note: string}
 */
final class MarketingMaterial
{
    /**
     * Every piece of material, with its files resolved. Entries whose PDF has not
     * been built are still returned, marked unavailable, so the admin page can say
     * so instead of silently listing one download where there should be two.
     *
     * Reports availability rather than paths: this feeds a public Livewire property,
     * which is serialised into the rendered page, and the server's directory layout
     * has no business being in the DOM. The controller resolves the path itself.
     *
     * @return list<array{
     *     key: string,
     *     title: string,
     *     audience: string,
     *     description: string,
     *     source: string,
     *     files: list<array{variant: string, label: string, note: string, available: bool, size: string|null}>
     * }>
     */
    public static function all(): array
    {
        $result = [];

        foreach (self::material() as $key => $item) {
            $files = [];

            foreach (self::variants() as $variant => $meta) {
                $file = self::file($key, $variant);

                $files[] = [
                    'variant' => $variant,
                    'label' => $meta['label'],
                    'note' => $meta['note'],
                    'available' => $file !== null,
                    'size' => $file === null ? null : self::formatSize(filesize($file['path'])),
                ];
            }

            $result[] = [
                'key' => $key,
                'title' => $item['title'],
                'audience' => $item['audience'],
                'description' => $item['description'],
                'source' => $item['source'],
                'files' => $files,
            ];
        }

        return $result;
    }

    /**
     * The path and download name for one file, or null if the key or the variant
     * is not one we publish — or if the PDF simply is not there, which on a fresh
     * checkout means nobody has run the build.
     *
     * @return array{path: string, name: string}|null
     */
    public static function file(string $key, string $variant): ?array
    {
        $material = self::material();
        $variants = self::variants();

        if (! isset($material[$key], $variants[$variant])) {
            return null;
        }

        $name = $material[$key]['basename'].'-'.$variant.'.pdf';
        $path = base_path(self::directory().'/'.$name);

        if (! is_file($path)) {
            return null;
        }

        return ['path' => $path, 'name' => $name];
    }

    /**
     * @return array<string, MaterialEntry>
     */
    private static function material(): array
    {
        /** @var array<string, MaterialEntry> */
        return config('marketing.material', []);
    }

    /**
     * @return array<string, VariantEntry>
     */
    private static function variants(): array
    {
        /** @var array<string, VariantEntry> */
        return config('marketing.variants', []);
    }

    private static function directory(): string
    {
        /** @var string */
        return config('marketing.directory', 'marketing/out');
    }

    /**
     * Deliberately no "last built" timestamp anywhere here: git does not preserve
     * mtimes, so on the server a file's mtime is when it was checked out, not when
     * the PDF was made. Showing it would read as freshness and mean deploy date.
     */
    private static function formatSize(int|false $bytes): ?string
    {
        if ($bytes === false) {
            return null;
        }

        return $bytes >= 1024 * 1024
            ? round($bytes / 1024 / 1024, 1).' MB'
            : max(1, (int) round($bytes / 1024)).' KB';
    }
}
