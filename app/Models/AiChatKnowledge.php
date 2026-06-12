<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiChatKnowledge extends Model
{
    protected $table = 'ai_chat_knowledge';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
