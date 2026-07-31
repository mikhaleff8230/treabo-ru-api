<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiKnowledgeProposalQuestion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'options' => 'array',
        'answer' => 'array',
        'answered_at' => 'datetime',
    ];
}
