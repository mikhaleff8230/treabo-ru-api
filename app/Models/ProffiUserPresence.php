<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\User;

class ProffiUserPresence extends Model
{
    protected $table = 'proffi_user_presence';

    protected $guarded = [];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'is_online' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function isUserOnline(int $userId, int $withinMinutes = 2): bool
    {
        $presence = static::where('user_id', $userId)->first();

        if (!$presence || !$presence->is_online || !$presence->last_seen_at) {
            return false;
        }

        return $presence->last_seen_at->greaterThan(now()->subMinutes($withinMinutes));
    }
}
