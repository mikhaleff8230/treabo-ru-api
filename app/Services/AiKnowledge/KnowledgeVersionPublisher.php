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

            $failures = $this->validateProposals($accepted);
            if ($failures !== []) {
                throw new \DomainException($failures[0]['message']);
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

    /**
     * @return array<int, array{code: string, proposal_id: int, message: string}>
     */
    public function validateVersion(AiKnowledgeVersion $version): array
    {
        return $this->validateProposals(
            $version->proposals()->where('status', 'accepted')->orderBy('id')->get()
        );
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
            $aliases = $this->aliasesFromPayload($payload);
            if ($aliases === []) {
                throw new \DomainException("В предложении #{$proposal->id} отсутствует alias.");
            }
            $before = $work->aliases ?? [];
            $after = $this->uniqueStrings([...$before, ...$aliases]);
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
            $slug = Str::slug($title);
            $aliases = $this->uniqueStrings($payload['aliases'] ?? []);
            $existing = ProffiWork::query()
                ->where('category_id', $categoryId)
                ->where(function ($query) use ($title, $slug) {
                    $query->whereRaw('LOWER(title) = ?', [mb_strtolower($title)]);
                    if ($slug !== '') {
                        $query->orWhere('slug', $slug);
                    }
                })
                ->first();

            if ($existing) {
                $before = [
                    'aliases' => $existing->aliases ?? [],
                    'description' => $existing->description,
                    'is_active' => (bool) $existing->is_active,
                ];
                $after = [
                    'aliases' => $this->uniqueStrings([...(array) $before['aliases'], ...$aliases]),
                    'description' => $existing->description ?: ($payload['description'] ?? null),
                    'is_active' => true,
                ];
                if ($before !== $after) {
                    $existing->update($after);
                }

                return [
                    'type' => 'updated_service',
                    'id' => $existing->id,
                    'before' => $before,
                    'after' => $after,
                ];
            }

            $work = ProffiWork::create([
                'category_id' => $categoryId,
                'title' => $title,
                'slug' => $slug,
                'aliases' => $aliases,
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

    private function validateProposals(iterable $proposals): array
    {
        $failures = [];
        foreach ($proposals as $proposal) {
            $payload = is_array($proposal->payload) ? $proposal->payload : [];
            $message = null;

            if ($proposal->proposal_type === 'add_alias' && $proposal->target_type === 'service') {
                if (!ProffiWork::whereKey((int) $proposal->target_id)->exists()) {
                    $message = "У предложения #{$proposal->id} не найдена целевая работа.";
                } elseif ($this->aliasesFromPayload($payload) === []) {
                    $message = "В предложении #{$proposal->id} отсутствует alias или aliases.";
                }
            } elseif ($proposal->proposal_type === 'create_category') {
                if (trim((string) ($payload['name_ru'] ?? $payload['title'] ?? '')) === '') {
                    $message = "В предложении #{$proposal->id} отсутствует name_ru.";
                }
            } elseif ($proposal->proposal_type === 'create_service') {
                $title = trim((string) ($payload['title'] ?? $payload['name'] ?? ''));
                $categoryId = (string) ($payload['category_id'] ?? $proposal->target_id ?? '');
                if ($title === '' || !ProffiCategory::whereKey($categoryId)->exists()) {
                    $message = "Предложение #{$proposal->id} не содержит корректную работу и категорию.";
                }
            } elseif ($proposal->proposal_type === 'create_question') {
                $workId = (int) ($payload['work_id'] ?? ($proposal->target_type === 'service' ? $proposal->target_id : 0));
                if (!$workId || trim((string) ($payload['question'] ?? '')) === '' || !ProffiWork::whereKey($workId)->exists()) {
                    $message = "Предложение #{$proposal->id} не содержит корректный вопрос и work_id.";
                }
            }

            if ($message !== null) {
                $failures[] = [
                    'code' => 'invalid_proposal_payload',
                    'proposal_id' => (int) $proposal->id,
                    'message' => $message,
                ];
            }
        }

        return $failures;
    }

    private function aliasesFromPayload(array $payload): array
    {
        $aliases = [];
        if (is_string($payload['alias'] ?? null)) {
            $aliases[] = $payload['alias'];
        }
        if (is_array($payload['aliases'] ?? null)) {
            $aliases = [...$aliases, ...$payload['aliases']];
        }

        return $this->uniqueStrings($aliases);
    }

    private function uniqueStrings(iterable $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $trimmed = trim($value);
            $unique[mb_strtolower($trimmed)] = $trimmed;
        }

        return array_values($unique);
    }

    private function revertCatalogChange(array $change): void
    {
        match ($change['type'] ?? null) {
            'work_aliases' => ProffiWork::whereKey($change['id'])->update(['aliases' => $change['before'] ?? []]),
            'created_category' => ProffiCategory::whereKey($change['id'])->update(['is_active' => false]),
            'created_service' => ProffiWork::whereKey($change['id'])->update(['is_active' => false]),
            'updated_service' => ProffiWork::whereKey($change['id'])->update($change['before'] ?? []),
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
