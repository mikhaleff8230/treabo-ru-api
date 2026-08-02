<?php

namespace App\Services\AiAssistant;

use App\Models\AiLearningEvent;
use App\Models\ProffiTask;
use App\Models\RequestDraft;
use App\Models\RequestDraftEvent;
use App\Models\TreaboResponseSetting;
use App\Services\AiKnowledge\KnowledgeTextNormalizer;
use App\Services\Proffi\MasterMatchingService;
use App\Services\Proffi\TaskLocationService;
use Illuminate\Support\Facades\DB;

class RequestDraftPublisher
{
    public function __construct(
        private readonly MasterMatchingService $matching,
        private readonly KnowledgeTextNormalizer $normalizer,
        private readonly ConditionalQuestionEngine $questionEngine,
        private readonly TaskLocationService $taskLocation,
    ) {
    }

    public function publish(RequestDraft $draft, int $userId, int $expectedVersion): ProffiTask
    {
        return DB::transaction(function () use ($draft, $userId, $expectedVersion) {
            $draft = RequestDraft::query()->lockForUpdate()->findOrFail($draft->id);
            if ($draft->task_id) {
                return ProffiTask::findOrFail($draft->task_id);
            }
            if ($draft->version !== $expectedVersion) {
                throw new RequestDraftException(
                    'DRAFT_VERSION_CONFLICT',
                    'Черновик был обновлён перед публикацией.',
                    409,
                    true,
                    ['current_version' => $draft->version]
                );
            }
            if ($draft->status !== 'ready_for_review') {
                throw new RequestDraftException(
                    'PUBLISH_VALIDATION_FAILED',
                    'Черновик ещё не готов к публикации.',
                    422
                );
            }

            $snapshot = $draft->snapshot;
            $missing = $draft->selected_service_id
                ? $this->missingRequired($draft)
                : ['service'];
            $city = trim((string) ($snapshot['location']['city'] ?? ''));
            if ($city === '') {
                $missing[] = 'city';
            }
            $address = trim((string) ($snapshot['location']['address'] ?? ''));
            if ($address !== '' && (
                empty($snapshot['location']['confirmed'])
                || !is_numeric($snapshot['location']['lat'] ?? null)
                || !is_numeric($snapshot['location']['lng'] ?? null)
            )) {
                $missing[] = 'address_confirmation';
            }
            if ($missing) {
                throw new RequestDraftException(
                    'PUBLISH_VALIDATION_FAILED',
                    'Не заполнены обязательные данные.',
                    422,
                    false,
                    ['missing' => $missing]
                );
            }

            $draft->update(['status' => 'publishing']);
            $settings = TreaboResponseSetting::current();
            $budget = $snapshot['budget'] ?? [];
            $location = $this->taskLocation->normalize([
                'city' => $city,
                'address' => $address !== '' ? $address : null,
                'lat' => $snapshot['location']['lat'] ?? null,
                'lng' => $snapshot['location']['lng'] ?? null,
            ]);
            $task = ProffiTask::create([
                'title' => mb_substr((string) ($snapshot['title'] ?? 'Заявка на услугу'), 0, 512),
                'description' => (string) ($snapshot['description'] ?? ''),
                'category' => (string) $draft->selected_category_id,
                'category_id' => $draft->selected_category_id,
                'work_id' => $draft->selected_service_id,
                'location_id' => $location['location_id'] ?? null,
                'city' => $location['city'] ?? $city,
                'address' => $location['address'] ?? null,
                'lat' => $location['lat'] ?? null,
                'lng' => $location['lng'] ?? null,
                'budget' => ($budget['type'] ?? null) === 'fixed' ? ($budget['amount'] ?? null) : null,
                'budget_type' => in_array($budget['type'] ?? null, ['fixed', 'range'], true)
                    ? $budget['type']
                    : 'fixed',
                'budget_min' => $budget['min'] ?? null,
                'budget_max' => $budget['max'] ?? null,
                'deadline' => $this->deadline($snapshot['urgency']['code'] ?? 'unknown'),
                'photos' => collect($snapshot['photos'] ?? [])->pluck('url')->filter()->values()->all(),
                'ai_details' => [
                    'request_draft_id' => $draft->id,
                    'catalog_version_id' => $draft->catalog_version_id,
                    'answers' => $draft->answers()->with('question')->get()->map(fn ($answer) => [
                        'question_id' => $answer->question_id,
                        'question' => $answer->question?->question,
                        'value' => $answer->value['value'] ?? null,
                        'display_value' => $answer->display_value,
                        'source' => $answer->source,
                    ])->values()->all(),
                    'materials' => $snapshot['materials'] ?? null,
                    'constraints' => $snapshot['constraints'] ?? [],
                    'preferences' => $snapshot['preferences'] ?? [],
                    'master_summary' => $snapshot['master_summary'] ?? null,
                    'location' => [
                        'confirmed' => (bool) ($snapshot['location']['confirmed'] ?? false),
                        'source' => $snapshot['location']['source'] ?? null,
                    ],
                ],
                'response_price_mdl' => $settings->default_response_price_mdl,
                'customer_id' => $userId,
                'status' => 'open',
            ]);

            $draft->update([
                'user_id' => $userId,
                'task_id' => $task->id,
                'status' => 'published',
                'version' => $draft->version + 1,
                'last_activity_at' => now(),
            ]);
            RequestDraftEvent::create([
                'draft_id' => $draft->id,
                'event_type' => 'draft_published',
                'from_status' => 'publishing',
                'to_status' => 'published',
                'payload' => ['task_id' => $task->id],
                'actor_user_id' => $userId,
            ]);
            AiLearningEvent::create([
                'request_draft_id' => $draft->id,
                'task_id' => $task->id,
                'event_type' => 'classification_confirmed',
                'before' => null,
                'after' => [
                    'category_id' => $draft->selected_category_id,
                    'service_id' => $draft->selected_service_id,
                ],
                'redacted_evidence' => $this->normalizer->redactPii((string) $snapshot['description']),
                'source_actor' => 'customer',
                'weight' => 0.8,
                'status' => 'new',
            ]);

            $this->matching->assignRecommendedSpecialists($task);

            return $task->fresh(['work', 'categoryEntity']);
        });
    }

    private function missingRequired(RequestDraft $draft): array
    {
        $values = [];
        foreach ($draft->answers()->with('question')->get() as $answer) {
            $value = $answer->value['value'] ?? null;
            $values[$answer->question_id] = $value;
            if ($answer->question?->field_key) {
                $values[$answer->question->field_key] = $value;
            }
        }
        $required = $this->questionEngine
            ->activeQuestions((int) $draft->selected_service_id, $values)
            ->where('effective_required', true)
            ->pluck('id');
        $answered = $draft->answers()->whereIn('question_id', $required)->pluck('question_id');

        return $required->diff($answered)->map(fn ($id) => 'question:'.$id)->values()->all();
    }

    private function deadline(string $urgency): ?string
    {
        return match ($urgency) {
            'urgent' => 'Срочно',
            'this_week' => 'На этой неделе',
            'this_month' => 'В этом месяце',
            'flexible' => 'Сроки гибкие',
            default => null,
        };
    }
}
