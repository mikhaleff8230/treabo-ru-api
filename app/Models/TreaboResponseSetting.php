<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TreaboResponseSetting extends Model
{
    protected $table = 'treabo_response_settings';
    protected $guarded = [];
    protected $casts = [
        'free_daily_limit' => 'integer',
        'default_response_price_mdl' => 'integer',
        'manual_deposit_amount_mdl' => 'integer',
        'is_active' => 'boolean',
    ];

    public static function current(): self
    {
        return self::query()->firstOrCreate(
            ['id' => 1],
            [
                'free_daily_limit' => (int) config('services.treabo_responses.free_daily_limit', 5),
                'default_response_price_mdl' => (int) config('services.treabo_responses.default_price_mdl', 15),
                'manual_deposit_amount_mdl' => (int) config('services.treabo_balance.manual_deposit_amount_mdl', 100),
                'manual_deposit_url' => config('services.treabo_balance.manual_payment_url'),
                'is_active' => true,
            ]
        );
    }
}
