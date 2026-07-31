<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiKnowledgeSource extends Model
{
    protected $guarded = [];

    public function imports(): HasMany
    {
        return $this->hasMany(AiKnowledgeImport::class, 'source_id');
    }
}
