<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Marvel\Database\Models\User;

class ProffiTask extends Model
{
    protected $table = 'proffi_tasks';
    protected $guarded = [];
    protected $casts = [
        'photos' => 'array',
        'lat' => 'float',
        'lng' => 'float',
        'budget' => 'integer',
        'response_price_mdl' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function acceptedSpecialist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_specialist_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ProffiApplication::class, 'task_id');
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(JobAttributeValue::class, 'job_id');
    }
}
