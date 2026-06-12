<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\User;

class ProffiApplication extends Model
{
    protected $table = 'proffi_applications';
    protected $guarded = [];
    protected $casts = [
        'price' => 'integer',
        'response_fee_mdl' => 'integer',
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
