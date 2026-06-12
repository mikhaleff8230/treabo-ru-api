<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Models\ProffiCategory;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\Category as MarvelCategory;

class CategoryController extends Controller
{
    public function index()
    {
        $query = ProffiCategory::query();

        if (Schema::hasColumn('proffi_categories', 'is_active')) {
            $query->where('is_active', true);
        }

        if (Schema::hasColumn('proffi_categories', 'sort_order')) {
            $query->orderBy('sort_order');
        }

        $categories = $query->orderBy('name_ru')->get();

        if ($categories->isEmpty()) {
            return MarvelCategory::query()
                ->published()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->limit(24)
                ->get()
                ->map(fn (MarvelCategory $category) => [
                    'id' => (string) ($category->slug ?: $category->id),
                    'icon' => 'Briefcase',
                    'name_ru' => $category->name,
                    'name_ro' => $category->name,
                    'slug' => $category->slug,
                    'parent_id' => null,
                    'is_active' => true,
                    'sort_order' => (int) $category->sort_order,
                ])
                ->values();
        }

        return $categories->map(fn (ProffiCategory $category) => $this->mapCategory($category))->values();
    }

    public function stories()
    {
        return $this->index()->take(8)->values()->map(fn ($category) => [
            'id' => (string) $category['id'],
            'title_ru' => $category['name_ru'],
            'title_ro' => $category['name_ro'],
            'color' => '#EDE9FE',
        ]);
    }

    public function mapCategory(ProffiCategory $category): array
    {
        return [
            'id' => (string) $category->id,
            'icon' => $category->icon ?: 'Briefcase',
            'name_ru' => $category->name_ru,
            'name_ro' => $category->name_ro ?: $category->name_ru,
            'slug' => $category->slug,
            'parent_id' => $category->parent_id,
            'is_active' => (bool) ($category->is_active ?? true),
            'sort_order' => (int) ($category->sort_order ?? 0),
        ];
    }

    public static function categoryId(string $name): string
    {
        return Str::slug($name) ?: Str::lower(Str::random(8));
    }
}
