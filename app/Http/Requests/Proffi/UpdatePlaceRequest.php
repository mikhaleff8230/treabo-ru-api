<?php

namespace App\Http\Requests\Proffi;

use App\Models\ProffiWork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePlaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        if (!$this->has('images') || !is_array($this->input('images'))) {
            return;
        }

        $this->merge([
            'images' => array_map(
                fn ($image) => is_string($image) ? ['url' => $image] : $image,
                $this->input('images')
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'category_id' => ['sometimes', 'string', 'max:64', 'exists:proffi_categories,id'],
            'work_id' => ['sometimes', 'integer', 'exists:proffi_works,id'],
            'price' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999999'],
            'hide_price' => ['sometimes', 'boolean'],
            'city' => ['sometimes', 'string', 'max:128'],
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:russia_locations,id'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'duration_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'hidden', 'archived'])],
            'source_task_id' => ['sometimes', 'nullable', 'integer', 'exists:proffi_tasks,id'],
            'images' => ['sometimes', 'array', 'max:10'],
            'images.*' => ['required', 'array'],
            'images.*.url' => ['required', 'string', 'max:2048'],
            'images.*.thumbnail_url' => ['nullable', 'string', 'max:2048'],
            'images.*.is_cover' => ['nullable', 'boolean'],
            'images.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'images.*.width' => ['nullable', 'integer', 'min:1'],
            'images.*.height' => ['nullable', 'integer', 'min:1'],
            'images.*.file_size' => ['nullable', 'integer', 'min:0'],
            'images.*.mime_type' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $place = $this->route('place');
            $categoryId = $this->input('category_id', $place?->category_id);
            $workId = $this->input('work_id', $place?->work_id);
            if ($categoryId && $workId && !ProffiWork::whereKey($workId)->where('category_id', $categoryId)->exists()) {
                $validator->errors()->add('work_id', 'Выбранная работа не относится к категории.');
            }
        });
    }
}
