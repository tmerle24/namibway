<?php

namespace App\Services\ImportExport;

use RuntimeException;
use ZipArchive;

/**
 * Reads the photo ZIP that accompanies a listings workbook: one folder per
 * listing, named in the sheet's `photo_folder` column.
 *
 * Folders are matched on their last path segment, case-insensitively, because
 * zipping a folder on macOS or Windows wraps everything in one more level —
 * "Photos/Okonjima Bush Camp/cover.jpg" has to be findable as
 * "Okonjima Bush Camp" or the content team would have to care about how their
 * operating system builds an archive.
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

    /** @var array<string, array{label: string, files: list<array{index: int, name: string, size: int}>}> */
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
        return isset($this->folders[ListingSheet::normalize($folder)]);
    }

    /**
     * @return list<array{index: int, name: string, size: int}> images in the folder, cover first
     */
    public function photos(string $folder): array
    {
        $entries = $this->folders[ListingSheet::normalize($folder)]['files'] ?? [];

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

            $folder = basename(dirname($name));

            if ($folder === '' || $folder === '.' || $folder === DIRECTORY_SEPARATOR) {
                continue; // A photo loose at the archive root belongs to no listing.
            }

            $key = ListingSheet::normalize($folder);
            $this->folders[$key]['label'] = $folder;
            $this->folders[$key]['files'][] = [
                'index' => $i,
                'name' => $basename,
                'size' => (int) $stat['size'],
            ];
        }
    }
}
