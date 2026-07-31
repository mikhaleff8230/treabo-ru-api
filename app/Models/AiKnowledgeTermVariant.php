<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiKnowledgeTermVariant extends Model
{
    protected $guarded = [];

    protected $casts = [
        'frequency' => 'integer',
        'confidence' => 'float',
        'evidence' => 'array',
    ];

    public function term(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeTerm::class, 'term_id');
    }
}
