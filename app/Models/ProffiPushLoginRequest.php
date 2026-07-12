<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProffiPushLoginRequest extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['expires_at' => 'datetime', 'approved_at' => 'datetime'];
}
