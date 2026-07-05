<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Proffi\Concerns\MapsProffiUsers;
use App\Models\ProffiCategory;
use App\Models\ProffiReview;
use App\Models\ProffiTask;
use App\Models\ProffiWork;
use App\Models\TreaboMobileUpdateSetting;
use Illuminate\Http\Request;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;

class HomeController extends Controller
{
    use MapsProffiUsers;

    public function stats()
    {
        $categoriesCount = ProffiCategory::query()
            ->when(
                \Illuminate\Support\Facades\Schema::hasColumn('proffi_categories', 'is_active'),
                fn ($q) => $q->where('is_active', true),
            )
            ->count();

        $worksCount = ProffiWork::query()
            ->when(
                \Illuminate\Support\Facades\Schema::hasColumn('proffi_works', 'is_active'),
                fn ($q) => $q->where('is_active', true),
            )
            ->count();

        $reviewsQuery = ProffiReview::query();
        $reviewsCount = (int) $reviewsQuery->count();
        $avgRating = $reviewsCount > 0
            ? round((float) $reviewsQuery->avg('rating'), 1)
            : 0.0;

        $openTasks = ProffiTask::where('status', 'open')->count();

        return [
            'categories_count' => $categoriesCount + $worksCount,
            'reviews_count' => $reviewsCount,
            'average_rating' => $avgRating,
            'open_tasks' => $openTasks,
        ];
    }

    public function topSpecialists(Request $request)
    {
        $limit = min(10, max(1, (int) $request->query('limit', 3)));

        $specialists = User::with('profile')
            ->permission(Permission::STORE_OWNER)
            ->limit(200)
            ->get()
            ->map(function (User $user) {
                $data = $this->publicUser($user);
                $data['identity_status'] = $this->identityStatus($user);

                return $data;
            })
            ->filter(fn (array $item) => ($item['role'] ?? null) === 'specialist')
            ->sortByDesc(fn (array $item) => [$item['rating'], $item['reviews_count']])
            ->take($limit)
            ->values();

        return $specialists;
    }

    public function siteSettings()
    {
        $language = defined('DEFAULT_LANGUAGE') ? DEFAULT_LANGUAGE : 'ru';
        $settings = Settings::getData($language);
        $options = $settings?->options;
        if (is_string($options)) {
            $options = json_decode($options, true);
        }
        if (!is_array($options)) {
            $options = [];
        }

        $assetUrl = fn (?array $asset) => $asset['original'] ?? $asset['thumbnail'] ?? null;

        return [
            'logo_url' => $assetUrl($options['logo'] ?? null),
            'dark_logo_url' => $assetUrl($options['dark_logo'] ?? null),
            'site_title' => $options['siteTitle'] ?? 'Treabo',
        ];
    }

    public function mobileVersion()
    {
        $settings = TreaboMobileUpdateSetting::current();

        return [
            'latest_version' => $settings->latest_version,
            'latest_build' => (int) $settings->latest_build,
            'min_supported_build' => (int) $settings->min_supported_build,
            'force_update' => (bool) $settings->force_update,
            'android_url' => $settings->android_url,
            'ios_url' => $settings->ios_url,
            'release_notes' => $settings->release_notes,
            'is_active' => (bool) $settings->is_active,
        ];
    }
}
