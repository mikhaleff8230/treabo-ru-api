<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiKnowledgeTerm extends Model
{
    protected $guarded = [];

    protected $casts = [
        'frequency' => 'integer',
        'use_count' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeVersion::class, 'knowledge_version_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(AiKnowledgeTermVariant::class, 'term_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(AiKnowledgeTermLink::class, 'term_id');
    }
}
