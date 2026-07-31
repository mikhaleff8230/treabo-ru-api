<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiKnowledgeTermLink extends Model
{
    protected $guarded = [];

    protected $casts = [
        'weight' => 'float',
    ];

    public function term(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeTerm::class, 'term_id');
    }
}
