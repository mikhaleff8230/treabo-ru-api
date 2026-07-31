<?php

namespace App\Services\AiKnowledge;

use App\Models\AiKnowledgeDocument;
use App\Models\AiKnowledgeProposal;
use App\Models\AiKnowledgeTerm;
use App\Models\AiKnowledgeTermLink;
use App\Models\AiKnowledgeTermVariant;
use App\Models\AiTrainingExample;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KnowledgeProposalApplier
{
    public function __construct(private readonly KnowledgeTextNormalizer $normalizer)
    {
    }

    public function applyToDraft(AiKnowledgeProposal $proposal): void
    {
        if (!$proposal->knowledge_version_id) {
            throw new \RuntimeException('У предложения отсутствует версия знаний.');
        }

        DB::transaction(function () use ($proposal) {
            $proposal->refresh();
            $payload = $proposal->payload ?? [];
            $phrase = $this->proposalPhrase($proposal, $payload);

            $document = [
                'proposal_type' => $proposal->proposal_type,
                'target_type' => $proposal->target_type,
                'target_id' => $proposal->target_id,
                'title' => $proposal->title,
                'payload' => $payload,
                'evidence' => $proposal->evidence,
            ];
            $content = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            AiKnowledgeDocument::updateOrCreate(
                [
                    'knowledge_version_id' => $proposal->knowledge_version_id,
                    'content_hash' => hash('sha256', (string) $content),
                ],
                [
                    'document_type' => 'expert_note',
                    'entity_type' => $proposal->target_type,
                    'entity_id' => $proposal->target_id,
                    'content' => $content,
                    'status' => 'draft',
                    'metadata' => ['proposal_id' => $proposal->id],
                ]
            );

            if ($phrase !== '') {
                $term = $this->upsertTerm($proposal, $phrase, $payload);
                $this->upsertVariantAndLink($proposal, $term, $phrase, $payload);
                $this->storeTrainingExamples($proposal, $term);
            }
        });
    }

    private function upsertTerm(AiKnowledgeProposal $proposal, string $phrase, array $payload): AiKnowledgeTerm
    {
        $normalized = $this->normalizer->normalize($phrase);
        $stableKey = 'term-'.substr(hash('sha256', $normalized), 0, 32);
        $termType = $payload['term_type'] ?? match ($proposal->proposal_type) {
            'add_negative_alias', 'mark_irrelevant' => 'negative',
            'add_alias' => 'service',
            default => 'unknown',
        };
        $allowedTypes = [
            'service', 'action', 'object', 'problem', 'material', 'parameter',
            'brand', 'informational', 'negative', 'unknown',
        ];

        return AiKnowledgeTerm::updateOrCreate(
            [
                'knowledge_version_id' => $proposal->knowledge_version_id,
                'stable_key' => $stableKey,
            ],
            [
                'display_text' => mb_substr($phrase, 0, 255),
                'normalized_text' => mb_substr($normalized, 0, 255),
                'term_type' => in_array($termType, $allowedTypes, true) ? $termType : 'unknown',
                'language' => 'ru',
                'region' => $proposal->import?->region,
                'status' => 'draft',
                'frequency' => $this->evidenceFrequency($proposal),
                'created_from_proposal_id' => $proposal->id,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'metadata' => ['title' => $proposal->title],
            ]
        );
    }

    private function upsertVariantAndLink(
        AiKnowledgeProposal $proposal,
        AiKnowledgeTerm $term,
        string $phrase,
        array $payload
    ): void {
        $normalized = $this->normalizer->normalize($phrase);
        AiKnowledgeTermVariant::updateOrCreate(
            ['term_id' => $term->id, 'normalized_text' => mb_substr($normalized, 0, 500)],
            [
                'variant_text' => mb_substr($phrase, 0, 500),
                'variant_type' => $payload['variant_type'] ?? 'search_phrase',
                'frequency' => $this->evidenceFrequency($proposal),
                'confidence' => $proposal->confidence,
                'source_row_id' => $this->firstEvidenceRowId($proposal),
                'evidence' => $proposal->evidence,
            ]
        );

        if (!$proposal->target_type || !$proposal->target_id) {
            return;
        }

        $relation = match ($proposal->proposal_type) {
            'add_negative_alias', 'mark_irrelevant' => 'excludes',
            'add_alias' => 'alias_of',
            default => $payload['relation'] ?? 'indicates',
        };

        AiKnowledgeTermLink::updateOrCreate(
            [
                'knowledge_version_id' => $proposal->knowledge_version_id,
                'term_id' => $term->id,
                'target_type' => $proposal->target_type,
                'target_id' => (string) $proposal->target_id,
                'relation' => $relation,
            ],
            [
                'weight' => max(0, min(1, (float) ($payload['weight'] ?? $proposal->confidence))),
                'status' => 'draft',
                'created_from_proposal_id' => $proposal->id,
            ]
        );
    }

    private function storeTrainingExamples(AiKnowledgeProposal $proposal, AiKnowledgeTerm $term): void
    {
        foreach ($proposal->evidence ?? [] as $evidence) {
            $text = $this->normalizer->redactPii((string) ($evidence['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $expected = [
                'target_type' => $proposal->target_type,
                'target_id' => $proposal->target_id,
                'relation' => $proposal->proposal_type,
                'term_id' => $term->id,
            ];
            AiTrainingExample::firstOrCreate(
                ['content_hash' => hash('sha256', $this->normalizer->normalize($text).'|'.json_encode($expected))],
                [
                    'input_text_redacted' => $text,
                    'expected' => $expected,
                    'label_source' => 'accepted_proposal',
                    'quality' => 'silver',
                    'weight' => max(0.1, $proposal->confidence),
                    'knowledge_version_id' => $proposal->knowledge_version_id,
                    'split' => 'train',
                    'consent_confirmed' => false,
                    'metadata' => ['proposal_id' => $proposal->id],
                ]
            );
        }
    }

    private function proposalPhrase(AiKnowledgeProposal $proposal, array $payload): string
    {
        foreach (['alias', 'negative_alias', 'display_text', 'term', 'phrase', 'question'] as $key) {
            if (is_string($payload[$key] ?? null) && trim($payload[$key]) !== '') {
                return trim($payload[$key]);
            }
        }

        return trim((string) collect($proposal->evidence ?? [])->pluck('text')->first());
    }

    private function firstEvidenceRowId(AiKnowledgeProposal $proposal): ?int
    {
        $ids = collect($proposal->evidence ?? [])
            ->pluck('row_id')
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id);
        if ($ids->isEmpty()) {
            return null;
        }

        return $proposal->import?->rows()->whereIn('id', $ids)->value('id');
    }

    private function evidenceFrequency(AiKnowledgeProposal $proposal): int
    {
        $rowIds = collect($proposal->evidence ?? [])->pluck('row_id')->filter()->map(fn ($id) => (int) $id);
        if ($rowIds->isEmpty()) {
            return 0;
        }

        return (int) $proposal->import?->rows()->whereIn('id', $rowIds)->sum('frequency');
    }
}
