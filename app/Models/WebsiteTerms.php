<?php

namespace App\Models;

use App\Sites\WebsiteTermsText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One version of our own terms for the website product.
 *
 * A version rather than a setting, because a customer's acceptance is recorded
 * against a moment (`sites.terms_accepted_at`) and "they accepted the terms"
 * means nothing if the text they accepted has since been edited over.
 *
 * @property int $id
 * @property string $version
 * @property string $body
 * @property Carbon|null $effective_at
 * @property Carbon|null $published_at
 */
class WebsiteTerms extends Model
{
    protected $table = 'website_terms';

    protected $fillable = [
        'version',
        'body',
        'effective_at',
        'published_at',
    ];

    protected $casts = [
        'effective_at' => 'date',
        'published_at' => 'datetime',
    ];

    /**
     * The version in force, or null while none has been published.
     *
     * Null is a real answer and has to be handled: the foot of every customer
     * site links to this, and a Terms link that 404s in front of a prospective
     * customer is worse than no link at all.
     */
    public static function current(): ?self
    {
        return self::query()
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->first();
    }

    /**
     * The starting point, created on first use rather than by a seeder — a
     * fresh database, a test and a deploy that has not run seeders all have to
     * answer the same question.
     *
     * Deliberately *unpublished*: the scaffold describes how the product works
     * but has not been read by a lawyer, and it is not something to put in
     * front of a customer on the strength of having been written.
     */
    public static function scaffoldIfEmpty(): self
    {
        return self::query()->firstOrCreate(
            ['version' => WebsiteTermsText::VERSION],
            ['body' => WebsiteTermsText::scaffold()],
        );
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }
}
