<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiInvocation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'input_tokens' => 'integer',
        'cached_input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cost_usd' => 'float',
        'latency_ms' => 'integer',
        'metadata' => 'array',
    ];
}
