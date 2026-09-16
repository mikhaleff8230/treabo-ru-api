<?php

namespace App\Services\Proffi;

use App\Models\ProffiApplication;
use App\Models\ProffiTask;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Place;
use Marvel\Database\Models\PlaceImage;
use Marvel\Database\Models\PlaceWishlist;
use Marvel\Database\Models\User;

class PlaceService
{
    private const STATUS_TRANSITIONS = [
        'draft' => ['draft', 'published', 'archived'],
        'published' => ['published', 'hidden', 'archived'],
        'hidden' => ['hidden', 'published', 'archived'],
        'archived' => ['archived', 'draft'],
    ];

    public function publicList(array $filters, ?int $viewerId = null): LengthAwarePaginator
    {
        return $this->paginate($filters, $viewerId, true);
    }

    public function mine(array $filters, int $userId): LengthAwarePaginator
    {
        $filters['author'] = $userId;

        return $this->paginate($filters, $userId, false);
    }

    public function byUser(array $filters, int $userId, ?int $viewerId = null): LengthAwarePaginator
    {
        $filters['author'] = $userId;

        return $this->paginate($filters, $viewerId, true);
    }

    public function detail(Place $place, ?int $viewerId = null): Place
    {
        if ($place->status !== 'published' && (int) $place->user_id !== (int) $viewerId) {
            abort(404);
        }

        $place->load($this->relations(true));
        $place->loadCount(['wishlists as favorites_count', 'likes as likes_count']);
        $place->setAttribute(
            'is_favorite',
            $viewerId
                ? PlaceWishlist::where('place_id', $place->id)->where('user_id', $viewerId)->exists()
                : false
        );

        return $place;
    }

    public function create(User $user, array $data): Place
    {
        $sourceTask = !empty($data['source_task_id'])
            ? $this->authorizedSourceTask($user, (int) $data['source_task_id'])
            : null;

        return DB::transaction(function () use ($user, $data, $sourceTask) {
            $images = $data['images'] ?? [];
            unset($data['images']);
            $status = $data['status'] ?? 'published';
            $attributes = Arr::only($data, [
                'title', 'description', 'category_id', 'work_id', 'price', 'hide_price',
                'city', 'location_id', 'lat', 'lng', 'duration_days', 'source_task_id',
            ]);
            $attributes['user_id'] = $user->id;
            $attributes['status'] = $status;
            $attributes['hide_price'] = (bool) ($attributes['hide_price'] ?? false);
            $attributes['published_at'] = $status === 'published' ? now() : null;

            $place = Place::create($attributes);
            $this->syncImages($place, $images, $user, $sourceTask);

            return $this->detail($place->fresh(), (int) $user->id);
        });
    }

    public function update(Place $place, User $user, array $data): Place
    {
        return DB::transaction(function () use ($place, $user, $data) {
            $imagesProvided = array_key_exists('images', $data);
            $images = $data['images'] ?? [];
            unset($data['images']);

            if (array_key_exists('source_task_id', $data)) {
                $newSourceTaskId = $data['source_task_id'] ? (int) $data['source_task_id'] : null;
                if ($place->source_task_id && $newSourceTaskId !== (int) $place->source_task_id) {
                    throw ValidationException::withMessages([
                        'source_task_id' => 'Источник опубликованной работы нельзя изменить.',
                    ]);
                }
                if ($newSourceTaskId) {
                    $this->authorizedSourceTask($user, $newSourceTaskId);
                }
            }

            if (isset($data['status']) && $data['status'] !== $place->status) {
                $allowed = self::STATUS_TRANSITIONS[$place->status] ?? [];
                if (!in_array($data['status'], $allowed, true)) {
                    throw ValidationException::withMessages([
                        'status' => "Переход {$place->status} → {$data['status']} запрещён.",
                    ]);
                }
                if ($data['status'] === 'published' && !$place->published_at) {
                    $data['published_at'] = now();
                }
            }

            $place->update(Arr::only($data, [
                'title', 'description', 'category_id', 'work_id', 'price', 'hide_price',
                'city', 'location_id', 'lat', 'lng', 'duration_days', 'status',
                'published_at', 'source_task_id',
            ]));

            if ($imagesProvided) {
                $sourceTask = $place->source_task_id
                    ? $this->authorizedSourceTask($user, (int) $place->source_task_id)
                    : null;
                $this->syncImages($place, $images, $user, $sourceTask);
            }

            return $this->detail($place->fresh(), (int) $user->id);
        });
    }

