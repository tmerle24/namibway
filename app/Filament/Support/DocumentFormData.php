<?php

namespace App\Filament\Support;

use App\Enums\DocumentKind;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Everything a document row needs that the form itself cannot ask for: who is
 * saving it, and what the uploaded file actually turned out to be.
 *
 * Size and MIME type are read back off the disk after the upload has landed
 * rather than taken from the browser's multipart headers, because those are
 * whatever the client claimed. They are stored (instead of being read on every
 * render) so listing a folder costs no filesystem calls at all.
 *
 * Shared by the create and edit pages so the two cannot drift — the bug that
 * shape invites is an edit quietly leaving a stale size behind after a file has
 * been replaced.
 */
class DocumentFormData
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepare(array $data, ?User $user, bool $creating): array
    {
        $kind = $data['kind'] ?? DocumentKind::File->value;
        $kind = $kind instanceof DocumentKind ? $kind : DocumentKind::from((string) $kind);

        if ($kind === DocumentKind::Page) {
            // A page has no file. Clearing rather than ignoring keeps a row that
            // was somehow saved with both from claiming a download it hasn't got.
            $data['disk'] = null;
            $data['path'] = null;
            $data['original_name'] = null;
            $data['mime_type'] = null;
            $data['size'] = null;
        } else {
            $data['body'] = null;
            $data['disk'] = Document::DISK;

            // FileUpload writes the uploaded name into `original_name`. A single
            // upload dehydrates to a string, but the same option keyed by file
            // id is what a multiple upload produces — take the first either way
            // rather than handing an array to a string column.
            if (is_array($data['original_name'] ?? null)) {
                $data['original_name'] = Arr::first($data['original_name']);
            }

            $data = [...$data, ...self::describe($data['path'] ?? null)];
        }

        if ($creating) {
            $data['author_id'] = $user?->id;
            $data['author_name'] = $user->name ?? 'NamibWay';
        } else {
            $data['editor_id'] = $user?->id;
            $data['editor_name'] = $user->name ?? 'NamibWay';
        }

        return $data;
    }

    /**
     * @return array{mime_type?: string|null, size?: int|null}
     */
    private static function describe(mixed $path): array
    {
        if (! is_string($path) || $path === '') {
            return [];
        }

        try {
            $disk = Storage::disk(Document::DISK);

            if (! $disk->exists($path)) {
                return [];
            }

            return [
                'mime_type' => $disk->mimeType($path) ?: null,
                'size' => $disk->size($path),
            ];
        } catch (Throwable) {
            // The file is saved either way; an unknown size shows as "—" rather
            // than failing the save the person just made.
            return [];
        }
    }
}
