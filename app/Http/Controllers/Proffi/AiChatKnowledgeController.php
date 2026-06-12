<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Models\AiChatKnowledge;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiChatKnowledgeController extends Controller
{
    private const TYPES = ['category', 'work', 'parameter', 'question', 'instruction'];

    public function index(Request $request)
    {
        $query = AiChatKnowledge::query()
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('category_slug')) {
            $query->where('category_slug', $request->string('category_slug'));
        }

        return $query->limit(1000)->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        return response()->json(AiChatKnowledge::create($data), 201);
    }

    public function update(Request $request, AiChatKnowledge $knowledge)
    {
        $knowledge->update($this->validated($request));

        return $knowledge->fresh();
    }

    public function destroy(AiChatKnowledge $knowledge)
    {
        $knowledge->delete();

        return ['ok' => true];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(self::TYPES)],
            'category_slug' => ['nullable', 'string', 'max:128'],
            'work_slug' => ['nullable', 'string', 'max:128'],
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:160'],
            'content' => ['nullable', 'string', 'max:6000'],
            'payload' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['is_active'] = $data['is_active'] ?? true;

        return $data;
    }
}
