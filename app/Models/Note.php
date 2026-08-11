<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One thing somebody wrote about a customer or a stay, with their name frozen
 * beside the account that wrote it. See the migration for why it is not the
 * `reservations.notes` column.
 *
 * @property int $id
 * @property string $notable_type
 * @property int $notable_id
 * @property int|null $user_id
 * @property string $author_name
 * @property string $body
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $author
 */
class Note extends Model
{
    protected $fillable = [
        'user_id',
        'author_name',
        'body',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function notable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Write a note in the name of whoever is signed in, falling back to the
     * account's own name. The name is copied rather than read through the
     * relation every time, so a deleted staff account leaves the log readable.
     */
    public static function write(Model $subject, string $body, ?User $author): self
    {
        $note = new self([
            'user_id' => $author?->id,
            'author_name' => $author->name ?? 'NamibWay',
            'body' => $body,
        ]);

        $note->notable()->associate($subject);
        $note->save();

        return $note;
    }
}
