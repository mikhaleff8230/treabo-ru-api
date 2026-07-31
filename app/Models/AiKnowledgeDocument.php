<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiKnowledgeDocument extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];
}
