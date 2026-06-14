<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Marvel\Database\Models\User;

class BalanceDeposit extends Model
{
    protected $fillable = [
        'seller_id',
        'amount',
        'payment_id',
        'status',
        'paid_at',
        'reported_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'reported_at' => 'datetime',
    ];

    /**
     * Связь с продавцом
     */
    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /**
     * Связь с магазином продавца
     */
    public function shop()
    {
        return $this->hasOneThrough(
            \Marvel\Database\Models\Shop::class,
            User::class,
            'id',
            'owner_id',
            'seller_id',
            'id'
        );
    }
}

