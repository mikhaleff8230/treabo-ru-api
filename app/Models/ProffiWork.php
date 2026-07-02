<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProffiWork extends Model
{
    protected $table = 'proffi_works';

    protected $guarded = [];

    protected $casts = [
        'aliases' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProffiCategory::class, 'category_id', 'id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ProffiWorkQuestion::class, 'work_id');
    }
}
