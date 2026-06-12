<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobAttributeValue extends Model
{
    protected $guarded = [];

    protected $casts = [
        'value_number' => 'float',
        'value_boolean' => 'boolean',
        'value_json' => 'array',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(CategoryAttribute::class, 'category_attribute_id');
    }
}
