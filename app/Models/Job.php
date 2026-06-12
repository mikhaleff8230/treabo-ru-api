<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Job extends ProffiTask
{
    public function attributeValues(): HasMany
    {
        return $this->hasMany(JobAttributeValue::class, 'job_id');
    }
}
