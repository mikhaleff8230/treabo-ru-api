<?php

namespace App\Services\AiKnowledge;

use App\Models\AiKnowledgeImport;
use App\Models\AiKnowledgeSource;
use App\Models\AiKnowledgeSourceRow;
use Illuminate\Support\Facades\DB;

class KnowledgeImportService
{
    public function __construct(
        private readonly KnowledgeTextNormalizer $normalizer,
        private readonly KnowledgeVersionManager $versionManager,
    ) {
    }

    public function createTextImport(array $data, ?int $userId): AiKnowledgeImport
    {
        $rows = $this->normalizer->parse($data['text'], $data['region'] ?? null);
        $maxRows = max(1, (int) config('ai_knowledge.max_rows_per_import', 10000));

        if (count($rows) > $maxRows) {
            throw new \InvalidArgumentException("Импорт содержит больше {$maxRows} строк.");
        }

        if (!$rows) {
            throw new \InvalidArgumentException('Не найдено ни одной непустой фразы.');
        }

        return DB::transaction(function () use ($data, $userId, $rows) {
            $source = AiKnowledgeSource::create([
                'name' => $data['source_name'] ?? 'Ручной импорт '.now()->format('d.m.Y H:i'),
                'type' => $data['source_type'] ?? 'manual_text',
                'description' => $data['description'] ?? null,
                'default_region' => $data['region'] ?? null,
                'trust_level' => $data['trust_level'] ?? 50,
                'created_by' => $userId,
            ]);

            $version = $this->versionManager->createDraft();

            $import = AiKnowledgeImport::create([
                'source_id' => $source->id,
                'knowledge_version_id' => $version->id,
                'status' => 'normalizing',
                'mode' => $data['mode'] ?? 'full_analysis',
                'category_hint' => $data['category_hint'] ?? null,
                'region' => $data['region'] ?? null,
                'settings' => [
                    'auto_analyze' => (bool) ($data['auto_analyze'] ?? false),
                ],
                'rows_total' => count($rows),
                'cost_limit_usd' => $data['cost_limit_usd']
                    ?? config('ai_knowledge.default_cost_limit_usd', 2),
                'created_by' => $userId,
            ]);

            $seen = [];
            $unique = 0;
            foreach ($rows as $row) {
                if (isset($seen[$row['content_hash']])) {
                    continue;
                }
                $seen[$row['content_hash']] = true;
                $unique++;
                AiKnowledgeSourceRow::create(['import_id' => $import->id, ...$row]);
            }

            $import->update([
                'status' => 'uploaded',
                'rows_unique' => $unique,
                'progress' => 10,
            ]);

            return $import->fresh(['source']);
        });
    }
}
