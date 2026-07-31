<?php

namespace App\Services\AiKnowledge;

use App\Models\AiEvaluationRun;
use App\Models\AiKnowledgeTermLink;
use App\Models\AiKnowledgeVersion;
use App\Models\ProffiCategory;
use App\Models\ProffiWork;

class KnowledgeEvaluationService
{
    public function evaluate(AiKnowledgeVersion $version): AiEvaluationRun
    {
        $started = microtime(true);
        $failures = [];
        $links = AiKnowledgeTermLink::query()
            ->where('knowledge_version_id', $version->id)
            ->get();

        foreach ($links as $link) {
            $valid = match ($link->target_type) {
                'category' => ProffiCategory::whereKey($link->target_id)->where('is_active', true)->exists(),
                'service' => ProffiWork::whereKey((int) $link->target_id)->where('is_active', true)->exists(),
                default => true,
            };
            if (!$valid) {
                $failures[] = [
                    'code' => 'invalid_target',
                    'link_id' => $link->id,
                    'target_type' => $link->target_type,
                    'target_id' => $link->target_id,
                ];
            }
        }

        $critical = $version->proposals()
            ->where('status', 'accepted')
            ->where('risk_level', 'critical')
            ->pluck('id');
        foreach ($critical as $proposalId) {
            $failures[] = ['code' => 'critical_proposal', 'proposal_id' => $proposalId];
        }

        $metrics = [
            'terms' => $version->terms()->count(),
            'documents' => $version->documents()->count(),
            'links' => $links->count(),
            'accepted_proposals' => $version->proposals()->where('status', 'accepted')->count(),
            'invalid_references' => collect($failures)->where('code', 'invalid_target')->count(),
        ];

        $run = AiEvaluationRun::create([
            'knowledge_version_id' => $version->id,
            'baseline_version_id' => $version->based_on_version_id,
            'model' => config('ai_knowledge.model'),
            'prompt_version' => config('ai_knowledge.prompt_version'),
            'status' => $failures ? 'failed' : 'passed',
            'metrics_before' => $version->metrics,
            'metrics_after' => $metrics,
            'failures' => $failures,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $version->update([
            'status' => $failures ? 'draft' : 'testing',
            'metrics' => $metrics,
        ]);

        return $run;
    }
}
