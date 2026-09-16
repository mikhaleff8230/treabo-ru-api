<?php

namespace App\Http\Requests\Proffi;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPlacesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'category_id' => ['nullable', 'string', 'max:64', 'exists:proffi_categories,id'],
            'category' => ['nullable', 'string', 'max:64'],
            'work_id' => ['nullable', 'integer', 'exists:proffi_works,id'],
            'work' => ['nullable', 'integer', 'exists:proffi_works,id'],
            'city' => ['nullable', 'string', 'max:128'],
            'sw_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:sw_lng,ne_lat,ne_lng'],
            'sw_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:sw_lat,ne_lat,ne_lng'],
            'ne_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:sw_lat,sw_lng,ne_lng'],
            'ne_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:sw_lat,sw_lng,ne_lat'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'price_from' => ['nullable', 'integer', 'min:0'],
            'price_to' => ['nullable', 'integer', 'min:0', 'gte:price_from'],
            'with_photo' => ['nullable', 'boolean'],
            'author' => ['nullable', 'integer', 'exists:users,id'],
            'favorites' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', Rule::in(['new', 'nearby', 'popular'])],
        ];
    }
}
