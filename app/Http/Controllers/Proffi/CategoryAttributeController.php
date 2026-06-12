<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Models\CategoryAttribute;
use App\Models\ServiceCategory;

class CategoryAttributeController extends Controller
{
    public function index(string $category)
    {
        $serviceCategory = $this->findCategory($category);

        return CategoryAttribute::where('category_id', $serviceCategory->id)
            ->where('show_in_form', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (CategoryAttribute $attribute) => [
                'id' => $attribute->id,
                'category_id' => (string) $attribute->category_id,
                'code' => $attribute->code,
                'name_ru' => $attribute->name_ru,
                'name_ro' => $attribute->name_ro ?: $attribute->name_ru,
                'type' => $attribute->type,
                'unit' => $attribute->unit,
                'required' => (bool) $attribute->required,
                'show_in_form' => (bool) $attribute->show_in_form,
                'show_to_master' => (bool) $attribute->show_to_master,
                'sort_order' => (int) $attribute->sort_order,
                'help_text_ru' => $attribute->help_text_ru,
                'help_text_ro' => $attribute->help_text_ro,
                'options' => $attribute->options ?: [],
                'validation_rules' => $attribute->validation_rules ?: [],
            ])
            ->values();
    }

    private function findCategory(string $category): ServiceCategory
    {
        return ServiceCategory::where('id', $category)
            ->orWhere('slug', $category)
            ->firstOrFail();
    }
}
