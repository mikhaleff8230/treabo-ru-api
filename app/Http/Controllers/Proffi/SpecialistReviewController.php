<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Models\ProffiApplication;
use App\Models\ProffiReview;
use App\Models\ProffiTask;
use Illuminate\Http\Request;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;

class SpecialistReviewController extends Controller
{
    public function index(User $user)
    {
        if (!$user->getPermissionNames()->contains(Permission::STORE_OWNER)) {
            return response()->json(['detail' => 'Specialist not found'], 404);
        }

        $reviews = ProffiReview::with(['customer.profile', 'task'])
            ->where('specialist_id', $user->id)
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (ProffiReview $review) => $this->mapReview($review))
            ->values();

        return response()->json([
            'rating' => $reviews->count() ? round((float) $reviews->avg('rating'), 1) : 0.0,
            'reviews_count' => $reviews->count(),
            'data' => $reviews,
        ]);
    }

    public function store(Request $request, User $user)
    {
        if (!$user->getPermissionNames()->contains(Permission::STORE_OWNER)) {
            return response()->json(['detail' => 'Specialist not found'], 404);
        }

        $data = $request->validate([
            'task_id' => ['required', 'integer', 'exists:proffi_tasks,id'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $task = ProffiTask::findOrFail($data['task_id']);
        if ((int) $task->customer_id !== (int) $request->user()->id) {
            return response()->json(['detail' => 'Only the customer can review this specialist'], 403);
        }

        $hasAcceptedApplication = ProffiApplication::where('task_id', $task->id)
            ->where('specialist_id', $user->id)
            ->whereIn('status', ['accepted', 'completed'])
            ->exists();

        if ((int) ($task->accepted_specialist_id ?? 0) !== (int) $user->id && !$hasAcceptedApplication) {
            return response()->json(['detail' => 'Review is available only after choosing this specialist'], 400);
        }

        $review = ProffiReview::updateOrCreate(
            [
                'task_id' => $task->id,
                'customer_id' => $request->user()->id,
                'specialist_id' => $user->id,
            ],
            [
                'rating' => $data['rating'],
                'comment' => $data['comment'] ?? null,
            ]
        );

        return response()->json($this->mapReview($review->load(['customer.profile', 'task'])));
    }

    private function mapReview(ProffiReview $review): array
    {
        return [
            'id' => (string) $review->id,
            'task_id' => $review->task_id ? (string) $review->task_id : null,
            'task_title' => $review->task?->title,
            'specialist_id' => (string) $review->specialist_id,
            'customer_id' => (string) $review->customer_id,
            'customer_name' => $review->customer?->name,
            'rating' => (int) $review->rating,
            'comment' => $review->comment,
            'created_at' => optional($review->created_at)->toIso8601String(),
        ];
    }
}
