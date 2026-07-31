<?php

namespace App\Services\AiKnowledge;

use App\Models\AiKnowledgeDocument;
use App\Models\AiKnowledgeTerm;
use App\Models\AiKnowledgeTermLink;
use App\Models\AiKnowledgeTermVariant;
use App\Models\AiKnowledgeVersion;
use Illuminate\Support\Facades\DB;

class KnowledgeVersionManager
{
    public function createDraft(?string $label = null): AiKnowledgeVersion
    {
        return DB::transaction(function () use ($label) {
            $existing = AiKnowledgeVersion::query()->where('status', 'draft')->latest('id')->first();
            if ($existing) {
                return $existing;
            }

            $base = AiKnowledgeVersion::query()->where('status', 'published')->latest('published_at')->first();
            $version = AiKnowledgeVersion::create([
                'version' => $label ?: 'draft-'.now()->format('Ymd-His'),
                'based_on_version_id' => $base?->id,
                'status' => 'draft',
            ]);

            if ($base) {
                $this->cloneKnowledge($base, $version);
            }

            return $version->fresh();
        });
    }

    private function cloneKnowledge(AiKnowledgeVersion $base, AiKnowledgeVersion $target): void
    {
        $termMap = [];
        foreach ($base->terms()->with(['variants', 'links'])->get() as $term) {
            $copy = AiKnowledgeTerm::create([
                ...$term->only([
                    'stable_key', 'display_text', 'normalized_text', 'term_type', 'language',
                    'region', 'frequency', 'use_count', 'created_from_proposal_id',
                    'first_seen_at', 'last_seen_at', 'metadata',
                ]),
                'knowledge_version_id' => $target->id,
                'status' => 'draft',
            ]);
            $termMap[$term->id] = $copy->id;

            foreach ($term->variants as $variant) {
                AiKnowledgeTermVariant::create([
                    ...$variant->only([
                        'variant_text', 'normalized_text', 'variant_type', 'frequency',
                        'confidence', 'source_row_id', 'evidence',
                    ]),
                    'term_id' => $copy->id,
                ]);
            }
        }

        foreach (AiKnowledgeTermLink::where('knowledge_version_id', $base->id)->get() as $link) {
            if (!isset($termMap[$link->term_id])) {
                continue;
            }
            AiKnowledgeTermLink::create([
                ...$link->only([
                    'target_type', 'target_id', 'relation', 'weight', 'created_from_proposal_id',
                ]),
                'knowledge_version_id' => $target->id,
                'term_id' => $termMap[$link->term_id],
                'status' => 'draft',
            ]);
        }

        foreach ($base->documents()->get() as $document) {
            AiKnowledgeDocument::create([
                ...$document->only([
                    'document_type', 'entity_type', 'entity_id', 'content', 'content_hash', 'metadata',
                ]),
                'knowledge_version_id' => $target->id,
                'status' => 'draft',
            ]);
        }
    }
}
