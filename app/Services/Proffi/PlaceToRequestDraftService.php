<?php

namespace App\Services\Proffi;

use App\Services\AiAssistant\RequestDraftOrchestrator;
use Illuminate\Support\Str;
use Marvel\Database\Models\Place;
use Marvel\Database\Models\User;

class PlaceToRequestDraftService
{
    public function __construct(private readonly RequestDraftOrchestrator $orchestrator)
    {
    }

    public function create(Place $place, User $user, ?string $idempotencyKey = null): array
    {
        abort_unless($place->status === 'published', 404);
        $place->loadMissing(['images', 'category', 'work']);

        $title = trim((string) $place->title);
        $question = "Вы хотите заказать работу, похожую на «{$title}». Расскажите, что хотите изменить или сделать иначе?";

        return $this->orchestrator->create([
            'initial_text' => 'Заявка на работу по примеру Place «'.$title.'»',
            'idempotency_key' => $idempotencyKey ?: 'place-'.$place->id.'-'.Str::uuid(),
            '_source_place_id' => $place->id,
            '_preset_category_id' => $place->category_id,
            '_preset_service_id' => $place->work_id,
            '_skip_initial_inference' => true,
            '_initial_assistant_text' => $question,
            '_source_context' => [
                'id' => (int) $place->id,
                'title' => $place->title,
                'description' => $place->description,
                'category_id' => $place->category_id,
                'work_id' => $place->work_id ? (int) $place->work_id : null,
                'price' => $place->hide_price ? null : $place->price,
                'price_hidden' => (bool) $place->hide_price,
                'photos' => $place->images
                    ->sortBy([['is_cover', 'desc'], ['sort_order', 'asc'], ['id', 'asc']])
                    ->map(fn ($image) => [
                        'id' => (int) $image->id,
                        'url' => $image->image_url,
                        'thumbnail' => $image->thumbnail_url,
                    ])->values()->all(),
                'metadata' => [
                    'city' => $place->city,
                    'duration_days' => $place->duration_days,
                ],
                'catalog_locked' => true,
                'usage' => 'reference_only',
            ],
        ], (int) $user->id);
    }
}
