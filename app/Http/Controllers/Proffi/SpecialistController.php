<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Proffi\Concerns\MapsProffiUsers;
use Illuminate\Http\Request;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;

class SpecialistController extends Controller
{
    use MapsProffiUsers;

    public function index(Request $request)
    {
        $query = User::with('profile')->permission(Permission::STORE_OWNER);

        if ($request->filled('city')) {
            $query->whereHas('profile', fn ($profile) => $profile->where('proffi_city', 'like', '%' . $request->query('city') . '%'));
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
