<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TreaboMatchingSetting extends Model
{
    protected $table = 'treabo_matching_settings';
    protected $guarded = [];
    protected $casts = [
        'category_weight' => 'integer',
        'work_weight' => 'integer',
        'rating_weight' => 'integer',
        'reviews_weight' => 'integer',
        'online_weight' => 'integer',
        'profile_relevance_weight' => 'integer',
        'min_rating' => 'float',
        'min_reviews' => 'integer',
        'max_recommended' => 'integer',
        'is_active' => 'boolean',
    ];

    public static function current(): self
    {
        return self::query()->firstOrCreate(
            ['id' => 1],
            [
                'category_weight' => 30,
                'work_weight' => 25,
                'rating_weight' => 20,
                'reviews_weight' => 10,
                'online_weight' => 10,
                'profile_relevance_weight' => 5,
                'min_rating' => 0,
                'min_reviews' => 0,
                'max_recommended' => 5,
                'is_active' => true,
            ]
        );
    }
}
