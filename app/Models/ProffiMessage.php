<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\User;

class ProffiMessage extends Model
{
    protected $table = 'proffi_messages';
    protected $guarded = [];
    protected $casts = [
        'read_at' => 'datetime',
        'delivered_at' => 'datetime',
        'metadata' => 'array',
    ];
    protected $attributes = [
        'type' => 'text',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(ProffiChat::class, 'chat_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
