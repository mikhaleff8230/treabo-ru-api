<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Models\AiEvaluationRun;
use App\Models\AiInvocation;
use App\Models\AiKnowledgeVersion;
use App\Models\AiLearningEvent;
use App\Models\AiTrainingExample;
use App\Models\RequestDraft;
use App\Services\AiKnowledge\LearningOperationsService;
use App\Services\AiKnowledge\OfflineEvaluationService;
use Illuminate\Http\Request;

class AiOperationsController extends Controller
{
    public function analytics(Request $request)
    {
        $days = min(365, max(1, (int) $request->query('days', 30)));
        $since = now()->subDays($days);
        $drafts = RequestDraft::where('created_at', '>=', $since);
        $total = (clone $drafts)->count();
        $published = (clone $drafts)->where('status', 'published')->count();
        $manual = (clone $drafts)->where('status', 'manual_selection')->count();
        $corrections = AiLearningEvent::where('created_at', '>=', $since)
            ->where('event_type', 'classification_corrected')->count();
        $invocations = AiInvocation::where('created_at', '>=', $since)->where('status', 'succeeded');
        $totalCost = (float) (clone $invocations)->sum('cost_usd');
        $draftCost = (float) (clone $drafts)->sum('estimated_cost_usd');
        $averageCost = $total ? $draftCost / $total : 0;

        return [
            'period_days' => $days,
            'drafts_total' => $total,
            'drafts_published' => $published,
            'completion_rate' => $total ? round($published / $total, 4) : 0,
            'manual_fallback_rate' => $total ? round($manual / $total, 4) : 0,
            'correction_rate' => $total ? round($corrections / $total, 4) : 0,
            'average_questions' => round((float) (clone $drafts)->avg('questions_asked_count'), 2),
            'average_ai_calls' => round((float) (clone $drafts)->avg('ai_calls_count'), 2),
            'average_cost_usd' => round($averageCost, 6),
            'total_cost_usd' => round($totalCost, 6),
            'projected_cost_1000_usd' => round($averageCost * 1000, 2),
            'average_latency_ms' => (int) round((float) (clone $invocations)->avg('latency_ms')),
            'input_tokens' => (int) (clone $invocations)->sum('input_tokens'),
            'cached_input_tokens' => (int) (clone $invocations)->sum('cached_input_tokens'),
            'output_tokens' => (int) (clone $invocations)->sum('output_tokens'),
            'unrecognized_count' => AiLearningEvent::where('created_at', '>=', $since)
                ->where('event_type', 'unrecognized_text')->count(),
            'multi_intent_count' => AiLearningEvent::where('created_at', '>=', $since)
                ->where('event_type', 'multi_intent_split')->count(),
            'learning_queue' => AiLearningEvent::where('status', 'new')->count(),
            'training_examples' => AiTrainingExample::count(),
        ];
    }

    public function learningEvents(Request $request)
    {
        return AiLearningEvent::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('event_type'), fn ($query) => $query->where('event_type', $request->string('event_type')))
            ->latest('id')
            ->paginate(min(100, max(1, (int) $request->query('limit', 50))));
    }

    public function promote(
        AiLearningEvent $event,
        LearningOperationsService $service
    ) {
        try {
            return $service->promote($event);
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function reviewEvent(Request $request, AiLearningEvent $event)
    {
        $data = $request->validate(['status' => ['required', 'in:quarantined,ignored,new']]);
        $event->update(['status' => $data['status']]);

        return $event->fresh();
    }

    public function evaluations()
    {
        return AiEvaluationRun::with(['version', 'baseline'])->latest('id')->limit(100)->get();
    }

    public function runEvaluation(
        AiKnowledgeVersion $version,
        OfflineEvaluationService $service
    ) {
        return response()->json($service->run($version), 201);
    }
}
