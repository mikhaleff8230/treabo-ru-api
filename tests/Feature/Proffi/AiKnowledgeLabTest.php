<?php

namespace Tests\Feature\Proffi;

use App\Jobs\AiKnowledge\AnalyzeKnowledgeImport;
use App\Models\AiKnowledgeImport;
use App\Models\AiKnowledgeProposal;
use App\Models\ProffiCategory;
use App\Models\ProffiWork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AiKnowledgeLabTest extends TestCase
{
    use RefreshDatabase;

    private array $headers = ['X-Admin-Token' => 'admin'];

    public function test_admin_can_create_deduplicated_text_import(): void
    {
        $response = $this->postJson('/api/proffi/admin/ai-lab/imports', [
            'source_name' => 'Сантехника',
            'source_type' => 'wordstat',
            'text' => "Течёт бачок;120;Москва;2026-07\nтечет бачок;100;Москва;2026-07\nремонт унитаза;90",
            'mode' => 'full_analysis',
            'cost_limit_usd' => 1,
        ], $this->headers);

        $response->assertCreated()
            ->assertJsonPath('rows_total', 3)
            ->assertJsonPath('rows_unique', 2)
            ->assertJsonPath('status', 'uploaded');

        $this->assertDatabaseCount('ai_knowledge_imports', 1);
        $this->assertDatabaseCount('ai_knowledge_source_rows', 2);
        $this->assertDatabaseCount('ai_knowledge_versions', 1);
    }

    public function test_analysis_is_queued_only_once(): void
    {
        Queue::fake();
        $import = $this->createImport();

        $this->postJson(
            "/api/proffi/admin/ai-lab/imports/{$import->id}/analyze",
            [],
            $this->headers
        )->assertAccepted()
            ->assertJsonPath('status', 'queued');

        Queue::assertPushed(AnalyzeKnowledgeImport::class, 1);

        $this->postJson(
            "/api/proffi/admin/ai-lab/imports/{$import->id}/analyze",
            [],
            $this->headers
        )->assertConflict();
    }

    public function test_accepted_proposal_stays_in_draft_version(): void
    {
        $import = $this->createImport();
        $proposal = AiKnowledgeProposal::create([
            'import_id' => $import->id,
            'knowledge_version_id' => $import->knowledge_version_id,
            'proposal_type' => 'add_alias',
            'status' => 'generated',
            'title' => 'Добавить «бачек»',
            'payload' => ['alias' => 'бачек'],
            'evidence' => [['row_id' => 1, 'text' => 'течет бачек']],
            'confidence' => 0.95,
            'risk_level' => 'low',
        ]);

        $this->postJson(
            "/api/proffi/admin/ai-lab/proposals/{$proposal->id}/accept",
            [],
            $this->headers
        )->assertOk()
            ->assertJsonPath('status', 'accepted');

        $this->assertDatabaseHas('ai_knowledge_versions', [
            'id' => $import->knowledge_version_id,
            'status' => 'draft',
        ]);
        $this->assertDatabaseCount('ai_knowledge_terms', 1);
        $this->assertDatabaseCount('ai_knowledge_term_variants', 1);
        $this->assertDatabaseCount('ai_training_examples', 1);
    }

    public function test_published_alias_is_retrievable_and_updates_catalog_atomically(): void
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
            'slug' => 'remont-unitaza',
            'aliases' => [],
            'is_active' => true,
        ]);
        $import = $this->createImport();
        $row = $import->rows()->firstOrFail();
        $proposal = AiKnowledgeProposal::create([
            'import_id' => $import->id,
            'knowledge_version_id' => $import->knowledge_version_id,
            'proposal_type' => 'add_alias',
            'status' => 'generated',
            'target_type' => 'service',
            'target_id' => (string) $work->id,
            'title' => 'Добавить бытовую формулировку',
            'payload' => ['alias' => 'течет бачок'],
            'evidence' => [['row_id' => $row->id, 'text' => 'течет бачок']],
            'confidence' => 0.98,
            'risk_level' => 'low',
        ]);

        $this->postJson(
            "/api/proffi/admin/ai-lab/proposals/{$proposal->id}/accept",
            [],
            $this->headers
        )->assertOk();
        $this->assertSame([], $work->fresh()->aliases);

        $this->postJson(
            "/api/proffi/admin/ai-lab/versions/{$import->knowledge_version_id}/publish",
            [],
            $this->headers
        )->assertOk()->assertJsonPath('status', 'published');

        $this->assertContains('течет бачок', $work->fresh()->aliases);

        $this->postJson('/api/proffi/admin/ai-lab/retrieve', [
            'text' => 'у меня течет бачок',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('works.0.work_id', $work->id)
            ->assertJsonPath('knowledge_version_id', $import->knowledge_version_id);
    }

    private function createImport(): AiKnowledgeImport
    {
        $response = $this->postJson('/api/proffi/admin/ai-lab/imports', [
            'source_name' => 'Test',
            'text' => "течет бачок\nремонт унитаза",
        ], $this->headers)->assertCreated();

        return AiKnowledgeImport::findOrFail($response->json('id'));
    }
}
