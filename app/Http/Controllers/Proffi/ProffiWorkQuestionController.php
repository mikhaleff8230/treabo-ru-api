<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Models\ProffiWork;
use App\Models\ProffiWorkQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProffiWorkQuestionController extends Controller
{
    private const TYPES = ['text', 'textarea', 'number', 'yesno', 'select', 'multiselect', 'photo'];

    public function index(Request $request)
    {
        $query = ProffiWorkQuestion::query()
            ->with(['work.category'])
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($request->filled('work_id')) {
            $query->where('work_id', $request->integer('work_id'));
        }

        if ($request->filled('category_id')) {
            $categoryId = $request->string('category_id');
            $query->whereHas('work', fn ($q) => $q->where('category_id', $categoryId));
        }

        return $query->limit(1000)->get()->map(function (ProffiWorkQuestion $question) {
            $data = $question->toArray();
            $data['category_id'] = $question->work?->category_id;

            return $data;
        });
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        return response()->json(ProffiWorkQuestion::create($data), 201);
    }

    public function update(Request $request, ProffiWorkQuestion $question)
    {
        $question->update($this->validated($request));

        $fresh = $question->fresh()->load(['work.category']);
        $data = $fresh->toArray();
        $data['category_id'] = $fresh->work?->category_id;

        return $data;
    }

    public function destroy(ProffiWorkQuestion $question)
    {
        $question->delete();

        return ['ok' => true];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'work_id' => ['required', 'integer', 'exists:proffi_works,id'],
            'question' => ['required', 'string', 'max:2000'],
            'field_key' => ['nullable', 'string', 'max:128'],
            'type' => ['required', Rule::in(self::TYPES)],
            'options' => ['nullable', 'array'],
            'options.*' => ['string', 'max:255'],
            'placeholder' => ['nullable', 'string', 'max:255'],
            'help_text' => ['nullable', 'string', 'max:2000'],
            'is_required' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['is_required'] = $data['is_required'] ?? false;
        $data['is_active'] = $data['is_active'] ?? true;
        $data['options'] = $data['options'] ?? null;
        if (empty($data['field_key'])) {
            $base = Str::slug($data['question'], '_') ?: 'question';
            $fieldKey = mb_substr($base, 0, 112);
            $candidate = $fieldKey;
            $suffix = 2;
            while (ProffiWorkQuestion::where('work_id', $data['work_id'])->where('field_key', $candidate)->exists()) {
                $candidate = $fieldKey . '_' . $suffix++;
            }
            $data['field_key'] = $candidate;
        }

        return $data;
    }
}
