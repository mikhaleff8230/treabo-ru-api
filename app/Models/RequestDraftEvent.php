<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestDraftEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
    ];
}
