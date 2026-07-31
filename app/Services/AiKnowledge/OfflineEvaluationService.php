<?php

namespace App\Services\AiKnowledge;

use App\Models\AiEvaluationRun;
use App\Models\AiKnowledgeVersion;
use App\Models\AiTrainingExample;

class OfflineEvaluationService
{
    public function __construct(private readonly KnowledgeRetrievalService $retrieval)
    {
    }

    public function run(AiKnowledgeVersion $version): AiEvaluationRun
    {
        $started = microtime(true);
        $examples = AiTrainingExample::query()
            ->whereIn('split', ['validation', 'test'])
            ->whereIn('quality', ['gold', 'silver'])
            ->limit(500)
            ->get();
        $correctWork = 0;
        $correctCategory = 0;
        $failures = [];

        foreach ($examples as $example) {
            $result = $this->retrieval->retrieve(
                $example->input_text_redacted,
                5,
                $version->status === 'published',
                $version
            );
            $top = $result['works'][0] ?? null;
            $expectedWork = $example->expected['service_id'] ?? $example->expected['work_id'] ?? null;
            $expectedCategory = $example->expected['category_id'] ?? null;
            $workOk = $expectedWork && (string) ($top['work_id'] ?? '') === (string) $expectedWork;
            $categoryOk = $expectedCategory && (string) ($top['category_id'] ?? '') === (string) $expectedCategory;
            $correctWork += (int) $workOk;
            $correctCategory += (int) $categoryOk;
            if (!$workOk && count($failures) < 100) {
                $failures[] = [
                    'example_id' => $example->id,
                    'expected_service_id' => $expectedWork,
                    'actual_service_id' => $top['work_id'] ?? null,
                    'text' => mb_substr($example->input_text_redacted, 0, 300),
                ];
            }
        }
        $count = $examples->count();
        $metrics = [
            'examples' => $count,
            'work_top1_accuracy' => $count ? round($correctWork / $count, 4) : null,
            'category_top1_accuracy' => $count ? round($correctCategory / $count, 4) : null,
            'failures_count' => count($failures),
        ];

        return AiEvaluationRun::create([
            'knowledge_version_id' => $version->id,
            'baseline_version_id' => $version->based_on_version_id,
            'model' => 'lexical-retrieval-v1',
            'prompt_version' => config('ai_knowledge.prompt_version'),
            'status' => $count === 0 || ($metrics['work_top1_accuracy'] ?? 0) >= 0.75 ? 'passed' : 'failed',
            'metrics_before' => null,
            'metrics_after' => $metrics,
            'failures' => $failures,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }
}
