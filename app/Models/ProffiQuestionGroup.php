<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProffiQuestionGroup extends Model
{
    protected $guarded = [];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function work(): BelongsTo
    {
        return $this->belongsTo(ProffiWork::class, 'work_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ProffiWorkQuestion::class, 'group_id');
    }
}
