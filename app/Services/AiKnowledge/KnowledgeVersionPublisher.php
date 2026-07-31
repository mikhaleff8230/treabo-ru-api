<?php

namespace App\Services\AiKnowledge;

use App\Models\AiKnowledgeProposal;
use App\Models\AiKnowledgeVersion;
use App\Models\ProffiCategory;
use App\Models\ProffiWork;
use App\Models\ProffiWorkQuestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KnowledgeVersionPublisher
{
    public function __construct(private readonly KnowledgeProposalApplier $proposalApplier)
    {
    }

    public function publish(AiKnowledgeVersion $version, ?int $userId): AiKnowledgeVersion
    {
        return DB::transaction(function () use ($version, $userId) {
            $version = AiKnowledgeVersion::query()->lockForUpdate()->findOrFail($version->id);
            if (!in_array($version->status, ['draft', 'testing'], true)) {
                throw new \DomainException('Опубликовать можно только черновую или тестируемую версию.');
            }

            $accepted = $version->proposals()->where('status', 'accepted')->orderBy('id')->get();
            $critical = $accepted->first(fn ($proposal) => $proposal->risk_level === 'critical');
            if ($critical) {
                throw new \DomainException("Критическое предложение #{$critical->id} блокирует публикацию.");
            }

            $report = ['changes' => [], 'proposal_count' => $accepted->count()];
            foreach ($accepted as $proposal) {
                $this->proposalApplier->applyToDraft($proposal);
                $change = $this->applyCatalogChange($proposal);
                if ($change) {
                    $report['changes'][] = $change;
                }
                $proposal->update(['status' => 'published']);
            }

            $previous = AiKnowledgeVersion::query()
                ->where('status', 'published')
                ->where('id', '!=', $version->id)
                ->lockForUpdate()
                ->get();
            foreach ($previous as $published) {
                $published->update(['status' => 'archived']);
            }

            $version->terms()->update(['status' => 'published']);
            $version->documents()->update(['status' => 'published']);
            DB::table('ai_knowledge_term_links')
                ->where('knowledge_version_id', $version->id)
                ->update(['status' => 'published', 'updated_at' => now()]);

            $version->update([
                'status' => 'published',
                'terms_checksum' => $this->checksumTerms($version),
                'documents_checksum' => $this->checksumDocuments($version),
                'index_checksum' => hash('sha256', $this->checksumTerms($version).$this->checksumDocuments($version)),
                'publication_report' => $report,
                'published_by' => $userId,
                'published_at' => now(),
            ]);

            return $version->fresh();
        });
    }

    public function rollback(AiKnowledgeVersion $version, ?int $userId): AiKnowledgeVersion
    {
        return DB::transaction(function () use ($version, $userId) {
            $version = AiKnowledgeVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($version->status !== 'published') {
                throw new \DomainException('Откатить можно только опубликованную версию.');
            }

            foreach (array_reverse($version->publication_report['changes'] ?? []) as $change) {
                $this->revertCatalogChange($change);
            }

            $target = AiKnowledgeVersion::query()
                ->whereKey($version->based_on_version_id)
                ->first();
            if (!$target) {
                $target = AiKnowledgeVersion::query()
                    ->where('status', 'archived')
                    ->where('id', '!=', $version->id)
                    ->latest('published_at')
                    ->first();
            }
            if (!$target) {
                throw new \DomainException('Нет предыдущей версии для отката.');
            }

            $version->update(['status' => 'archived']);
            $target->update([
                'status' => 'published',
                'rollback_of_version_id' => $version->id,
                'published_by' => $userId,
                'published_at' => now(),
            ]);

            return $target->fresh();
        });
    }

    private function applyCatalogChange(AiKnowledgeProposal $proposal): ?array
    {
        $payload = $proposal->payload ?? [];

        if ($proposal->proposal_type === 'add_alias' && $proposal->target_type === 'service') {
            $work = ProffiWork::findOrFail((int) $proposal->target_id);
            $alias = trim((string) ($payload['alias'] ?? ''));
            if ($alias === '') {
                throw new \DomainException("В предложении #{$proposal->id} отсутствует alias.");
            }
            $before = $work->aliases ?? [];
            $after = array_values(array_unique([...$before, $alias]));
            $work->update(['aliases' => $after]);

            return ['type' => 'work_aliases', 'id' => $work->id, 'before' => $before, 'after' => $after];
        }

        if ($proposal->proposal_type === 'create_category') {
            $name = trim((string) ($payload['name_ru'] ?? $payload['title'] ?? ''));
            if ($name === '') {
                throw new \DomainException("В предложении #{$proposal->id} отсутствует name_ru.");
            }
            $base = Str::slug($name) ?: 'category';
            $id = $base;
            $suffix = 2;
            while (ProffiCategory::whereKey($id)->exists()) {
                $id = $base.'-'.$suffix++;
            }
            ProffiCategory::create([
                'id' => $id,
                'parent_id' => $payload['parent_id'] ?? null,
                'slug' => $id,
                'icon' => 'MoreHorizontal',
                'name_ru' => $name,
                'name_ro' => $payload['name_ro'] ?? $name,
                'is_active' => true,
                'sort_order' => 0,
            ]);

            return ['type' => 'created_category', 'id' => $id];
        }

        if ($proposal->proposal_type === 'create_service') {
            $title = trim((string) ($payload['title'] ?? $payload['name'] ?? ''));
            $categoryId = (string) ($payload['category_id'] ?? $proposal->target_id ?? '');
            if ($title === '' || !ProffiCategory::whereKey($categoryId)->exists()) {
                throw new \DomainException("Предложение #{$proposal->id} не содержит корректную работу и категорию.");
            }
            $work = ProffiWork::create([
                'category_id' => $categoryId,
                'title' => $title,
                'slug' => Str::slug($title),
                'aliases' => array_values(array_filter($payload['aliases'] ?? [])),
                'description' => $payload['description'] ?? null,
                'sort_order' => 0,
                'is_active' => true,
            ]);

            return ['type' => 'created_service', 'id' => $work->id];
        }

        if ($proposal->proposal_type === 'create_question') {
            $workId = (int) ($payload['work_id'] ?? ($proposal->target_type === 'service' ? $proposal->target_id : 0));
            $questionText = trim((string) ($payload['question'] ?? ''));
            if (!$workId || $questionText === '' || !ProffiWork::whereKey($workId)->exists()) {
                throw new \DomainException("Предложение #{$proposal->id} не содержит корректный вопрос и work_id.");
            }
            $type = in_array($payload['type'] ?? null, [
                'text', 'textarea', 'number', 'yesno', 'select', 'multiselect', 'photo',
            ], true) ? $payload['type'] : 'text';
            $question = ProffiWorkQuestion::create([
                'work_id' => $workId,
                'question' => $questionText,
                'field_key' => $payload['field_key'] ?? 'q_'.Str::slug($questionText, '_'),
                'type' => $type,
                'options' => is_array($payload['options'] ?? null) ? $payload['options'] : null,
                'placeholder' => $payload['placeholder'] ?? null,
                'help_text' => $payload['help_text'] ?? null,
                'is_required' => (bool) ($payload['is_required'] ?? false),
                'sort_order' => 0,
                'is_active' => true,
            ]);

            return ['type' => 'created_question', 'id' => $question->id];
        }

        return null;
    }

    private function revertCatalogChange(array $change): void
    {
        match ($change['type'] ?? null) {
            'work_aliases' => ProffiWork::whereKey($change['id'])->update(['aliases' => $change['before'] ?? []]),
            'created_category' => ProffiCategory::whereKey($change['id'])->update(['is_active' => false]),
            'created_service' => ProffiWork::whereKey($change['id'])->update(['is_active' => false]),
            'created_question' => ProffiWorkQuestion::whereKey($change['id'])->update(['is_active' => false]),
            default => null,
        };
    }

    private function checksumTerms(AiKnowledgeVersion $version): string
    {
        return hash('sha256', $version->terms()->orderBy('stable_key')->get()->toJson());
    }

    private function checksumDocuments(AiKnowledgeVersion $version): string
    {
        return hash('sha256', $version->documents()->orderBy('content_hash')->pluck('content_hash')->implode('|'));
    }
}
