<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Profile extends Model
{
    protected $table = 'user_profiles';

    public $guarded = [];

    protected $casts = [
        'socials' => 'json',
        'avatar' => 'json',
        'notifications' => 'json',
        'proffi_services' => 'json',
        'phone_verified' => 'boolean',
        'phone_verified_at' => 'datetime',
        'contract_read' => 'boolean',
        'contract_accepted' => 'boolean',
        'contract_signed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
