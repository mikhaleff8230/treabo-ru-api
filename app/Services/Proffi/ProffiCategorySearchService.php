<?php

namespace App\Services\Proffi;

use App\Models\ProffiCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ProffiCategorySearchService
{
    private ?Collection $categories = null;

    public function all(): Collection
    {
        if ($this->categories !== null) {
            return $this->categories;
        }

        $query = ProffiCategory::query();

        if (Schema::hasColumn('proffi_categories', 'is_active')) {
            $query->where('is_active', true);
        }

        if (Schema::hasColumn('proffi_categories', 'sort_order')) {
            $query->orderBy('sort_order');
        }

        $this->categories = $query->orderBy('name_ru')->get();

        return $this->categories;
    }

    /**
     * @return list<string>
     */
    public function resolveCategoryIds(?string $categoryId = null, ?string $query = null): array
    {
        $categories = $this->all();
        $ids = [];

        if ($categoryId) {
            $ids = array_merge($ids, $this->expandWithChildren($categories, [(string) $categoryId]));
        }

        $needle = trim((string) $query);
        if ($needle !== '') {
            $normalized = mb_strtolower($needle);

            foreach ($categories as $category) {
                $values = array_filter([
                    (string) $category->id,
                    (string) ($category->slug ?? ''),
                    (string) $category->name_ru,
                    (string) ($category->name_ro ?? ''),
                ]);

                foreach ($values as $value) {
                    if ($value !== '' && str_contains(mb_strtolower($value), $normalized)) {
                        $ids[] = (string) $category->id;
                        break;
                    }
                }
            }

            $ids = $this->expandWithChildren($categories, $ids);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Terms to match against proffi_services JSON (ids, slugs, labels).
     *
     * @return list<string>
     */
    public function matchTerms(?string $categoryId = null, ?string $query = null): array
    {
        $ids = $this->resolveCategoryIds($categoryId, $query);
        if (!$ids) {
            $needle = trim((string) $query);

            return $needle !== '' ? [$needle] : [];
        }

        $categories = $this->all()->whereIn('id', $ids);
        $terms = [];

        foreach ($categories as $category) {
            foreach ([$category->id, $category->slug, $category->name_ru, $category->name_ro] as $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $terms[] = $value;
                }
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * @param  list<string>  $rootIds
     * @return list<string>
     */
    private function expandWithChildren(Collection $categories, array $rootIds): array
    {
        $ids = array_values(array_unique(array_filter($rootIds)));
        $changed = true;

        while ($changed) {
            $changed = false;

            foreach ($categories as $category) {
                $parentId = (string) ($category->parent_id ?? '');
                $categoryId = (string) $category->id;

                if ($parentId !== '' && in_array($parentId, $ids, true) && !in_array($categoryId, $ids, true)) {
                    $ids[] = $categoryId;
                    $changed = true;
                }
            }
        }

        return $ids;
    }
}
