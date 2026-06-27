<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\User;

class ProffiReview extends Model
{
    protected $table = 'proffi_reviews';
    protected $guarded = [];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProffiTask::class, 'task_id');
    }

    public function specialist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'specialist_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
