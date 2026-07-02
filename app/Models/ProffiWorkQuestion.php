<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProffiWorkQuestion extends Model
{
    protected $table = 'proffi_work_questions';

    protected $guarded = [];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function work(): BelongsTo
    {
        return $this->belongsTo(ProffiWork::class, 'work_id');
    }
}
