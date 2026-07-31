<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiTrainingExample extends Model
{
    protected $guarded = [];

    protected $casts = [
        'expected' => 'array',
        'weight' => 'float',
        'consent_confirmed' => 'boolean',
        'retain_until' => 'datetime',
        'metadata' => 'array',
    ];
}
