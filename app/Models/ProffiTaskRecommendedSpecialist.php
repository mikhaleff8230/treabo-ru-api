<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\User;

class ProffiTaskRecommendedSpecialist extends Model
{
    protected $table = 'proffi_task_recommended_specialists';
    protected $guarded = [];
    protected $casts = [
        'score' => 'float',
        'rank' => 'integer',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProffiTask::class, 'task_id');
    }

    public function specialist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'specialist_id');
    }
}
