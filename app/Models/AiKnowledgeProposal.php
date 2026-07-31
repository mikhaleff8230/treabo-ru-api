<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiKnowledgeProposal extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'evidence' => 'array',
        'confidence' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeImport::class, 'import_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(AiKnowledgeProposalQuestion::class, 'proposal_id');
    }
}
