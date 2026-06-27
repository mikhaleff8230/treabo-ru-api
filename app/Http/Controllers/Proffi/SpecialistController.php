<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Proffi\Concerns\MapsProffiUsers;
use App\Services\Proffi\ProffiCategorySearchService;
use Illuminate\Http\Request;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;

class SpecialistController extends Controller
{
    use MapsProffiUsers;

    public function __construct(
        private readonly ProffiCategorySearchService $categorySearch,
    ) {
    }

    public function index(Request $request)
    {
        $query = User::with('profile')->permission(Permission::STORE_OWNER);

        if ($request->filled('city')) {
            $query->whereHas(
                'profile',
                fn ($profile) => $profile->where('proffi_city', 'like', '%' . $request->query('city') . '%')
            );
        }

        $searchQuery = $request->query('q') ?: $request->query('service');
        $terms = $this->categorySearch->matchTerms(
            $request->query('category_id'),
            is_string($searchQuery) ? $searchQuery : null,
        );

        if ($terms) {
            $query->whereHas('profile', function ($profile) use ($terms) {
                $profile->where(function ($inner) use ($terms) {
                    foreach ($terms as $term) {
                        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
                        $inner
                            ->orWhere('proffi_services', 'like', '%"' . $escaped . '"%')
                            ->orWhere('proffi_services', 'like', '%' . $escaped . '%');
                    }
                });
            });
        }

        return $query->limit(100)->get()->map(fn (User $user) => $this->publicUser($user))->values();
    }

    public function show(User $user)
    {
        if (!$user->getPermissionNames()->contains(Permission::STORE_OWNER)) {
            return response()->json(['detail' => 'Specialist not found'], 404);
        }

        return $this->publicUser($user->load('profile'));
    }
}
