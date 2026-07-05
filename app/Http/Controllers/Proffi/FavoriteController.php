<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Models\ProffiFavorite;
use App\Models\ProffiTask;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function index(Request $request)
    {
        $taskIds = ProffiFavorite::where('user_id', $request->user()->id)
            ->pluck('task_id')
            ->map(fn ($id) => (string) $id)
            ->values();

        return ['task_ids' => $taskIds];
    }

    public function store(Request $request, ProffiTask $task)
    {
        ProffiFavorite::firstOrCreate([
            'user_id' => $request->user()->id,
            'task_id' => $task->id,
        ]);

        return ['ok' => true, 'task_id' => (string) $task->id];
    }

    public function destroy(Request $request, ProffiTask $task)
    {
        ProffiFavorite::where('user_id', $request->user()->id)
            ->where('task_id', $task->id)
            ->delete();

        return ['ok' => true];
    }
}
