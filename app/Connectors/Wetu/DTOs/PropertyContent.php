<?php

namespace App\Connectors\Wetu\DTOs;

final class PropertyContent
{
    /**
     * @param  string[]  $imageUrls
     * @param  string[]  $highlights
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $wetuId,
        public readonly string $name,
        public readonly ?string $description,
        public readonly array $imageUrls,
        public readonly array $highlights,
        public readonly ?string $region,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly ?string $website,
        public readonly array $raw = [],
    ) {}

    public static function empty(string $wetuId): self
    {
        return new self(
            wetuId: $wetuId,
            name: '',
            description: null,
            imageUrls: [],
            highlights: [],
            region: null,
            latitude: null,
            longitude: null,
            website: null,
        );
    }
}
