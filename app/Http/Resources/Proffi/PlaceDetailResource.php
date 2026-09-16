<?php

namespace App\Http\Resources\Proffi;

use Illuminate\Http\Request;

class PlaceDetailResource extends PlaceListResource
{
    public function toArray(Request $request): array
    {
        $base = parent::toArray($request);
        $author = $this->user;
        $reviews = $author?->relationLoaded('recentProffiReviews')
            ? $author->recentProffiReviews->take(3)
            : collect();

        return [
            ...$base,
            'description' => $this->description,
            'gallery' => $this->images->map(fn ($image) => [
                ...$this->imagePayload($image),
                'width' => $image->width ? (int) $image->width : null,
                'height' => $image->height ? (int) $image->height : null,
                'mime_type' => $image->mime_type,
            ])->values(),
            'duration_days' => $this->duration_days ? (int) $this->duration_days : null,
            'location' => [
                'id' => $this->location_id ? (int) $this->location_id : null,
                'city' => $this->city,
                'name' => $this->location?->name,
                'region' => $this->location?->region,
                'lat' => $this->lat !== null ? (float) $this->lat : null,
                'lng' => $this->lng !== null ? (float) $this->lng : null,
            ],
            'author_details' => $author ? [
                'id' => (string) $author->id,
                'name' => $author->name ?? '',
                'avatar' => $this->avatarUrl($author->profile?->avatar),
                'bio' => $author->profile?->bio,
                'city' => $author->profile?->proffi_city,
                'online' => $this->isOnline($author->proffiPresence),
            ] : null,
            'reviews_summary' => [
                'rating' => $author?->proffi_reviews_avg_rating !== null
                    ? round((float) $author->proffi_reviews_avg_rating, 1)
                    : 0.0,
                'count' => (int) ($author?->proffi_reviews_count ?? 0),
                'reviews' => $reviews->map(fn ($review) => [
                    'id' => (string) $review->id,
                    'rating' => (int) $review->rating,
                    'comment' => $review->comment,
                    'customer' => [
                        'id' => (string) $review->customer_id,
                        'name' => $review->customer?->name ?? '',
                        'avatar' => $this->avatarUrl($review->customer?->profile?->avatar),
                    ],
                    'created_at' => optional($review->created_at)->toIso8601String(),
                ])->values(),
            ],
            'source' => $this->source_task_id ? ['type' => 'task'] : null,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
