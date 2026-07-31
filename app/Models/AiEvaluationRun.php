<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiEvaluationRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metrics_before' => 'array',
        'metrics_after' => 'array',
        'failures' => 'array',
        'cost_usd' => 'float',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'latency_ms' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeVersion::class, 'knowledge_version_id');
    }

    public function baseline(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeVersion::class, 'baseline_version_id');
    }
}
