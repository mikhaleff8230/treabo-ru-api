<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Marvel\Database\Models\User;

class ProffiTask extends Model
{
    protected $table = 'proffi_tasks';
    protected $guarded = [];
    protected $casts = [
        'photos' => 'array',
        'ai_details' => 'array',
        'lat' => 'float',
        'lng' => 'float',
        'budget' => 'integer',
        'budget_min' => 'integer',
        'budget_max' => 'integer',
        'response_price_mdl' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function acceptedSpecialist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_specialist_id');
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(ProffiWork::class, 'work_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(RussiaLocation::class, 'location_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ProffiApplication::class, 'task_id');
    }

    public function recommendedSpecialists(): HasMany
    {
        return $this->hasMany(ProffiTaskRecommendedSpecialist::class, 'task_id')->orderBy('rank');
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(JobAttributeValue::class, 'job_id');
    }

    public function displayTitle(): string
    {
        $title = trim(strip_tags((string) $this->title));

        if (!$this->isGenericTitle($title)) {
            return $title;
        }

        $aiDetails = is_array($this->ai_details) ? $this->ai_details : [];
        $promptTitle = $this->titleFromText((string) ($aiDetails['prompt'] ?? ''));
        if ($promptTitle !== '') {
            return $promptTitle;
        }

        $descriptionTitle = $this->titleFromText((string) $this->description);
        if ($descriptionTitle !== '') {
            return $descriptionTitle;
        }

        return $title !== '' ? $title : 'Заявка';
    }

    private function isGenericTitle(string $title): bool
    {
        $normalized = mb_strtolower($title);

        return $normalized === ''
            || str_contains($normalized, 'заявка для специалиста')
            || str_contains($normalized, 'request for specialist');
    }

    private function titleFromText(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        $text = preg_replace('/^(запрос клиента|клиент описал задачу так):\s*/iu', '', $text) ?? $text;
        $sentence = preg_split('/[.!?\n]+/u', $text, 2)[0] ?? '';
        $sentence = trim($sentence);

        if ($sentence === '' || $this->isGenericTitle($sentence)) {
            return '';
        }

        return mb_substr($sentence, 0, 96);
    }
}
