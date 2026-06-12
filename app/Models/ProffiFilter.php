<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProffiFilter extends Model
{
    protected $table = 'proffi_filters';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
}
