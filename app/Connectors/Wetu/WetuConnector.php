<?php

namespace App\Connectors\Wetu;

use App\Connectors\Contracts\ContentConnector;
use App\Connectors\Wetu\DTOs\PropertyContent;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

class WetuConnector implements ContentConnector
{
    public function __construct(
        private readonly WetuClient $client,
    ) {}

    public function getConnectorType(): string
    {
        return 'wetu';
    }

    public function fetchPropertyContent(string $propertyId): PropertyContent
    {
        try {
            $data = $this->client->get("{$propertyId}/Get", [
                'fields' => 'name,introduction,location,images,keyfeatures,website',
            ]);

            $images = collect($data['Images'] ?? $data['images'] ?? [])
                ->map(fn (array $img) => $img['Url'] ?? $img['url'] ?? null)
                ->filter()
                ->values()
                ->all();

            $highlights = collect($data['KeyFeatures'] ?? $data['key_features'] ?? [])
                ->map(fn (array $f) => $f['Text'] ?? $f['text'] ?? null)
                ->filter()
                ->values()
                ->all();

            $location = $data['Location'] ?? $data['location'] ?? [];

            return new PropertyContent(
                wetuId: $propertyId,
                name: $data['Name'] ?? $data['name'] ?? '',
                description: $data['Introduction'] ?? $data['introduction'] ?? null,
                imageUrls: $images,
                highlights: $highlights,
                region: $location['Country'] ?? $location['Region'] ?? $location['region'] ?? null,
                latitude: isset($location['Latitude']) ? (float) $location['Latitude'] : (isset($location['latitude']) ? (float) $location['latitude'] : null),
                longitude: isset($location['Longitude']) ? (float) $location['Longitude'] : (isset($location['longitude']) ? (float) $location['longitude'] : null),
                website: $data['Website'] ?? $data['website'] ?? null,
                raw: $data,
            );

        } catch (RequestException $e) {
            Log::error('Wetu content fetch failed', [
                'property_id' => $propertyId,
                'status' => $e->response->status(),
                'body' => $e->response->body(),
            ]);

            return PropertyContent::empty($propertyId);
        }
    }
}
