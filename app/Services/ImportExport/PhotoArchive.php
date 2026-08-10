<?php

namespace App\Services\ImportExport;

use RuntimeException;
use ZipArchive;

/**
 * Reads the photo ZIP that accompanies a listings workbook: one folder per
 * listing, named in the sheet's `photo_folder` column.
 *
 * A folder is matched on the tail of its path, case-insensitively: "Okonjima
 * Bush Camp" finds "Photos/Okonjima Bush Camp/", because zipping a folder on
 * macOS or Windows wraps everything in one more level and the content team
 * should not have to care how their operating system builds an archive. When a
 * tail matches more than one folder — likely for room photos, where every lodge
 * has an "STD" — the caller is told to write more of the path
 * ("Okonjima Bush Camp/STD") rather than getting one of them at random.
 *
 * Nothing is extracted to a path taken from the archive: entries are read by
 * index and only their basename is ever used, so a crafted zip can't write
 * outside the storage key it was given.
 */
class PhotoArchive
{
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** A single photo above this is a mistake, not a photo. */
    public const MAX_FILE_BYTES = 25 * 1024 * 1024;

    public const MAX_FILES_PER_FOLDER = 40;

    /** @var array<string, array{label: string, segments: list<string>, files: list<array{index: int, name: string, size: int}>}> normalized full path => folder */
    private array $folders = [];

    private function __construct(private readonly ZipArchive $zip) {}

    public static function open(string $path): self
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('The ZIP file could not be opened.');
        }

        $archive = new self($zip);
        $archive->index();

        return $archive;
    }

    public function close(): void
    {
        $this->zip->close();
    }

    /** @return list<string> the folder names as they appear in the archive */
    public function folderNames(): array
    {
        return array_values(array_map(
            static fn (array $folder): string => $folder['label'],
            $this->folders,
        ));
    }

    public function hasFolder(string $folder): bool
    {
        return is_string($this->resolve($folder));
    }

    /**
     * Which archive folder a sheet cell means.
     *
     * @return string|list<string>|null the folder key, the competing labels when the
     *                                  name is ambiguous, or null when nothing matches
     */
    public function resolve(string $folder): string|array|null
    {
        $wanted = array_values(array_filter(array_map(
            ListingSheet::normalize(...),
            explode('/', str_replace('\\', '/', trim($folder))),
        )));

        if ($wanted === []) {
            return null;
        }

        $matches = [];

        foreach ($this->folders as $key => $candidate) {
            if (array_slice($candidate['segments'], -count($wanted)) === $wanted) {
                $matches[$key] = $candidate['label'];
            }
        }

        return match (count($matches)) {
            0 => null,
            1 => (string) array_key_first($matches),
            default => array_values($matches),
        };
    }

    /**
     * @return list<array{index: int, name: string, size: int}> images in the folder, cover first
     */
    public function photos(string $folder): array
    {
        $key = $this->resolve($folder);
        $entries = is_string($key) ? $this->folders[$key]['files'] : [];

        usort($entries, static function (array $a, array $b): int {
            // A file called "cover" is the hero image; everything else keeps the
            // order the person sees in their file manager.
            $aCover = str_starts_with(mb_strtolower(pathinfo($a['name'], PATHINFO_FILENAME)), 'cover');
            $bCover = str_starts_with(mb_strtolower(pathinfo($b['name'], PATHINFO_FILENAME)), 'cover');

            return $aCover === $bCover ? strnatcasecmp($a['name'], $b['name']) : ($aCover ? -1 : 1);
        });

        return $entries;
    }

    public function contents(int $index): ?string
    {
        $contents = $this->zip->getFromIndex($index);

        return $contents === false ? null : $contents;
    }

    private function index(): void
    {
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $stat = $this->zip->statIndex($i);

            if ($stat === false) {
                continue;
            }

            $name = $stat['name'];
            $basename = basename($name);

            // Directory entries, macOS resource forks, and dotfiles.
            if (str_ends_with($name, '/') || str_contains($name, '__MACOSX/') || str_starts_with($basename, '.')) {
                continue;
            }

            if (! in_array(mb_strtolower(pathinfo($basename, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                continue;
            }

            $directory = trim(str_replace('\\', '/', dirname($name)), '/');

            if ($directory === '' || $directory === '.') {
                continue; // A photo loose at the archive root belongs to no listing.
            }

            $segments = array_values(array_filter(explode('/', $directory)));
            $key = implode('/', array_map(ListingSheet::normalize(...), $segments));

            $this->folders[$key] ??= [
                'label' => $directory,
                'segments' => array_map(ListingSheet::normalize(...), $segments),
                'files' => [],
            ];

            $this->folders[$key]['files'][] = [
                'index' => $i,
                'name' => $basename,
                'size' => (int) $stat['size'],
            ];
        }
    }
}
