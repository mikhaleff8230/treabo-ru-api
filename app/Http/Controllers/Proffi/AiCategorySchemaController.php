<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use App\Services\JobAttributeService;

class AiCategorySchemaController extends Controller
{
    public function __construct(private readonly JobAttributeService $attributes)
    {
    }

    public function show(string $category)
    {
        $serviceCategory = ServiceCategory::where('id', $category)
            ->orWhere('slug', $category)
            ->firstOrFail();

        return [
            'category' => [
                'id' => (string) $serviceCategory->id,
                'parent_id' => $serviceCategory->parent_id,
                'slug' => $serviceCategory->slug,
                'name_ru' => $serviceCategory->name_ru,
                'name_ro' => $serviceCategory->name_ro ?: $serviceCategory->name_ru,
            ],
            'attributes' => $this->attributes->schemaForCategory($serviceCategory->id),
        ];
    }
}
