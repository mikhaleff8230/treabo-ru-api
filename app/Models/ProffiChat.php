<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Marvel\Database\Models\User;

class ProffiChat extends Model
{
    protected $table = 'proffi_chats';
    protected $guarded = [];
    protected $casts = ['last_message_at' => 'datetime'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProffiTask::class, 'task_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ProffiApplication::class, 'application_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function specialist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'specialist_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ProffiMessage::class, 'chat_id');
    }
}
