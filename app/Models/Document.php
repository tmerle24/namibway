<?php

namespace App\Models;

use App\Enums\DocumentKind;
use Carbon\CarbonImmutable;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One filed document: an uploaded file, or a page written in the admin panel.
 *
 * @property int $id
 * @property int|null $document_category_id
 * @property DocumentKind $kind
 * @property string $title
 * @property string|null $description
 * @property string|null $body
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $original_name
 * @property string|null $mime_type
 * @property int|null $size
 * @property int|null $author_id
 * @property string $author_name
 * @property int|null $editor_id
 * @property string|null $editor_name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read DocumentCategory|null $category
 * @property-read Collection<int, Note> $notes
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /**
     * Where uploaded documents are written. Private, not the public media
     * bucket — see the migration for why.
     */
    public const DISK = 'local';

    public const DIRECTORY = 'documents';

    protected $fillable = [
        'document_category_id',
        'kind',
        'title',
        'description',
        'body',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'author_id',
        'author_name',
        'editor_id',
        'editor_name',
    ];

    protected $casts = [
        'kind' => DocumentKind::class,
        'size' => 'integer',
    ];

    protected static function booted(): void
    {
        // The stored file belongs to the row, so it goes when the row goes.
        // Comments too — they are about this document and mean nothing without
        // it (`notes` is polymorphic, so nothing in the database cascades).
        static::deleting(function (self $document): void {
            $document->deleteStoredFile();
            $document->notes()->delete();
        });
    }

    /**
     * @return BelongsTo<DocumentCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'document_category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    /**
     * The discussion about this document, newest first — the same log staff
     * write on customers and stays, so it behaves the way they already expect.
     *
     * @return MorphMany<Note, $this>
     */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable')->latest();
    }

    public function isFile(): bool
    {
        return $this->kind === DocumentKind::File && filled($this->path);
    }

    public function isPage(): bool
    {
        return $this->kind === DocumentKind::Page;
    }

    /** The name the browser should save the download as. */
    public function downloadName(): string
    {
        return $this->original_name ?: basename((string) $this->path);
    }

    /** "1.4 MB" — null when there is no file, or its size was never recorded. */
    public function humanSize(): ?string
    {
        if ($this->size === null) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $this->size;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) (int) $size : number_format($size, 1)).' '.$units[$unit];
    }

    /** Whether the file can be shown inline rather than only downloaded. */
    public function isImage(): bool
    {
        return $this->isFile() && str_starts_with((string) $this->mime_type, 'image/');
    }

    /**
     * Stamp who wrote this. Copied rather than read through the relation later,
     * for the same reason a note freezes its author's name.
     */
    public function stampAuthor(?User $user): void
    {
        $this->author_id = $user?->id;
        $this->author_name = $user->name ?? 'NamibWay';
    }

    /** Stamp who last changed it — the wiki's answer to "who wrote that?". */
    public function stampEditor(?User $user): void
    {
        $this->editor_id = $user?->id;
        $this->editor_name = $user->name ?? 'NamibWay';
    }

    /**
     * Remove the stored file, if there is one. Failures are swallowed: a file
     * already gone (or a disk briefly unreachable) must not block deleting the
     * row, which is the thing the person actually asked for.
     */
    public function deleteStoredFile(): void
    {
        if (blank($this->path)) {
            return;
        }

        try {
            Storage::disk($this->disk ?: self::DISK)->delete((string) $this->path);
        } catch (Throwable) {
            // Left behind on disk rather than left behind in the list.
        }
    }
}
