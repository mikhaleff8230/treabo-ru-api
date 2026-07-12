<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProffiPushToken extends Model
{
    protected $guarded = [];
    protected $casts = ['last_seen_at' => 'datetime'];
}
