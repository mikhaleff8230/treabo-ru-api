<?php

namespace App\Http\Resources\Proffi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Marvel\Database\Models\PlaceImage;

class PlaceListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cover = $this->images->first();
        $author = $this->user;
        $rating = $author?->proffi_reviews_avg_rating;
        $reviewsCount = (int) ($author?->proffi_reviews_count ?? 0);
        $authorPlacesCount = (int) ($author?->published_places_count ?? 0);

        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'cover' => $cover ? $this->imagePayload($cover) : null,
            'price' => $this->hide_price || $this->price === null ? null : (int) $this->price,
            'hide_price' => (bool) $this->hide_price,
            'price_label' => $this->priceLabel(),
            'city' => $this->city,
            'lat' => $this->lat !== null ? (float) $this->lat : null,
            'lng' => $this->lng !== null ? (float) $this->lng : null,
            'distance' => $this->distance($request),
            'category' => $this->category ? [
                'id' => (string) $this->category->id,
                'name' => $this->category->name_ru,
                'icon' => $this->category->icon,
            ] : null,
            'work' => $this->work ? [
                'id' => (int) $this->work->id,
                'title' => $this->work->title,
                'slug' => $this->work->slug,
            ] : null,
            'author' => $author ? [
                'id' => (string) $author->id,
                'name' => $author->name ?? '',
                'avatar' => $this->avatarUrl($author->profile?->avatar),
                'rating' => $rating !== null ? round((float) $rating, 1) : 0.0,
                'reviews_count' => $reviewsCount,
                'online' => $this->isOnline($author->proffiPresence),
            ] : null,
            'works_count' => $authorPlacesCount,
            'author_places_count' => $authorPlacesCount,
            'favorites_count' => (int) ($this->favorites_count ?? 0),
            'is_favorite' => (bool) ($this->is_favorite ?? false),
            'status' => $this->when(
                $request->user() && (int) $request->user()->id === (int) $this->user_id,
                $this->status
            ),
            'published_at' => optional($this->published_at)->toIso8601String(),
        ];
    }

    protected function imagePayload(PlaceImage $image): array
    {
        return [
            'id' => (string) $image->id,
            'url' => $image->image_url,
            'thumbnail' => $image->thumbnail_url,
            'is_cover' => (bool) $image->is_cover,
            'sort_order' => (int) $image->sort_order,
        ];
    }

    protected function avatarUrl(mixed $avatar): ?string
    {
        if (is_array($avatar)) {
            return $avatar['original'] ?? $avatar['thumbnail'] ?? null;
        }
        if (is_string($avatar)) {
            $decoded = json_decode($avatar, true);
            if (is_array($decoded)) {
                return $decoded['original'] ?? $decoded['thumbnail'] ?? null;
            }

            return $avatar;
        }

        return null;
    }

    protected function isOnline($presence): bool
    {
        return (bool) ($presence?->is_online
            && $presence?->last_seen_at
            && $presence->last_seen_at->greaterThan(now()->subMinutes(2)));
    }

    private function priceLabel(): string
    {
        if ($this->hide_price || $this->price === null) {
            return 'Цена по запросу';
        }

        return number_format((int) $this->price, 0, ',', ' ').' ₽';
    }

    private function distance(Request $request): ?float
    {
        if (!$request->filled('lat') || !$request->filled('lng') || $this->lat === null || $this->lng === null) {
            return null;
        }

        $lat1 = deg2rad((float) $request->query('lat'));
        $lng1 = deg2rad((float) $request->query('lng'));
        $lat2 = deg2rad((float) $this->lat);
        $lng2 = deg2rad((float) $this->lng);
        $deltaLat = $lat2 - $lat1;
        $deltaLng = $lng2 - $lng1;
        $a = sin($deltaLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($deltaLng / 2) ** 2;

        return round(6371 * 2 * atan2(sqrt($a), sqrt(1 - $a)), 1);
    }
}
