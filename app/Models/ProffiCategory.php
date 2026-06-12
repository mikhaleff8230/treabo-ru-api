<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProffiCategory extends Model
{
    protected $table = 'proffi_categories';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function attributes(): HasMany
    {
        return $this->hasMany(CategoryAttribute::class, 'category_id', 'id');
    }
}
