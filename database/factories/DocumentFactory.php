<?php

namespace Database\Factories;

use App\Enums\DocumentKind;
use App\Models\Document;
use App\Models\DocumentCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_category_id' => DocumentCategory::factory(),
            'kind' => DocumentKind::Page,
            'title' => fake()->unique()->sentence(3),
            'description' => fake()->sentence(),
            'body' => '<p>'.fake()->paragraph().'</p>',
            'author_name' => fake()->name(),
        ];
    }

    /** An uploaded file rather than a written page. */
    public function file(string $path = 'documents/example.pdf'): self
    {
        return $this->state(fn (): array => [
            'kind' => DocumentKind::File,
            'body' => null,
            'disk' => Document::DISK,
            'path' => $path,
            'original_name' => basename($path),
            'mime_type' => 'application/pdf',
            'size' => 12_345,
        ]);
    }
}
