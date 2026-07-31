<?php

namespace Tests\Feature\Proffi;

use App\Models\ProffiCategory;
use App\Models\ProffiWork;
use App\Models\ProffiWorkQuestion;
use App\Services\AiAssistant\DialogueInferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class RequestDraftAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_dialogue_is_restorable_idempotent_and_answers_without_another_ai_call(): void
    {
        [$work, $question] = $this->catalog();
        $ai = Mockery::mock(DialogueInferenceService::class);
        $ai->shouldReceive('infer')->once()->andReturn([
            'input_class' => 'service_request',
            'intents' => [[
                'category_id' => 'plumbing',
                'service_id' => $work->id,
                'label' => 'Ремонт протечки',
                'confidence' => 0.95,
            ]],
            'extracted_facts' => [],
            'conflicts' => [],
            'assistant_text' => 'Откуда течёт?',
            'confidence' => ['input' => 0.99, 'category' => 0.98, 'service' => 0.94, 'facts' => 0.7],
            '_catalog_version_id' => null,
        ]);
        $this->app->instance(DialogueInferenceService::class, $ai);

        $clientDraftId = (string) Str::uuid();
        $created = $this->postJson('/api/proffi/request-drafts', [
            'initial_text' => 'Течет бачок',
            'city_hint' => 'Москва',
            'client_draft_id' => $clientDraftId,
            'idempotency_key' => 'create-'.Str::uuid(),
        ])->assertCreated()
            ->assertJsonPath('data.draft.status', 'clarifying')
            ->assertJsonPath('data.draft.work.id', $work->id)
            ->assertJsonPath('data.ui_action.type', 'ask_question')
            ->assertJsonPath('data.ui_action.question.id', $question->id);

        $draftId = $created->json('data.draft.id');
        $version = $created->json('data.draft.version');
        $recovery = $created->json('recovery_token');
        $turnId = (string) Str::uuid();
        $headers = ['X-Draft-Recovery-Token' => $recovery];

        $answered = $this->postJson("/api/proffi/request-drafts/{$draftId}/turns", [
            'client_turn_id' => $turnId,
            'expected_version' => $version,
            'answer' => ['question_id' => $question->id, 'value' => 'Из бачка'],
        ], $headers)->assertOk()
            ->assertJsonPath('data.draft.status', 'ready_for_review')
            ->assertJsonPath('data.ui_action.type', 'review')
            ->assertJsonPath('data.draft.answers.0.confirmed', true);
        $this->assertStringContainsString('Течет бачок', $answered->json('data.draft.description'));
        $this->assertStringContainsString('Из бачка', $answered->json('data.draft.description'));

        $newVersion = $answered->json('data.draft.version');
        $this->postJson("/api/proffi/request-drafts/{$draftId}/turns", [
            'client_turn_id' => $turnId,
            'expected_version' => $newVersion,
            'answer' => ['question_id' => $question->id, 'value' => 'Из бачка'],
        ], $headers)->assertOk()->assertJsonPath('data.draft.version', $newVersion);

        $this->getJson(
            "/api/proffi/request-drafts/latest?client_draft_id={$clientDraftId}",
            $headers
        )->assertOk()->assertJsonPath('data.draft.id', $draftId);

        $this->assertDatabaseCount('ai_invocations', 0);
        $this->assertDatabaseCount('request_draft_answers', 1);
    }

    public function test_stale_version_is_rejected(): void
    {
        [$work] = $this->catalog();
        $ai = Mockery::mock(DialogueInferenceService::class);
        $ai->shouldReceive('infer')->once()->andReturn([
            'input_class' => 'service_request',
            'intents' => [[
                'category_id' => 'plumbing',
                'service_id' => $work->id,
                'label' => 'Ремонт',
                'confidence' => 0.95,
            ]],
            'extracted_facts' => [],
            'conflicts' => [],
            'assistant_text' => '',
            'confidence' => ['input' => 1, 'category' => 1, 'service' => 1, 'facts' => 0],
            '_catalog_version_id' => null,
        ]);
        $this->app->instance(DialogueInferenceService::class, $ai);

        $created = $this->postJson('/api/proffi/request-drafts', [
            'initial_text' => 'Нужен ремонт',
            'client_draft_id' => (string) Str::uuid(),
            'idempotency_key' => 'create-'.Str::uuid(),
        ])->assertCreated();

        $this->postJson(
            '/api/proffi/request-drafts/'.$created->json('data.draft.id').'/turns',
            [
                'client_turn_id' => (string) Str::uuid(),
                'expected_version' => 0,
                'message' => 'Дополнение',
            ],
            ['X-Draft-Recovery-Token' => $created->json('recovery_token')]
        )->assertConflict()->assertJsonPath('error.code', 'DRAFT_VERSION_CONFLICT');
    }

    public function test_photo_metadata_is_kept_out_of_description_and_master_summary(): void
    {
        [$work, $question] = $this->catalog();
        $question->update([
            'field_key' => 'work_photo',
            'question' => 'Фото места работ',
            'type' => 'photo',
            'options' => null,
        ]);
        $ai = Mockery::mock(DialogueInferenceService::class);
        $ai->shouldReceive('infer')->once()->andReturn([
            'input_class' => 'service_request',
            'intents' => [[
                'category_id' => 'plumbing',
                'service_id' => $work->id,
                'label' => 'Ремонт унитаза',
                'confidence' => 0.95,
            ]],
            'extracted_facts' => [],
            'conflicts' => [],
            'assistant_text' => 'Добавьте фото.',
            'confidence' => ['input' => 1, 'category' => 1, 'service' => 1, 'facts' => 0],
            '_catalog_version_id' => null,
        ]);
        $this->app->instance(DialogueInferenceService::class, $ai);

        $created = $this->postJson('/api/proffi/request-drafts', [
            'initial_text' => 'Ремонт унитаза',
            'city_hint' => 'Москва',
            'client_draft_id' => (string) Str::uuid(),
            'idempotency_key' => 'create-'.Str::uuid(),
        ])->assertCreated();

        $answer = $this->postJson(
            '/api/proffi/request-drafts/'.$created->json('data.draft.id').'/turns',
            [
                'client_turn_id' => (string) Str::uuid(),
                'expected_version' => $created->json('data.draft.version'),
                'answer' => [
                    'question_id' => $question->id,
                    'value' => [
                        'path' => 'public_proffi/tasks/photo.jpg',
                        'url' => 'http://127.0.0.1:8001/api/proffi/files/photo.jpg',
                        'mime_type' => 'image/jpeg',
                        'size' => 204069,
                    ],
                ],
            ],
            ['X-Draft-Recovery-Token' => $created->json('recovery_token')]
        )->assertOk()
            ->assertJsonPath('data.draft.description', 'Ремонт унитаза')
            ->assertJsonPath('data.draft.answers.0.display_value', 'Фото добавлено')
            ->assertJsonPath('data.draft.photos.0.url', 'http://127.0.0.1:8001/api/proffi/files/photo.jpg');

        $this->assertStringNotContainsString(
            'photo.jpg',
            (string) $answer->json('data.draft.master_summary')
        );
    }

    private function catalog(): array
    {
        ProffiCategory::create([
            'id' => 'plumbing',
            'slug' => 'plumbing',
            'icon' => 'Wrench',
            'name_ru' => 'Сантехника',
            'name_ro' => 'Sanitare',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $work = ProffiWork::create([
            'category_id' => 'plumbing',
            'title' => 'Ремонт унитаза',
            'slug' => 'toilet-repair',
            'aliases' => ['течет бачок'],
            'is_active' => true,
        ]);
        $question = ProffiWorkQuestion::create([
            'work_id' => $work->id,
            'field_key' => 'leak_source',
            'question' => 'Откуда течёт?',
            'type' => 'select',
            'options' => ['Из бачка', 'Снизу', 'Не знаю'],
            'is_required' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        return [$work, $question];
    }
}
