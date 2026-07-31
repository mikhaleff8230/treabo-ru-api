<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestDraftAnswer extends Model
{
    protected $guarded = [];

    protected $casts = [
        'value' => 'array',
        'confidence' => 'float',
        'is_confirmed' => 'boolean',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(ProffiWorkQuestion::class, 'question_id');
    }
}
