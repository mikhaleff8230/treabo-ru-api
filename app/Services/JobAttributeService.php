<?php

namespace App\Services;

use App\Models\CategoryAttribute;
use App\Models\Job;
use App\Models\JobAttributeValue;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class JobAttributeService
{
    public function valuesForJob(int|string $jobId): array
    {
        $job = Job::with('attributeValues.attribute')->findOrFail($jobId);

        return $job->attributeValues
            ->filter(fn (JobAttributeValue $value) => $value->attribute !== null)
            ->map(fn (JobAttributeValue $value) => $this->mapValue($value))
            ->values()
            ->all();
    }

    public function save(int|string $jobId, array $values): array
    {
        $job = Job::findOrFail($jobId);
        $categoryId = $this->jobCategoryId($job);

        if (!$categoryId) {
            throw ValidationException::withMessages([
                'category_id' => ['У заказа не указана категория.'],
            ]);
        }

        $attributes = CategoryAttribute::where('category_id', $categoryId)
            ->orderBy('sort_order')
            ->get();

        if ($attributes->isEmpty()) {
            throw ValidationException::withMessages([
                'values' => ['Для категории заказа не настроены атрибуты.'],
            ]);
        }

        $normalizedInput = $this->normalizeIncomingValues($values);
        $attributesById = $attributes->keyBy('id');
        $attributesByCode = $attributes->keyBy('code');
        $existing = JobAttributeValue::where('job_id', $job->id)
            ->whereIn('category_attribute_id', $attributes->pluck('id'))
            ->get()
            ->keyBy('category_attribute_id');

        $this->validateRequiredValues($attributes, $normalizedInput, $existing);

        foreach ($normalizedInput as $key => $rawValue) {
            $attribute = is_numeric($key)
                ? $attributesById->get((int) $key)
                : $attributesByCode->get((string) $key);

            if (!$attribute) {
                throw ValidationException::withMessages([
                    "values.$key" => ['Атрибут не найден в категории заказа.'],
                ]);
            }

            $payload = $this->typedPayload($attribute, $rawValue, "values.$key");

            JobAttributeValue::updateOrCreate(
                [
                    'job_id' => $job->id,
                    'category_attribute_id' => $attribute->id,
                ],
                $payload
            );
        }

        return $this->valuesForJob($job->id);
    }

    public function schemaForCategory(int|string $categoryId): array
    {
        return CategoryAttribute::where('category_id', $categoryId)
            ->where('show_in_form', true)
            ->orderByDesc('ai_priority')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (CategoryAttribute $attribute) => [
                'id' => $attribute->id,
                'code' => $attribute->code,
                'name_ru' => $attribute->name_ru,
                'name_ro' => $attribute->name_ro ?: $attribute->name_ru,
                'type' => $attribute->type,
                'unit' => $attribute->unit,
                'required' => (bool) $attribute->required,
                'ai_priority' => (int) $attribute->ai_priority,
                'show_to_master' => (bool) $attribute->show_to_master,
                'help_text_ru' => $attribute->help_text_ru,
                'help_text_ro' => $attribute->help_text_ro,
                'options' => $attribute->options ?: [],
                'validation_rules' => $attribute->validation_rules ?: [],
            ])
            ->values()
            ->all();
    }

    private function normalizeIncomingValues(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $item) {
            if (is_array($item) && (array_key_exists('value', $item) || array_key_exists('code', $item) || array_key_exists('category_attribute_id', $item))) {
                $attributeKey = $item['category_attribute_id'] ?? $item['code'] ?? $key;
                $normalized[$attributeKey] = $item['value'] ?? null;
                continue;
            }

            $normalized[$key] = $item;
        }

        return $normalized;
    }

    private function validateRequiredValues(Collection $attributes, array $incoming, Collection $existing): void
    {
        $missing = [];

        foreach ($attributes->where('required', true) as $attribute) {
            $hasIncoming = array_key_exists($attribute->code, $incoming) || array_key_exists((string) $attribute->id, $incoming) || array_key_exists($attribute->id, $incoming);
            $hasExisting = $existing->has($attribute->id);

            if (!$hasIncoming && !$hasExisting) {
                $missing["values.$attribute->code"] = ["Поле \"$attribute->name_ru\" обязательно."];
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    private function typedPayload(CategoryAttribute $attribute, mixed $value, string $path): array
    {
        $payload = [
            'value_text' => null,
            'value_number' => null,
            'value_boolean' => null,
            'value_json' => null,
        ];

        if ($value === null || $value === '') {
            if ($attribute->required) {
                throw ValidationException::withMessages([
                    $path => ["Поле \"$attribute->name_ru\" обязательно."],
                ]);
            }

            return $payload;
        }

        return match ($attribute->type) {
            'text', 'textarea' => array_merge($payload, ['value_text' => (string) $value]),
            'date' => array_merge($payload, ['value_text' => $this->validateDate($value, $path)]),
            'number' => array_merge($payload, ['value_number' => $this->validateNumber($value, $path)]),
            'boolean' => array_merge($payload, ['value_boolean' => $this->validateBoolean($value, $path)]),
            'select' => array_merge($payload, ['value_json' => $this->validateSelect($attribute, $value, $path)]),
            'multiselect' => array_merge($payload, ['value_json' => $this->validateMultiSelect($attribute, $value, $path)]),
            'file' => array_merge($payload, ['value_json' => is_array($value) ? array_values($value) : [$value]]),
            default => throw ValidationException::withMessages([
                $path => ["Неизвестный тип атрибута \"$attribute->type\"."],
            ]),
        };
    }

    private function validateNumber(mixed $value, string $path): float
    {
        if (!is_numeric($value)) {
            throw ValidationException::withMessages([
                $path => ['Значение должно быть числом.'],
            ]);
        }

        return (float) $value;
    }

    private function validateBoolean(mixed $value, string $path): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [1, '1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($value, [0, '0', 'false', 'no', 'off'], true)) {
            return false;
        }

        throw ValidationException::withMessages([
            $path => ['Значение должно быть true/false.'],
        ]);
    }

    private function validateDate(mixed $value, string $path): string
    {
        $date = strtotime((string) $value);

        if ($date === false) {
            throw ValidationException::withMessages([
                $path => ['Значение должно быть датой.'],
            ]);
        }

        return date('Y-m-d', $date);
    }

    private function validateSelect(CategoryAttribute $attribute, mixed $value, string $path): string
    {
        if (is_array($value)) {
            throw ValidationException::withMessages([
                $path => ['Для select нужно передать одно значение.'],
            ]);
        }

        $value = (string) $value;
        $this->validateOption($attribute, $value, $path);

        return $value;
    }

    private function validateMultiSelect(CategoryAttribute $attribute, mixed $value, string $path): array
    {
        if (!is_array($value)) {
            throw ValidationException::withMessages([
                $path => ['Для multiselect нужно передать массив значений.'],
            ]);
        }

        $values = array_values(array_map('strval', $value));

        foreach ($values as $option) {
            $this->validateOption($attribute, $option, $path);
        }

        return $values;
    }

    private function validateOption(CategoryAttribute $attribute, string $value, string $path): void
    {
        $allowed = collect($attribute->options ?: [])
            ->map(fn ($option) => is_array($option) ? ($option['value'] ?? $option['id'] ?? $option['label'] ?? null) : $option)
            ->filter(fn ($option) => $option !== null && $option !== '')
            ->map(fn ($option) => (string) $option)
            ->values();

        if ($allowed->isNotEmpty() && !$allowed->contains($value)) {
            throw ValidationException::withMessages([
                $path => ['Значение не входит в список допустимых вариантов.'],
            ]);
        }
    }

    private function mapValue(JobAttributeValue $value): array
    {
        $attribute = $value->attribute;

        return [
            'id' => $value->id,
            'job_id' => (string) $value->job_id,
            'category_attribute_id' => $value->category_attribute_id,
            'code' => $attribute->code,
            'name_ru' => $attribute->name_ru,
            'name_ro' => $attribute->name_ro ?: $attribute->name_ru,
            'type' => $attribute->type,
            'unit' => $attribute->unit,
            'value' => $this->extractValue($value),
            'show_to_master' => (bool) $attribute->show_to_master,
            'sort_order' => (int) $attribute->sort_order,
        ];
    }

    private function extractValue(JobAttributeValue $value): mixed
    {
        return match ($value->attribute->type) {
            'number' => $value->value_number,
            'boolean' => $value->value_boolean,
            'select', 'multiselect', 'file' => $value->value_json,
            default => $value->value_text,
        };
    }

    private function jobCategoryId(Job $job): ?string
    {
        return $job->category_id ?: ($job->category ? (string) $job->category : null);
    }
}