    public function delete(Place $place): void
    {
        $place->delete();
    }

    public function favorite(Place $place, int $userId): void
    {
        abort_unless($place->status === 'published', 404);
        PlaceWishlist::firstOrCreate(['place_id' => $place->id, 'user_id' => $userId]);
    }

    public function unfavorite(Place $place, int $userId): void
    {
        PlaceWishlist::where('place_id', $place->id)->where('user_id', $userId)->delete();
    }

    public function authorizedSourceTask(User $user, int $taskId): ProffiTask
    {
        $task = ProffiTask::findOrFail($taskId);
        if (!in_array($task->status, ['done', 'completed'], true)) {
            throw ValidationException::withMessages([
                'source_task_id' => 'Place можно создать только из завершённой заявки.',
            ]);
        }

        $isAcceptedSpecialist = (int) $task->accepted_specialist_id === (int) $user->id;
        $hasAcceptedApplication = ProffiApplication::where('task_id', $task->id)
            ->where('specialist_id', $user->id)
            ->whereIn('status', ['accepted', 'completed'])
            ->exists();

        abort_unless($isAcceptedSpecialist || $hasAcceptedApplication, 403, 'Forbidden');

        return $task;
    }

    private function paginate(array $filters, ?int $viewerId, bool $publicOnly): LengthAwarePaginator
    {
        $query = $this->baseQuery($viewerId);
        if ($publicOnly) {
            $query->where('places.status', 'published');
        }

        $categoryId = $filters['category_id'] ?? $filters['category'] ?? null;
        if ($categoryId) {
            $query->where('places.category_id', $categoryId);
        }
        $workId = $filters['work_id'] ?? $filters['work'] ?? null;
        if ($workId) {
            $query->where('places.work_id', $workId);
        }
        if (!empty($filters['city'])) {
            $query->where('places.city', 'like', '%'.$this->escapeLike($filters['city']).'%');
        }
        if (!empty($filters['author'])) {
            $query->where('places.user_id', (int) $filters['author']);
        }
        if (!empty($filters['search'])) {
            $term = '%'.$this->escapeLike($filters['search']).'%';
            $query->where(fn (Builder $inner) => $inner
                ->where('places.title', 'like', $term)
                ->orWhere('places.description', 'like', $term));
        }
        if (!empty($filters['with_photo'])) {
            $query->whereHas('images');
        }
        if (array_key_exists('price_from', $filters) || array_key_exists('price_to', $filters)) {
            $query->where('places.hide_price', false)->whereNotNull('places.price');
            if (array_key_exists('price_from', $filters)) {
                $query->where('places.price', '>=', (int) $filters['price_from']);
            }
            if (array_key_exists('price_to', $filters)) {
                $query->where('places.price', '<=', (int) $filters['price_to']);
            }
        }
        if (!empty($filters['favorites'])) {
            abort_unless($viewerId, 401, 'Authorization required for favorites filter');
            $query->whereHas('wishlists', fn (Builder $favorite) => $favorite->where('user_id', $viewerId));
        }

        $hasViewport = collect(['sw_lat', 'sw_lng', 'ne_lat', 'ne_lng'])
            ->every(fn (string $key) => array_key_exists($key, $filters));
        if ($hasViewport) {
            $query->whereNotNull('places.lat')->whereNotNull('places.lng')
                ->whereBetween('places.lat', [
                    min((float) $filters['sw_lat'], (float) $filters['ne_lat']),
                    max((float) $filters['sw_lat'], (float) $filters['ne_lat']),
                ])
                ->whereBetween('places.lng', [
                    min((float) $filters['sw_lng'], (float) $filters['ne_lng']),
                    max((float) $filters['sw_lng'], (float) $filters['ne_lng']),
                ]);
        }

        $sort = $filters['sort'] ?? 'new';
        if ($sort === 'nearby' && isset($filters['lat'], $filters['lng'])) {
            $lat = (float) $filters['lat'];
            $lng = (float) $filters['lng'];
            $query->whereNotNull('places.lat')->whereNotNull('places.lng')
                ->orderByRaw(
                    '((places.lat - ?) * (places.lat - ?) + (places.lng - ?) * (places.lng - ?)) asc',
                    [$lat, $lat, $lng, $lng]
                );
        } elseif ($sort === 'popular') {
            $query->orderByDesc('favorites_count')->orderByDesc('likes_count');
        } else {
            $query->orderByDesc('places.published_at')->orderByDesc('places.id');
        }

        return $query->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();
    }

