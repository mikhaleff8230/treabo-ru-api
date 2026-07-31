<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequestDraft extends Model
{
    use HasUlids;

    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    protected $casts = [
        'snapshot' => 'array',
        'version' => 'integer',
        'ai_calls_count' => 'integer',
        'questions_asked_count' => 'integer',
        'meaningless_turns_count' => 'integer',
        'estimated_cost_usd' => 'float',
        'expires_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(RequestDraftMessage::class, 'draft_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(RequestDraftAnswer::class, 'draft_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RequestDraftEvent::class, 'draft_id');
    }
}
