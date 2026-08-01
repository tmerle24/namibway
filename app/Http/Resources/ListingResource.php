<?php

namespace App\Http\Resources;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Listing
 */
class ListingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'short_description' => $this->short_description,
            'image' => $this->image ? Controller::resolveMediaUrl($this->image) : null,
            'gallery' => collect($this->gallery ?? [])->map(fn (string $path) => Controller::resolveMediaUrl($path))->values(),
            'region' => $this->region,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'price_from' => $this->price_from !== null ? (float) $this->price_from : null,
            'price_currency' => $this->price_currency,
            'rating' => $this->rating !== null ? (float) $this->rating : null,
            'rating_count' => $this->rating_count,
            'accepts_inquiries' => $this->accepts_inquiries,
            'booking_mode' => $this->partner?->connector_type?->isBookingConnector()
                ? 'connector'
                : 'inquiry',
        ];
    }
}
