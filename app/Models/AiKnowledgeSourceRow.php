<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiKnowledgeSourceRow extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'frequency' => 'integer',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeImport::class, 'import_id');
    }
}
