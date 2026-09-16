<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Marvel\Database\Models\Place;

class AdminPlaceController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'published', 'hidden', 'archived'])],
            'search' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = Place::with(['user.profile', 'category', 'work', 'images', 'sourceTask'])
            ->withCount('requestTasks')
            ->latest('id');
        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (!empty($data['search'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $data['search']).'%';
            $query->where(fn ($inner) => $inner
                ->where('title', 'like', $term)
                ->orWhere('description', 'like', $term));
        }

        $places = $query->paginate((int) ($data['per_page'] ?? 50));

        return [
            'data' => collect($places->items())->map(fn (Place $place) => $this->mapPlace($place))->values(),
            'meta' => [
                'current_page' => $places->currentPage(),
                'last_page' => $places->lastPage(),
                'per_page' => $places->perPage(),
                'total' => $places->total(),
            ],
        ];
    }

    public function show(Place $place): array
    {
        return $this->mapPlace($place->load([
            'user.profile', 'category', 'work', 'images', 'sourceTask',
        ])->loadCount('requestTasks'));
    }

    public function update(Request $request, Place $place): array
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['draft', 'published', 'hidden', 'archived'])],
        ]);
        $updates = ['status' => $data['status']];
        if ($data['status'] === 'published' && !$place->published_at) {
            $updates['published_at'] = now();
        }
        $place->update($updates);

        return $this->show($place->fresh());
    }

    public function destroy(Place $place): array
    {
        $place->delete();

        return ['ok' => true];
    }

    private function mapPlace(Place $place): array
    {
        return [
            'id' => (string) $place->id,
            'title' => $place->title,
            'description' => $place->description,
            'status' => $place->status,
            'author' => [
                'id' => (string) $place->user_id,
                'name' => $place->user?->name,
                'phone' => $place->user?->profile?->contact,
            ],
            'category' => $place->category ? [
                'id' => (string) $place->category->id,
                'name' => $place->category->name_ru,
            ] : null,
            'work' => $place->work ? [
                'id' => (int) $place->work->id,
                'title' => $place->work->title,
            ] : null,
            'price' => $place->price,
            'hide_price' => (bool) $place->hide_price,
            'city' => $place->city,
            'lat' => $place->lat,
            'lng' => $place->lng,
            'images' => $place->images->sortBy('sort_order')->map(fn ($image) => [
                'id' => (string) $image->id,
                'url' => $image->image_url,
                'thumbnail' => $image->thumbnail_url,
                'is_cover' => (bool) $image->is_cover,
                'sort_order' => (int) $image->sort_order,
            ])->values(),
            'source_task_id' => $place->source_task_id ? (string) $place->source_task_id : null,
            'source_task_status' => $place->sourceTask?->status,
            'request_conversions_count' => (int) ($place->request_tasks_count ?? 0),
            'published_at' => optional($place->published_at)->toIso8601String(),
            'created_at' => optional($place->created_at)->toIso8601String(),
            'updated_at' => optional($place->updated_at)->toIso8601String(),
        ];
    }
}
