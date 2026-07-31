<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProffiQuestionRule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'conditions' => 'array',
        'actions' => 'array',
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    public function work(): BelongsTo
    {
        return $this->belongsTo(ProffiWork::class, 'work_id');
    }
}
