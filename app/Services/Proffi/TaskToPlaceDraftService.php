<?php

namespace App\Services\Proffi;

use App\Models\ProffiCategory;
use App\Models\ProffiTask;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Place;
use Marvel\Database\Models\User;

class TaskToPlaceDraftService
{
    public function __construct(private readonly PlaceService $places)
    {
    }

    public function create(ProffiTask $task, User $user): Place
    {
        $task = $this->places->authorizedSourceTask($user, (int) $task->id);
        $existing = Place::where('source_task_id', $task->id)
            ->where('user_id', $user->id)
            ->where('status', 'draft')
            ->latest('id')
            ->first();
        if ($existing) {
            return $this->places->detail($existing, (int) $user->id);
        }

        $categoryId = $task->category_id;
        if (!$categoryId && $task->category && ProffiCategory::whereKey($task->category)->exists()) {
            $categoryId = (string) $task->category;
        }
        if (!$categoryId || !$task->work_id) {
            throw ValidationException::withMessages([
                'task' => 'У завершённой заявки не заполнены category/work для создания Place.',
            ]);
        }

        return $this->places->create($user, [
            'title' => mb_substr('Выполненная работа: '.$task->displayTitle(), 0, 255),
            'description' => strip_tags((string) $task->description),
            'category_id' => $categoryId,
            'work_id' => (int) $task->work_id,
            'city' => $task->city,
            'location_id' => $task->location_id,
            'lat' => $task->lat,
            'lng' => $task->lng,
            'price' => null,
            'hide_price' => false,
            'status' => 'draft',
            'source_task_id' => (int) $task->id,
            'images' => [],
        ]);
    }
}
