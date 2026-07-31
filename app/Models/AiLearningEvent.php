<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiLearningEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'weight' => 'float',
    ];
}