    private function baseQuery(?int $viewerId): Builder
    {
        $query = Place::query()
            ->select('places.*')
            ->with($this->relations())
            ->withCount(['wishlists as favorites_count', 'likes as likes_count']);

        if ($viewerId) {
            $query->withExists([
                'wishlists as is_favorite' => fn (Builder $favorite) => $favorite->where('user_id', $viewerId),
            ]);
        } else {
            $query->selectRaw('0 as is_favorite');
        }

        return $query;
    }

    private function relations(bool $detail = false): array
    {
        $relations = [
            'images' => fn ($query) => $query->orderByDesc('is_cover')->orderBy('sort_order')->orderBy('id'),
            'category',
            'work',
            'location',
            'user' => function ($query) {
                $query->with(['profile', 'proffiPresence'])
                    ->withCount('publishedPlaces')
                    ->withCount('proffiReviews')
                    ->withAvg('proffiReviews', 'rating');
            },
        ];

        if ($detail) {
            $relations['user.recentProffiReviews'] = fn ($query) => $query
                ->with('customer.profile')->limit(3);
        }

        return $relations;
    }

    private function syncImages(Place $place, array $images, User $user, ?ProffiTask $sourceTask): void
    {
        foreach ($images as $image) {
            $this->assertMediaAllowed((string) ($image['url'] ?? ''), $user, $sourceTask);
        }

        $place->images()->delete();
        $coverIndex = collect($images)->search(fn ($image) => !empty($image['is_cover']));
        $coverIndex = $coverIndex === false ? 0 : $coverIndex;

        foreach (array_values($images) as $index => $image) {
            PlaceImage::create([
                'place_id' => $place->id,
                'url' => $image['url'],
                'thumbnail_url' => $image['thumbnail_url'] ?? null,
                'sort_order' => $image['sort_order'] ?? $index,
                'is_cover' => $index === $coverIndex,
                'width' => $image['width'] ?? null,
                'height' => $image['height'] ?? null,
                'file_size' => $image['file_size'] ?? null,
                'mime_type' => $image['mime_type'] ?? null,
            ]);
        }
    }

    private function assertMediaAllowed(string $url, User $user, ?ProffiTask $sourceTask): void
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        $isProffiUpload = str_contains(str_replace('\\', '/', $path), '/api/proffi/files/proffi/')
            || str_starts_with(ltrim($path, '/'), 'proffi/');
        $belongsToOwnPlace = PlaceImage::where('url', $url)
            ->whereHas('place', fn (Builder $query) => $query->where('user_id', $user->id))
            ->exists();
        $belongsToSourceTask = $sourceTask && collect($sourceTask->photos ?? [])->contains(function ($photo) use ($url) {
            $photoUrl = is_array($photo)
                ? ($photo['url'] ?? $photo['original'] ?? $photo['thumbnail'] ?? null)
                : $photo;

            return $photoUrl === $url;
        });

        if (!$isProffiUpload && !$belongsToOwnPlace && !$belongsToSourceTask) {
            throw ValidationException::withMessages([
                'images' => 'Используйте файл, загруженный через Proffi uploads, или разрешённое медиа заявки.',
            ]);
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($value));
    }
}
