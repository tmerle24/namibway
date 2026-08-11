<?php

namespace App\Models;

use App\Sites\BlockRegistry;
use App\Sites\Blocks\BlockDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * One block on a page. The type is a string, the payload is JSON, and the
 * validation for both lives in App\Sites\Blocks — see the migration.
 *
 * @property int $id
 * @property int $site_page_id
 * @property string $type
 * @property array<string, mixed> $data
 * @property int $sort
 * @property bool $is_enabled
 */
class SiteBlock extends Model
{
    protected $fillable = [
        'site_page_id',
        'type',
        'data',
        'sort',
        'is_enabled',
    ];

    protected $casts = [
        'data' => 'array',
        'sort' => 'integer',
        'is_enabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        // The database cannot check a JSON payload, so this is where the
        // guarantee actually lives: nothing reaches the table without passing
        // its own type's rules. Enforcing it on the model rather than in each
        // writer means the generator, a seeder, tinker and next slice's editor
        // are all held to the same shape.
        static::saving(function (SiteBlock $block) {
            $block->data = $block->validatedData();
        });
    }

    /**
     * @return BelongsTo<SitePage, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(SitePage::class, 'site_page_id');
    }

    public function definition(): ?BlockDefinition
    {
        return BlockRegistry::find($this->type);
    }

    /** Whether this block has enough content to be worth a band on the page. */
    public function isFilled(): bool
    {
        return $this->definition()?->isFilled($this->data ?? []) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(): array
    {
        $definition = $this->definition();

        if ($definition === null) {
            throw new InvalidArgumentException(
                "[{$this->type}] is not a block type. Known types: ".implode(', ', BlockRegistry::types()).'.'
            );
        }

        $data = $this->data ?? [];

        $validator = Validator::make($data, $definition->rules());

        if ($validator->fails()) {
            throw new InvalidArgumentException(
                "Invalid payload for a [{$this->type}] block: ".implode(' ', $validator->errors()->all())
            );
        }

        return $data;
    }
}
