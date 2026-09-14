<?php

namespace App\Sites\Import;

use RuntimeException;
use ZipArchive;

/**
 * A website package: one ZIP holding `site.json` and the media it names.
 *
 * Format: SITE_PACKAGE.md. Folders inside the archive are ignored — a file is
 * found by its basename, so zipping a folder on any OS works. The one exception
 * is `listings/`, which belongs to the platform listings rather than to the
 * website: those photographs are handed to the listings importer whole, folder
 * names and all, and never appear in the site's own picture list. Entries are read
 * by index and only the basename is ever used, so a crafted archive cannot
 * write anywhere (same rule as PhotoArchive).
 */
class SitePackage
{
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public const VIDEO_EXTENSIONS = ['mp4', 'webm', 'mov'];

    public const MAX_FILE_BYTES = 25 * 1024 * 1024;

    public const MAX_FILES = 120;

    /** @var array<string, int> lowercased basename => zip index */
    private array $files = [];

    /** @var array<string, mixed> */
    private array $manifest = [];

    private ?string $listingsCsv = null;

    /** @var list<string> */
    private array $errors = [];

    private function __construct(private readonly ZipArchive $zip, public readonly string $path) {}

    public static function open(string $path): self
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('The ZIP file could not be opened.');
        }

        $package = new self($zip, $path);
        $package->index();

        return $package;
    }

    public function close(): void
    {
        $this->zip->close();
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return $this->manifest;
    }

    /** @return list<string> problems that stop the whole package */
    public function errors(): array
    {
        return $this->errors;
    }

    /** The listings sheet, where the package carries one. */
    public function listingsCsv(): ?string
    {
        return $this->listingsCsv;
    }

    public function has(string $file): bool
    {
        return isset($this->files[strtolower(basename($file))]);
    }

    public function isImage(string $file): bool
    {
        return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true);
    }

    public function isVideo(string $file): bool
    {
        return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::VIDEO_EXTENSIONS, true);
    }

    public function contents(string $file): string
    {
        $index = $this->files[strtolower(basename($file))] ?? null;
        $bytes = $index === null ? false : $this->zip->getFromIndex($index);

        if (! is_string($bytes)) {
            throw new RuntimeException("[{$file}] could not be read from the ZIP.");
        }

        return $bytes;
    }

    /** @return list<string> every media basename in the archive */
    public function mediaFiles(): array
    {
        return array_values(array_filter(
            array_map(fn (int $index): string => basename((string) $this->zip->getNameIndex($index)), $this->files),
            fn (string $name): bool => $this->isImage($name) || $this->isVideo($name),
        ));
    }

    private function index(): void
    {
        $manifestIndex = null;

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $stat = $this->zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');

            // Folders, and the metadata macOS adds to every archive it makes.
            if ($name === '' || str_ends_with($name, '/') || str_contains($name, '__MACOSX/') || str_starts_with(basename($name), '.')) {
                continue;
            }

            $base = strtolower(basename($name));

            if ($base === 'site.json') {
                $manifestIndex = $i;

                continue;
            }

            if ($base === 'listings.csv') {
                $this->listingsCsv = (string) $this->zip->getFromIndex($i);

                continue;
            }

            // Listing photographs: read by the listings importer straight from
            // the archive, so they are neither indexed nor reported as unused.
            if (str_starts_with(strtolower($name), 'listings/') || str_contains(strtolower($name), '/listings/')) {
                continue;
            }

            if (! $this->isImage($base) && ! $this->isVideo($base)) {
                continue;
            }

            if ((int) ($stat['size'] ?? 0) > self::MAX_FILE_BYTES) {
                $this->errors[] = "[{$name}] is larger than 25 MB.";

                continue;
            }

            if (isset($this->files[$base])) {
                $this->errors[] = "Two files are called [{$base}] — names must be unique across the whole ZIP.";

                continue;
            }

            $this->files[$base] = $i;
        }

        if (count($this->files) > self::MAX_FILES) {
            $this->errors[] = 'More than '.self::MAX_FILES.' media files.';
        }

        if ($manifestIndex === null) {
            $this->errors[] = 'No site.json in the ZIP.';

            return;
        }

        $json = json_decode((string) $this->zip->getFromIndex($manifestIndex), true);

        if (! is_array($json)) {
            $this->errors[] = 'site.json is not valid JSON: '.json_last_error_msg().'.';

            return;
        }

        if ((int) ($json['version'] ?? 0) !== 1) {
            $this->errors[] = 'site.json must say "version": 1.';
        }

        $this->manifest = $json;
    }
}
