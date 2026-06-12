<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoryAttribute extends Model
{
    protected $guarded = [];

    protected $casts = [
        'required' => 'boolean',
        'show_in_form' => 'boolean',
        'show_to_master' => 'boolean',
        'ai_priority' => 'integer',
        'sort_order' => 'integer',
        'options' => 'array',
        'validation_rules' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id', 'id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(JobAttributeValue::class);
    }
}
