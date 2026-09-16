<?php

namespace App\Http\Requests\Proffi;

use App\Models\ProffiWork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePlaceRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'category_id' => ['required', 'string', 'max:64', 'exists:proffi_categories,id'],
            'work_id' => ['required', 'integer', 'exists:proffi_works,id'],
            'price' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'hide_price' => ['sometimes', 'boolean'],
            'city' => ['required', 'string', 'max:128'],
            'location_id' => ['nullable', 'integer', 'exists:russia_locations,id'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
            'source_task_id' => ['nullable', 'integer', 'exists:proffi_tasks,id'],
            'images' => ['nullable', 'array', 'max:10'],
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
            $categoryId = $this->input('category_id');
            $workId = $this->input('work_id');
            if ($categoryId && $workId && !ProffiWork::whereKey($workId)->where('category_id', $categoryId)->exists()) {
                $validator->errors()->add('work_id', 'Выбранная работа не относится к категории.');
            }
        });
    }
}
