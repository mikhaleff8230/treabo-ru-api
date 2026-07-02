<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Models\ProffiWork;
use Illuminate\Http\Request;

class ProffiWorkController extends Controller
{
    public function index(Request $request)
    {
        $query = ProffiWork::query()
            ->with('category')
            ->orderBy('sort_order')
            ->orderBy('title');

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->string('category_id'));
        }

        return $query->limit(1000)->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        return response()->json(ProffiWork::create($data), 201);
    }

    public function update(Request $request, ProffiWork $work)
    {
        $work->update($this->validated($request));

        return $work->fresh()->load('category');
    }

    public function destroy(ProffiWork $work)
    {
        $work->delete();

        return ['ok' => true];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'string', 'max:64', 'exists:proffi_categories,id'],
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:128'],
            'aliases' => ['nullable', 'array'],
            'aliases.*' => ['string', 'max:255'],
            'description' => ['nullable', 'string', 'max:6000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['is_active'] = $data['is_active'] ?? true;
        $data['aliases'] = $data['aliases'] ?? null;

        return $data;
    }
}
