<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateJobDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'min:5', 'max:3000'],
            'city_hint' => ['nullable', 'string', 'max:100'],
            'category_hint' => ['nullable', 'string', 'max:100'],
            'language_hint' => ['nullable', 'in:auto,ru'],
        ];
    }
}
