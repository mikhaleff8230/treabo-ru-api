<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiPromptVersion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'schema' => 'array',
        'settings' => 'array',
        'published_at' => 'datetime',
    ];
}
