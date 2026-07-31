<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Models\AiLearningEvent;
use App\Models\ProffiTask;
use App\Services\AiKnowledge\KnowledgeTextNormalizer;
use Illuminate\Http\Request;

class TaskAiFeedbackController extends Controller
{
    public function store(
        Request $request,
        ProffiTask $task,
        KnowledgeTextNormalizer $normalizer
    ) {
        $user = $request->user();
        $isParticipant = (int) $task->customer_id === (int) $user->id
            || (int) $task->accepted_specialist_id === (int) $user->id;
        $isAdmin = false;
        if (method_exists($user, 'hasAnyPermission')) {
            try {
                $isAdmin = $user->hasAnyPermission(['super_admin', 'super-admin', 'admin']);
            } catch (\Throwable) {
                $isAdmin = false;
            }
        }
        abort_unless($isParticipant || $isAdmin, 403);

        $data = $request->validate([
            'outcome' => ['required', 'in:confirmed,corrected,completed,cancelled'],
            'category_id' => ['nullable', 'string', 'exists:proffi_categories,id'],
            'service_id' => ['nullable', 'integer', 'exists:proffi_works,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $eventType = match ($data['outcome']) {
            'corrected' => 'master_reclassified',
            'completed' => 'task_completed',
            'cancelled' => 'task_cancelled',
            default => 'classification_confirmed',
        };
        $event = AiLearningEvent::create([
            'request_draft_id' => $task->ai_details['request_draft_id'] ?? null,
            'task_id' => $task->id,
            'event_type' => $eventType,
            'before' => [
                'category_id' => $task->category_id,
                'service_id' => $task->work_id,
            ],
            'after' => [
                'category_id' => $data['category_id'] ?? $task->category_id,
                'service_id' => $data['service_id'] ?? $task->work_id,
                'outcome' => $data['outcome'],
            ],
            'redacted_evidence' => $normalizer->redactPii(
                trim($task->description."\n".($data['notes'] ?? ''))
            ),
            'source_actor' => (int) $task->accepted_specialist_id === (int) $user->id ? 'master' : 'customer',
            'weight' => $data['outcome'] === 'corrected' ? 1 : 0.8,
            'status' => 'new',
        ]);

        return response()->json($event, 201);
    }
}
