<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Jobs\AiKnowledge\AnalyzeKnowledgeImport;
use App\Models\AiKnowledgeImport;
use App\Models\AiKnowledgeProposal;
use App\Models\AiKnowledgeProposalQuestion;
use App\Models\AiKnowledgeTerm;
use App\Models\AiKnowledgeVersion;
use App\Services\AiKnowledge\KnowledgeEvaluationService;
use App\Services\AiKnowledge\KnowledgeImportService;
use App\Services\AiKnowledge\KnowledgeProposalApplier;
use App\Services\AiKnowledge\KnowledgeRetrievalService;
use App\Services\AiKnowledge\KnowledgeVersionManager;
use App\Services\AiKnowledge\KnowledgeVersionPublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AiKnowledgeLabController extends Controller
{
    public function imports(Request $request)
    {
        return AiKnowledgeImport::query()
            ->with('source')
            ->withCount('proposals')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest('id')
            ->paginate(min(100, max(1, (int) $request->query('limit', 25))));
    }

    public function storeImport(Request $request, KnowledgeImportService $service)
    {
        $data = $request->validate([
            'source_name' => ['nullable', 'string', 'max:255'],
            'source_type' => [
                'nullable',
                Rule::in(['manual_text', 'wordstat', 'csv', 'xlsx', 'requests', 'operator', 'master', 'external']),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'text' => ['required', 'string', 'max:5000000'],
            'mode' => ['nullable', Rule::in(['terms', 'catalog', 'questions', 'full_analysis'])],
            'category_hint' => ['nullable', 'string', 'max:128'],
            'region' => ['nullable', 'string', 'max:128'],
            'trust_level' => ['nullable', 'integer', 'min:0', 'max:100'],
            'cost_limit_usd' => ['nullable', 'numeric', 'min:0.01', 'max:1000'],
            'auto_analyze' => ['nullable', 'boolean'],
        ]);

        try {
            $import = $service->createTextImport($data, $request->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['text' => [$e->getMessage()]],
            ], 422);
        }

        if ($data['auto_analyze'] ?? false) {
            $this->dispatchAnalysis($import);
        }

        return response()->json($this->importPayload($import), 201);
    }

    public function showImport(AiKnowledgeImport $import)
    {
        $import->load('source')->loadCount('proposals');

        return $this->importPayload($import, true);
    }

    public function analyze(AiKnowledgeImport $import)
    {
        if (in_array($import->status, ['queued', 'analyzing'], true)) {
            return response()->json(['message' => 'Анализ уже запущен.'], 409);
        }
        if ($import->status === 'cancelled') {
            return response()->json(['message' => 'Отменённый импорт нельзя запустить.'], 422);
        }

        $this->dispatchAnalysis($import);

        return response()->json($this->importPayload($import->fresh()), 202);
    }

    public function cancel(AiKnowledgeImport $import)
    {
        if (in_array($import->status, ['completed', 'cancelled'], true)) {
            return $this->importPayload($import);
        }

        $import->update([
            'status' => 'cancelled',
            'finished_at' => now(),
        ]);

        return $this->importPayload($import->fresh());
    }

    public function proposals(Request $request)
    {
        $excludedStatuses = collect(explode(',', (string) $request->query('exclude_statuses')))
            ->filter(fn ($status) => in_array($status, ['rejected', 'superseded', 'published'], true))
            ->values()
            ->all();

        return AiKnowledgeProposal::query()
            ->with('import.source')
            ->when($request->filled('import_id'), fn ($query) => $query->where('import_id', $request->integer('import_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('proposal_type'), fn ($query) => $query->where('proposal_type', $request->string('proposal_type')))
            ->when($excludedStatuses, fn ($query) => $query->whereNotIn('status', $excludedStatuses))
            ->latest('id')
            ->paginate(min(100, max(1, (int) $request->query('limit', 50))));
    }

    public function showProposal(AiKnowledgeProposal $proposal)
    {
        return $proposal->load('import.source');
    }

    public function updateProposal(Request $request, AiKnowledgeProposal $proposal)
    {
        if (in_array($proposal->status, ['published', 'superseded'], true)) {
            return response()->json(['message' => 'Опубликованное предложение нельзя изменить.'], 409);
        }

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'payload' => ['sometimes', 'required', 'array'],
            'review_note' => ['nullable', 'string', 'max:4000'],
        ]);

        $proposal->update($data);

        return $proposal->fresh();
    }

    public function acceptProposal(
        Request $request,
        AiKnowledgeProposal $proposal,
        KnowledgeProposalApplier $applier
    )
    {
        if ($proposal->status === 'published') {
            return response()->json(['message' => 'Предложение уже опубликовано.'], 409);
        }

        $data = $request->validate([
            'review_note' => ['nullable', 'string', 'max:4000'],
        ]);

        DB::transaction(function () use ($proposal, $request, $data, $applier) {
            $applier->applyToDraft($proposal);
            $proposal->update([
                'status' => 'accepted',
                'reviewed_by' => $request->user()?->id,
                'review_note' => $data['review_note'] ?? null,
                'reviewed_at' => now(),
            ]);
        });

        return $proposal->fresh();
    }

    public function rejectProposal(Request $request, AiKnowledgeProposal $proposal)
    {
        $data = $request->validate([
            'review_note' => ['required', 'string', 'max:4000'],
        ]);

        $proposal->update([
            'status' => 'rejected',
            'reviewed_by' => $request->user()?->id,
            'review_note' => $data['review_note'],
            'reviewed_at' => now(),
        ]);

        return $proposal->fresh();
    }

    public function bulkReview(Request $request, KnowledgeProposalApplier $applier)
    {
        $data = $request->validate([
            'proposal_ids' => ['required', 'array', 'min:1', 'max:200'],
            'proposal_ids.*' => ['integer', 'distinct', 'exists:ai_knowledge_proposals,id'],
            'action' => ['required', Rule::in(['accept', 'reject'])],
            'review_note' => [
                Rule::requiredIf(fn () => $request->input('action') === 'reject'),
                'nullable', 'string', 'max:4000',
            ],
        ]);

        $proposals = AiKnowledgeProposal::whereIn('id', $data['proposal_ids'])->orderBy('id')->get();
        DB::transaction(function () use ($proposals, $data, $request, $applier) {
            foreach ($proposals as $proposal) {
                if ($data['action'] === 'accept') {
                    $applier->applyToDraft($proposal);
                }
                $proposal->update([
                    'status' => $data['action'] === 'accept' ? 'accepted' : 'rejected',
                    'reviewed_by' => $request->user()?->id,
                    'review_note' => $data['review_note'] ?? null,
                    'reviewed_at' => now(),
                ]);
            }
        });

        return response()->json(['reviewed' => $proposals->count()]);
    }

    public function answerProposal(Request $request, AiKnowledgeProposal $proposal)
    {
        $data = $request->validate([
            'question_id' => ['required', 'integer', 'exists:ai_knowledge_proposal_questions,id'],
            'answer' => ['required'],
        ]);
        $question = AiKnowledgeProposalQuestion::query()
            ->where('proposal_id', $proposal->id)
            ->findOrFail($data['question_id']);
        $question->update([
            'answer' => ['value' => $data['answer']],
            'answered_by' => $request->user()?->id,
            'answered_at' => now(),
        ]);
        if ($proposal->questions()->whereNull('answered_at')->doesntExist()) {
            $proposal->update(['status' => 'in_review']);
        }

        return $question->fresh();
    }

    public function terms(Request $request)
    {
        $versionId = $request->integer('version_id') ?: AiKnowledgeVersion::query()
            ->where('status', 'published')
            ->latest('id')
            ->value('id');

        return AiKnowledgeTerm::query()
            ->with(['variants', 'links'])
            ->when($versionId, fn ($query) => $query->where('knowledge_version_id', $versionId))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('term_type'), fn ($query) => $query->where('term_type', $request->string('term_type')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%'.$request->string('search')->trim().'%';
                $query->where(function ($nested) use ($search) {
                    $nested->where('display_text', 'like', $search)
                        ->orWhere('normalized_text', 'like', $search)
                        ->orWhereHas('variants', fn ($variant) => $variant->where('normalized_text', 'like', $search));
                });
            })
            ->orderByDesc('frequency')
            ->orderBy('display_text')
            ->paginate(min(100, max(1, (int) $request->query('limit', 50))));
    }

    public function versions()
    {
        return AiKnowledgeVersion::query()
            ->withCount(['terms', 'documents', 'proposals'])
            ->latest('id')
            ->get();
    }

    public function createVersion(Request $request, KnowledgeVersionManager $manager)
    {
        $data = $request->validate([
            'version' => ['nullable', 'string', 'max:64', 'unique:ai_knowledge_versions,version'],
        ]);

        return response()->json($manager->createDraft($data['version'] ?? null), 201);
    }

    public function evaluateVersion(AiKnowledgeVersion $version, KnowledgeEvaluationService $service)
    {
        return response()->json($service->evaluate($version), 201);
    }

    public function publishVersion(
        Request $request,
        AiKnowledgeVersion $version,
        KnowledgeEvaluationService $evaluation,
        KnowledgeVersionPublisher $publisher
    ) {
        $run = $evaluation->evaluate($version);
        if ($run->status !== 'passed') {
            return response()->json([
                'message' => 'Версия не прошла обязательные проверки.',
                'evaluation' => $run,
            ], 422);
        }

        try {
            return $publisher->publish($version->fresh(), $request->user()?->id);
        } catch (\DomainException $e) {
            $version->update(['status' => 'draft']);
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $version->update(['status' => 'draft']);
            report($e);

            return response()->json([
                'message' => 'Публикация не выполнена. Версия возвращена в черновик.',
            ], 500);
        }
    }

    public function rollbackVersion(
        Request $request,
        AiKnowledgeVersion $version,
        KnowledgeVersionPublisher $publisher
    ) {
        try {
            return $publisher->rollback($version, $request->user()?->id);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function retrieve(Request $request, KnowledgeRetrievalService $service)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:4000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:30'],
            'include_draft' => ['nullable', 'boolean'],
        ]);

        return $service->retrieve(
            $data['text'],
            $data['limit'] ?? 10,
            !($data['include_draft'] ?? false)
        );
    }

    private function dispatchAnalysis(AiKnowledgeImport $import): void
    {
        $import->update([
            'status' => 'queued',
            'progress' => max(10, $import->progress),
            'error' => null,
            'finished_at' => null,
        ]);
        AnalyzeKnowledgeImport::dispatch($import->id);
    }

    private function importPayload(AiKnowledgeImport $import, bool $withRows = false): array
    {
        $import->loadMissing('source')->loadCount('proposals');

        $payload = $import->toArray();
        if ($withRows) {
            $payload['rows_preview'] = $import->rows()
                ->orderBy('row_no')
                ->limit(100)
                ->get();
        }

        return $payload;
    }
}
