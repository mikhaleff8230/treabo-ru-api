<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiKnowledgeVersion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metrics' => 'array',
        'publication_report' => 'array',
        'published_at' => 'datetime',
    ];

    public function terms(): HasMany
    {
        return $this->hasMany(AiKnowledgeTerm::class, 'knowledge_version_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AiKnowledgeDocument::class, 'knowledge_version_id');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(AiKnowledgeProposal::class, 'knowledge_version_id');
    }
}
