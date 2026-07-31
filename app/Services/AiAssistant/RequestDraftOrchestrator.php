<?php

namespace App\Services\AiAssistant;

use App\Models\AiKnowledgeVersion;
use App\Models\AiLearningEvent;
use App\Models\ProffiCategory;
use App\Models\ProffiWork;
use App\Models\ProffiWorkQuestion;
use App\Models\RequestDraft;
use App\Models\RequestDraftAnswer;
use App\Models\RequestDraftEvent;
use App\Models\RequestDraftMessage;
use App\Services\AiKnowledge\KnowledgeTextNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RequestDraftOrchestrator
{
    public function __construct(
        private readonly DialogueInferenceService $inference,
        private readonly ConditionalQuestionEngine $questionEngine,
        private readonly KnowledgeTextNormalizer $normalizer,
    ) {
    }

    public function create(array $data, ?int $userId): array
    {
        if (!empty($data['idempotency_key'])) {
            $existing = RequestDraft::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $this->response($existing);
            }
        }

        $guestToken = $userId ? null : Str::random(64);
        $catalogVersion = AiKnowledgeVersion::where('status', 'published')->latest('published_at')->first();
        $draft = DB::transaction(function () use ($data, $userId, $guestToken, $catalogVersion) {
            $draft = RequestDraft::create([
                'user_id' => $userId,
                'guest_token_hash' => $guestToken ? hash('sha256', $guestToken) : null,
                'client_draft_id' => $data['client_draft_id'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'status' => 'classifying',
                'version' => 0,
                'catalog_version_id' => $catalogVersion?->id,
                'snapshot' => $this->initialSnapshot($data),
                'expires_at' => now()->addDays((int) config('ai_assistant.draft_ttl_days', 30)),
                'last_activity_at' => now(),
            ]);
            $this->event($draft, 'draft_created', null, 'classifying');

            return $draft;
        });

        $message = $this->message($draft, 'user', $data['initial_text'], null, null);
        $this->processFreeText($draft, $message);
        $result = $this->response($draft->fresh());
        if ($guestToken) {
            $result['recovery_token'] = $guestToken;
        }

        return $result;
    }

    public function turn(RequestDraft $draft, array $data): array
    {
        $result = DB::transaction(function () use ($draft, $data) {
            $draft = RequestDraft::query()->lockForUpdate()->findOrFail($draft->id);
            $this->assertMutable($draft);
            if ((int) $data['expected_version'] !== $draft->version) {
                throw new RequestDraftException(
                    'DRAFT_VERSION_CONFLICT',
                    'Черновик был обновлён в другой вкладке.',
                    409,
                    true,
                    ['current_version' => $draft->version]
                );
            }

            $duplicate = RequestDraftMessage::query()
                ->where('draft_id', $draft->id)
                ->where('client_turn_id', $data['client_turn_id'])
                ->first();
            if ($duplicate) {
                return ['response' => $this->response($draft)];
            }

            if (!empty($data['skip_question_id'])) {
                $this->processSkip($draft, (int) $data['skip_question_id'], $data['client_turn_id']);
                return ['response' => $this->response($draft->fresh())];
            }

            if (!empty($data['answer'])) {
                $this->processAnswer($draft, $data['answer'], $data['client_turn_id']);
                return ['response' => $this->response($draft->fresh())];
            } else {
                $message = $this->message(
                    $draft,
                    'user',
                    (string) ($data['message'] ?? ''),
                    null,
                    $data['client_turn_id']
                );
                $this->transition($draft, 'classifying', 'free_text_received', ['message_id' => $message->id]);

                return ['message_id' => $message->id];
            }
        }, 3);

        if (isset($result['response'])) {
            return $result['response'];
        }

        $draft = RequestDraft::findOrFail($draft->id);
        $message = RequestDraftMessage::findOrFail($result['message_id']);
        $this->processFreeText($draft, $message);

        return $this->response($draft->fresh());
    }

    public function patch(RequestDraft $draft, array $data): array
    {
        return DB::transaction(function () use ($draft, $data) {
            $draft = RequestDraft::query()->lockForUpdate()->findOrFail($draft->id);
            $this->assertMutable($draft);
            if ((int) $data['expected_version'] !== $draft->version) {
                throw new RequestDraftException('DRAFT_VERSION_CONFLICT', 'Черновик уже изменён.', 409, true);
            }

            $snapshot = $draft->snapshot;
            $selectionBefore = [
                'category_id' => $draft->selected_category_id,
                'service_id' => $draft->selected_service_id,
            ];
            $invalidated = [];
            foreach ($data['changes'] as $change) {
                $path = $change['path'];
                $value = $change['value'] ?? null;
                match ($path) {
                    '/category/id' => $this->setCategory($draft, $snapshot, $value, $invalidated),
                    '/work/id' => $this->setWork($draft, $snapshot, $value, $invalidated),
                    '/location/city' => $snapshot['location']['city'] = $this->shortText($value, 128),
                    '/location/address' => $snapshot['location']['address'] = $this->shortText($value, 512),
                    '/location/lat' => $snapshot['location']['lat'] = is_numeric($value) ? (float) $value : null,
                    '/location/lng' => $snapshot['location']['lng'] = is_numeric($value) ? (float) $value : null,
                    '/location/confirmed' => $snapshot['location']['confirmed'] = (bool) $value,
                    '/location/source' => $snapshot['location']['source'] = $this->shortText($value, 32),
                    '/urgency/code' => $snapshot['urgency']['code'] = $this->urgency($value),
                    '/budget/type' => $snapshot['budget']['type'] = $this->budgetType($value),
                    '/budget/amount' => $snapshot['budget']['amount'] = is_numeric($value) ? max(0, (int) $value) : null,
                    '/budget/min' => $snapshot['budget']['min'] = is_numeric($value) ? max(0, (int) $value) : null,
                    '/budget/max' => $snapshot['budget']['max'] = is_numeric($value) ? max(0, (int) $value) : null,
                    '/title' => $snapshot['title'] = $this->shortText($value, 100),
                    '/description' => $snapshot['description'] = $this->shortText($value, 4000),
                    default => throw new RequestDraftException('INVALID_PATCH_PATH', "Поле {$path} нельзя изменить.", 422),
                };
            }
            $draft->update(['snapshot' => $snapshot]);
            $selectionAfter = [
                'category_id' => $draft->selected_category_id,
                'service_id' => $draft->selected_service_id,
            ];
            if ($selectionBefore !== $selectionAfter) {
                $this->learningEvent(
                    $draft,
                    'classification_corrected',
                    $selectionBefore,
                    $selectionAfter,
                    1
                );
            }
            $this->advance($draft, 'draft_corrected', ['changes' => $data['changes']]);
            $this->decideNextAction($draft);

            $response = $this->response($draft->fresh());
            $response['invalidated_answers'] = $invalidated;

            return $response;
        });
    }

    public function response(RequestDraft $draft): array
    {
        $draft->loadMissing(['messages', 'answers']);
        $snapshot = $this->buildSnapshot($draft);
        $uiAction = $snapshot['_ui_action'] ?? ['type' => 'wait'];
        unset($snapshot['_ui_action']);

        return [
            'data' => [
                'draft' => $snapshot,
                'ui_action' => $uiAction,
                'progress' => $this->progress($draft),
            ],
        ];
    }

    private function processFreeText(RequestDraft $draft, RequestDraftMessage $message): void
    {
        try {
            $result = $this->inference->infer($draft, $message);
        } catch (DialogueInferenceException $e) {
            $snapshot = $draft->snapshot;
            $snapshot['_ui_action'] = [
                'type' => 'manual_fallback',
                'message' => 'AI временно недоступен. Выберите услугу вручную — введённый текст сохранён.',
            ];
            $this->transition($draft, 'manual_selection', 'ai_unavailable', ['message' => $e->getMessage()]);
            $draft->update(['snapshot' => $snapshot]);

            return;
        }

        $snapshot = $draft->snapshot;
        $snapshot['input_class'] = $result['input_class'];
        $snapshot['confidence'] = [
            ...$snapshot['confidence'],
            ...$result['confidence'],
        ];
        $snapshot['multiple_services_detected'] = count(array_filter(
            $result['intents'],
            fn ($intent) => !empty($intent['service_id'])
        )) > 1;
        $snapshot['intents'] = $result['intents'];
        $draft->input_class = $result['input_class'];
        $draft->catalog_version_id = $result['_catalog_version_id'] ?: $draft->catalog_version_id;

        if ($result['input_class'] !== 'service_request') {
            $draft->meaningless_turns_count++;
            $manual = $draft->meaningless_turns_count >= 2;
            $snapshot['_ui_action'] = $manual
                ? [
                    'type' => 'manual_fallback',
                    'message' => 'Не получилось распознать задачу. Выберите категорию и работу вручную.',
                ]
                : [
                    'type' => 'ask_question',
                    'question' => [
                        'id' => null,
                        'key' => 'service_description',
                        'text' => $result['assistant_text'] ?: 'Что нужно сделать или починить?',
                        'field_type' => 'text',
                        'options' => [],
                    ],
                ];
            $draft->snapshot = $snapshot;
            $draft->save();
            $this->transition($draft, $manual ? 'manual_selection' : 'clarifying', 'input_not_service');
            $this->learningEvent(
                $draft,
                $manual ? 'manual_fallback' : 'unrecognized_text',
                ['input_class' => $result['input_class']],
                null,
                $manual ? 0.7 : 0.4
            );
            $this->assistantMessage($draft, $snapshot['_ui_action']['message'] ?? $snapshot['_ui_action']['question']['text']);

            return;
        }

        $validIntents = collect($result['intents'])->filter(fn ($intent) => $intent['service_id']);
        if ($validIntents->count() > 1) {
            $snapshot['_ui_action'] = [
                'type' => 'split_intents',
                'message' => 'Похоже, здесь несколько задач. Создать отдельную заявку для каждой?',
                'intents' => $validIntents->values()->all(),
            ];
            $draft->snapshot = $snapshot;
            $draft->save();
            $this->transition($draft, 'split_intents', 'multiple_services_detected');
            $this->learningEvent(
                $draft,
                'multi_intent_split',
                null,
                ['intents' => $validIntents->values()->all()],
                0.6
            );
            $this->assistantMessage($draft, $snapshot['_ui_action']['message']);

            return;
        }

        $intent = collect($result['intents'])->sortByDesc('confidence')->first();
        if ($intent
            && $intent['category_id']
            && $result['confidence']['category'] >= config('ai_assistant.category_confidence', 0.55)
        ) {
            $draft->selected_category_id = $intent['category_id'];
        }
        if ($intent
            && $intent['service_id']
            && $result['confidence']['service'] >= config('ai_assistant.service_confidence', 0.65)
        ) {
            $draft->selected_service_id = $intent['service_id'];
        }
        $draft->save();

        foreach ($result['extracted_facts'] as $fact) {
            if (!$fact['question_id'] || $fact['confidence'] < 0.7) {
                continue;
            }
            $question = ProffiWorkQuestion::whereKey($fact['question_id'])
                ->where('work_id', $draft->selected_service_id)
                ->where('is_active', true)
                ->first();
            if (!$question || !$this->validAnswer($question, $fact['value'])) {
                continue;
            }
            RequestDraftAnswer::updateOrCreate(
                ['draft_id' => $draft->id, 'question_id' => $question->id],
                [
                    'value' => ['value' => $fact['value']],
                    'display_value' => $this->displayValue($fact['value']),
                    'source' => 'ai_extracted',
                    'confidence' => $fact['confidence'],
                    'evidence_message_id' => $message->id,
                    'is_confirmed' => false,
                    'catalog_version_id' => $draft->catalog_version_id,
                ]
            );
        }

        $snapshot['description'] = $this->description($draft);
        $snapshot['title'] = $this->title($draft, $intent['label'] ?? null);
        $snapshot['master_summary'] = $this->masterSummary($draft);
        $draft->snapshot = $snapshot;
        $draft->save();
        $this->advance($draft, 'inference_merged', ['input_class' => $result['input_class']]);
        $this->decideNextAction($draft, $result['assistant_text']);
    }

    private function processAnswer(RequestDraft $draft, array $answer, string $clientTurnId): void
    {
        $question = $this->activeQuestions($draft)->firstWhere('id', (int) $answer['question_id']);
        if (!$question || !$this->validAnswer($question, $answer['value'] ?? null)) {
            throw new RequestDraftException('INVALID_ANSWER', 'Ответ не подходит к текущему вопросу.', 422);
        }

        $displayValue = $question->type === 'photo'
            ? 'Фото добавлено'
            : $this->displayValue($answer['value']);
        $message = $this->message(
            $draft,
            'user',
            $displayValue,
            $question->id,
            $clientTurnId
        );
        RequestDraftAnswer::updateOrCreate(
            ['draft_id' => $draft->id, 'question_id' => $question->id],
            [
                'value' => ['value' => $answer['value']],
                'display_value' => $displayValue,
                'source' => 'user_selected',
                'confidence' => 1,
                'evidence_message_id' => $message->id,
                'is_confirmed' => true,
                'catalog_version_id' => $draft->catalog_version_id,
            ]
        );
        if ($question->type === 'photo') {
            $snapshot = $draft->snapshot;
            $photo = is_array($answer['value']) ? $answer['value'] : ['url' => $answer['value']];
            $snapshot['photos'] = collect($snapshot['photos'] ?? [])
                ->push([
                    'upload_id' => $photo['path'] ?? $photo['upload_id'] ?? null,
                    'url' => $photo['url'] ?? null,
                    'caption' => $photo['caption'] ?? null,
                ])
                ->filter(fn ($item) => $item['upload_id'] || $item['url'])
                ->unique(fn ($item) => ($item['upload_id'] ?? '').'|'.($item['url'] ?? ''))
                ->take(10)
                ->values()
                ->all();
            $draft->update(['snapshot' => $snapshot]);
        }
        $this->advance($draft, 'answer_saved', ['question_id' => $question->id]);
        $this->decideNextAction($draft);
    }

    private function processSkip(RequestDraft $draft, int $questionId, string $clientTurnId): void
    {
        $question = $this->activeQuestions($draft)->firstWhere('id', $questionId);
        if (!$question || $question->effective_required) {
            throw new RequestDraftException(
                'QUESTION_CANNOT_BE_SKIPPED',
                'Этот вопрос нужен для формирования заявки.',
                422
            );
        }

        $message = $this->message($draft, 'user', 'Пропущено', $question->id, $clientTurnId);
        RequestDraftAnswer::updateOrCreate(
            ['draft_id' => $draft->id, 'question_id' => $question->id],
            [
                'value' => ['value' => null],
                'display_value' => 'Пропущено',
                'source' => 'user_skipped',
                'confidence' => 1,
                'evidence_message_id' => $message->id,
                'is_confirmed' => true,
                'catalog_version_id' => $draft->catalog_version_id,
            ]
        );
        $this->advance($draft, 'question_skipped', ['question_id' => $question->id]);
        $this->learningEvent($draft, 'question_skipped', ['question_id' => $question->id], null, 0.25);
        $this->decideNextAction($draft);
    }

    private function decideNextAction(RequestDraft $draft, ?string $assistantText = null): void
    {
        $draft->refresh();
        $snapshot = $draft->snapshot;
        if (!$draft->selected_category_id) {
            $snapshot['_ui_action'] = [
                'type' => 'choose_category',
                'message' => $assistantText ?: 'Выберите, к какому направлению относится задача.',
            ];
            $draft->update(['snapshot' => $snapshot]);
            $this->transition($draft, 'manual_selection', 'category_unresolved');

            return;
        }
        if (!$draft->selected_service_id) {
            $snapshot['_ui_action'] = [
                'type' => 'choose_service',
                'category_id' => $draft->selected_category_id,
                'message' => $assistantText ?: 'Какая именно работа нужна?',
            ];
            $draft->update(['snapshot' => $snapshot]);
            $this->transition($draft, 'manual_selection', 'service_unresolved');

            return;
        }

        $question = $this->nextQuestion($draft);
        if ($question) {
            $snapshot['_ui_action'] = [
                'type' => 'ask_question',
                'question' => [
                    'id' => $question->id,
                    'key' => $question->field_key ?: 'q_'.$question->id,
                    'text' => $question->question,
                    'field_type' => $this->fieldType($question->type),
                    'options' => collect($question->options ?? [])->map(fn ($option) => [
                        'value' => $option,
                        'label' => $option,
                    ])->values()->all(),
                    'required' => (bool) $question->effective_required,
                ],
            ];
            $draft->increment('questions_asked_count');
            $draft->update(['snapshot' => $snapshot]);
            $this->transition($draft, 'clarifying', 'next_question', ['question_id' => $question->id]);
            $this->assistantMessage($draft, $question->question, $question->id);

            return;
        }

        $snapshot['description'] = $this->description($draft);
        $snapshot['title'] = $this->title($draft);
        $snapshot['master_summary'] = $this->masterSummary($draft);
        $snapshot['_ui_action'] = ['type' => 'review'];
        $draft->update(['snapshot' => $snapshot]);
        $this->transition($draft, 'ready_for_review', 'required_information_complete');
    }

    private function nextQuestion(RequestDraft $draft): ?ProffiWorkQuestion
    {
        $answered = $draft->answers()->pluck('question_id');
        $questions = $this->activeQuestions($draft)
            ->whereNotIn('id', $answered)
            ->sortBy([
                fn ($a, $b) => ((int) $b->effective_required) <=> ((int) $a->effective_required),
                fn ($a, $b) => $a->sort_order <=> $b->sort_order,
                fn ($a, $b) => $a->id <=> $b->id,
            ]);
        if ($draft->questions_asked_count >= config('ai_assistant.soft_question_limit', 5)) {
            $questions = $questions->where('effective_required', true);
        }

        return $questions->first();
    }

    private function buildSnapshot(RequestDraft $draft): array
    {
        $snapshot = $draft->snapshot;
        $category = $draft->selected_category_id
            ? ProffiCategory::find($draft->selected_category_id)
            : null;
        $work = $draft->selected_service_id ? ProffiWork::find($draft->selected_service_id) : null;
        $answers = $draft->answers()
            ->join('proffi_work_questions', 'proffi_work_questions.id', '=', 'request_draft_answers.question_id')
            ->orderBy('proffi_work_questions.sort_order')
            ->get([
                'request_draft_answers.*',
                'proffi_work_questions.field_key',
                'proffi_work_questions.question',
            ])
            ->map(fn ($answer) => [
                'question_id' => $answer->question_id,
                'question_key' => $answer->field_key ?: 'q_'.$answer->question_id,
                'question' => $answer->question,
                'value' => $answer->value['value'] ?? null,
                'display_value' => $answer->display_value,
                'source' => $answer->source,
                'confidence' => $answer->confidence,
                'confirmed' => $answer->is_confirmed,
            ])->values()->all();
        $missing = [];
        if ($work) {
            $answeredIds = collect($answers)->pluck('question_id');
            $missing = $this->activeQuestions($draft)
                ->where('effective_required', true)
                ->whereNotIn('id', $answeredIds)
                ->map(fn ($question) => [
                    'field_key' => $question->field_key ?: 'q_'.$question->id,
                    'reason' => 'required',
                    'blocking' => true,
                ])->values()->all();
        }

        return [
            ...$snapshot,
            'id' => $draft->id,
            'status' => $draft->status,
            'version' => $draft->version,
            'catalog_version' => $draft->catalog_version_id,
            'category' => $category ? ['id' => $category->id, 'name' => $category->name_ru] : null,
            'work' => $work ? ['id' => $work->id, 'stable_key' => $work->slug, 'name' => $work->title] : null,
            'answers' => $answers,
            'missing' => $missing,
            'confidence' => [
                ...($snapshot['confidence'] ?? []),
                'overall' => $this->overallConfidence($draft, count($missing)),
            ],
            'created_at' => $draft->created_at?->toIso8601String(),
            'updated_at' => $draft->updated_at?->toIso8601String(),
        ];
    }

    private function initialSnapshot(array $data): array
    {
        return [
            'title' => null,
            'initial_text' => trim((string) $data['initial_text']),
            'description' => trim((string) $data['initial_text']),
            'answers' => [],
            'location' => [
                'city' => $data['city_hint'] ?? null,
                'address' => null,
                'lat' => null,
                'lng' => null,
                'access_notes' => null,
                'confirmed' => false,
                'source' => null,
            ],
            'urgency' => ['code' => 'unknown', 'desired_at' => null, 'flexible' => false],
            'budget' => [
                'type' => 'unknown', 'amount' => null, 'min' => null, 'max' => null, 'currency' => 'RUB',
            ],
            'photos' => collect($data['photo_upload_ids'] ?? [])->map(fn ($id) => [
                'upload_id' => $id, 'url' => null, 'caption' => null,
            ])->all(),
            'materials' => ['status' => 'unknown', 'provided_by' => 'unknown', 'notes' => null],
            'constraints' => [],
            'preferences' => [],
            'missing' => [],
            'confidence' => ['overall' => 0, 'category' => 0, 'work' => 0, 'facts' => 0],
            'master_summary' => '',
            'multiple_services_detected' => false,
            'safety_notices' => [],
            '_ui_action' => ['type' => 'wait'],
        ];
    }

    private function message(
        RequestDraft $draft,
        string $role,
        ?string $content,
        ?int $questionId,
        ?string $clientTurnId
    ): RequestDraftMessage {
        $turnNo = (int) $draft->messages()->max('turn_no') + ($role === 'user' ? 1 : 0);

        return RequestDraftMessage::create([
            'draft_id' => $draft->id,
            'turn_no' => max(1, $turnNo),
            'role' => $role,
            'content' => $content,
            'question_id' => $questionId,
            'client_turn_id' => $clientTurnId,
        ]);
    }

    private function assistantMessage(RequestDraft $draft, string $content, ?int $questionId = null): void
    {
        $this->message($draft, 'assistant', $content, $questionId, null);
    }

    private function advance(RequestDraft $draft, string $event, array $payload = []): void
    {
        $draft->increment('version');
        $draft->update(['last_activity_at' => now()]);
        $this->event($draft, $event, $draft->status, $draft->status, $payload);
    }

    private function transition(
        RequestDraft $draft,
        string $status,
        string $event,
        array $payload = []
    ): void {
        $from = $draft->status;
        $draft->update([
            'status' => $status,
            'version' => $draft->version + 1,
            'last_activity_at' => now(),
        ]);
        $this->event($draft, $event, $from, $status, $payload);
    }

    private function event(
        RequestDraft $draft,
        string $type,
        ?string $from,
        ?string $to,
        array $payload = []
    ): void {
        RequestDraftEvent::create([
            'draft_id' => $draft->id,
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'payload' => $payload ?: null,
            'actor_user_id' => $draft->user_id,
        ]);
    }

    private function setCategory(RequestDraft $draft, array &$snapshot, mixed $value, array &$invalidated): void
    {
        $category = ProffiCategory::whereKey((string) $value)->where('is_active', true)->first();
        if (!$category) {
            throw new RequestDraftException('INVALID_CATEGORY', 'Категория не найдена.', 422);
        }
        if ($draft->selected_category_id !== $category->id) {
            $invalidated = $draft->answers()->pluck('question_id')->all();
            $draft->answers()->delete();
            $draft->selected_service_id = null;
        }
        $draft->selected_category_id = $category->id;
        $draft->save();
    }

    private function setWork(RequestDraft $draft, array &$snapshot, mixed $value, array &$invalidated): void
    {
        $work = ProffiWork::whereKey((int) $value)->where('is_active', true)->first();
        if (!$work) {
            throw new RequestDraftException('INVALID_SERVICE', 'Работа не найдена.', 422);
        }
        if ($draft->selected_service_id !== $work->id) {
            $invalidated = $draft->answers()->pluck('question_id')->all();
            $draft->answers()->delete();
        }
        $draft->selected_category_id = $work->category_id;
        $draft->selected_service_id = $work->id;
        $draft->save();
    }

    private function validAnswer(ProffiWorkQuestion $question, mixed $value): bool
    {
        return match ($question->type) {
            'number' => is_numeric($value),
            'yesno' => is_bool($value) || in_array($value, ['yes', 'no', 'Да', 'Нет'], true),
            'select' => is_string($value) && in_array($value, $question->options ?? [], true),
            'multiselect' => is_array($value) && !array_diff($value, $question->options ?? []),
            'photo' => is_array($value) || is_string($value),
            default => is_string($value) && trim($value) !== '',
        };
    }

    private function fieldType(string $type): string
    {
        return match ($type) {
            'select' => 'single_select',
            'multiselect' => 'multi_select',
            'yesno' => 'boolean',
            default => $type,
        };
    }

    private function displayValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Да' : 'Нет';
        }
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        return mb_substr(trim((string) $value), 0, 500);
    }

    private function description(RequestDraft $draft): string
    {
        $base = $draft->messages()
            ->where('role', 'user')
            ->whereNull('question_id')
            ->pluck('content')
            ->filter()
            ->implode('. ');
        $details = $this->narrativeAnswers($draft);

        return mb_substr(trim(
            $base.($details->isNotEmpty() ? "\n\nДетали задачи:\n- ".$details->implode("\n- ") : '')
        ), 0, 4000);
    }

    private function title(RequestDraft $draft, ?string $label = null): string
    {
        $work = $draft->selected_service_id ? ProffiWork::find($draft->selected_service_id) : null;

        return mb_substr(trim($label ?: $work?->title ?: 'Заявка на услугу'), 0, 100);
    }

    private function masterSummary(RequestDraft $draft): string
    {
        $work = $draft->selected_service_id ? ProffiWork::find($draft->selected_service_id) : null;
        $answers = $this->narrativeAnswers($draft)->implode('; ');

        return mb_substr(trim(($work?->title ?: 'Задача').($answers ? ': '.$answers : '')), 0, 500);
    }

    private function narrativeAnswers(RequestDraft $draft): \Illuminate\Support\Collection
    {
        return $draft->answers()
            ->with('question')
            ->get()
            ->reject(fn (RequestDraftAnswer $answer) => $answer->source === 'user_skipped'
                || $answer->question?->type === 'photo'
                || trim((string) $answer->display_value) === '')
            ->map(fn (RequestDraftAnswer $answer) => trim(
                ($answer->question?->question
                    ? rtrim($answer->question->question, " \t\n\r\0\x0B?:.!").': '
                    : '').$answer->display_value
            ))
            ->filter()
            ->values();
    }

    private function progress(RequestDraft $draft): array
    {
        $questions = $draft->selected_service_id ? $this->activeQuestions($draft) : collect();
        $answeredIds = $draft->answers()->pluck('question_id');
        $requiredIds = $questions->where('effective_required', true)->pluck('id');
        $optionalIds = $questions->where('effective_required', false)->pluck('id');
        $requiredAnswered = $requiredIds->intersect($answeredIds)->count();
        $optionalAnswered = $optionalIds->intersect($answeredIds)->count();

        $percent = match (true) {
            in_array($draft->status, ['ready_for_review', 'published'], true) => 100,
            !$draft->selected_category_id => 10,
            !$draft->selected_service_id => 25,
            $requiredIds->isEmpty() => 90,
            default => (int) round(30 + 60 * ($requiredAnswered / $requiredIds->count())),
        };

        return [
            'stage' => $draft->status,
            'required_answered' => $requiredAnswered,
            'required_total' => $requiredIds->count(),
            'optional_answered' => $optionalAnswered,
            'optional_total' => $optionalIds->count(),
            'percent' => min(100, $percent),
            // Backward-compatible aliases for the first API prototype.
            'answered_required' => $requiredAnswered,
            'active_required' => $requiredIds->count(),
        ];
    }

    private function overallConfidence(RequestDraft $draft, int $missing): float
    {
        $confidence = $draft->snapshot['confidence'] ?? [];
        $base = (
            (float) ($confidence['category'] ?? 0)
            + (float) ($confidence['work'] ?? $confidence['service'] ?? 0)
            + (float) ($confidence['facts'] ?? 0)
        ) / 3;

        return round(max(0, min(1, $base - min(0.4, $missing * 0.08))), 2);
    }

    private function urgency(mixed $value): string
    {
        return in_array($value, ['urgent', 'this_week', 'this_month', 'flexible', 'unknown'], true)
            ? $value
            : 'unknown';
    }

    private function budgetType(mixed $value): string
    {
        return in_array($value, ['negotiable', 'fixed', 'range', 'unknown'], true)
            ? $value
            : 'negotiable';
    }

    private function activeQuestions(RequestDraft $draft): \Illuminate\Support\Collection
    {
        if (!$draft->selected_service_id) {
            return collect();
        }
        $answers = [];
        foreach ($draft->answers()->with('question')->get() as $answer) {
            $value = $answer->value['value'] ?? null;
            $answers[$answer->question_id] = $value;
            if ($answer->question?->field_key) {
                $answers[$answer->question->field_key] = $value;
            }
        }

        return $this->questionEngine->activeQuestions((int) $draft->selected_service_id, $answers);
    }

    private function learningEvent(
        RequestDraft $draft,
        string $eventType,
        ?array $before,
        ?array $after,
        float $weight
    ): void {
        AiLearningEvent::create([
            'request_draft_id' => $draft->id,
            'task_id' => $draft->task_id,
            'event_type' => $eventType,
            'before' => $before,
            'after' => $after,
            'redacted_evidence' => $this->normalizer->redactPii(
                (string) ($draft->snapshot['description'] ?? '')
            ),
            'source_actor' => 'customer',
            'weight' => $weight,
            'status' => 'new',
        ]);
    }

    private function shortText(mixed $value, int $limit): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function assertMutable(RequestDraft $draft): void
    {
        if ($draft->expires_at->isPast()) {
            throw new RequestDraftException('DRAFT_EXPIRED', 'Срок хранения черновика истёк.', 410);
        }
        if ($draft->status === 'published') {
            throw new RequestDraftException('DRAFT_READ_ONLY', 'Опубликованный черновик нельзя изменить.', 409);
        }
    }
}
