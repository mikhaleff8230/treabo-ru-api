<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiKnowledgeImport extends Model
{
    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
        'error' => 'array',
        'cost_limit_usd' => 'float',
        'actual_cost_usd' => 'float',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeSource::class, 'source_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(AiKnowledgeSourceRow::class, 'import_id');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(AiKnowledgeProposal::class, 'import_id');
    }
}
