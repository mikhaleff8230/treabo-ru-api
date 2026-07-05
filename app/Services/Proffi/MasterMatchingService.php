<?php

namespace App\Services\Proffi;

use App\Http\Controllers\Proffi\Concerns\MapsProffiUsers;
use App\Models\ProffiTask;
use App\Models\ProffiTaskRecommendedSpecialist;
use App\Models\ProffiUserPresence;
use App\Models\TreaboMatchingSetting;
use Illuminate\Support\Collection;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;

class MasterMatchingService
{
    use MapsProffiUsers;

    public function __construct(
        private readonly ProffiCategorySearchService $categorySearch,
    ) {
    }

    public function assignRecommendedSpecialists(ProffiTask $task): void
    {
        $task->loadMissing('work');
        $settings = TreaboMatchingSetting::current();
        $limit = max(1, min(5, (int) $settings->max_recommended));

        $ranked = $this->findCandidates($task, $settings)
            ->map(fn (User $user) => [
                'user' => $user,
                'score' => $this->calculateScore($task, $user, $settings),
            ])
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        ProffiTaskRecommendedSpecialist::where('task_id', $task->id)->delete();

        foreach ($ranked as $index => $item) {
            ProffiTaskRecommendedSpecialist::create([
                'task_id' => $task->id,
                'specialist_id' => $item['user']->id,
                'score' => $item['score'],
                'rank' => $index + 1,
            ]);
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function findCandidates(ProffiTask $task, TreaboMatchingSetting $settings): Collection
    {
        $query = User::with('profile')->permission(Permission::STORE_OWNER);

        if ($task->city) {
            $city = (string) $task->city;
            $query->whereHas(
                'profile',
                fn ($profile) => $profile->where('proffi_city', 'like', '%' . $city . '%')
            );
        }

        $categoryId = $task->category_id ?: $task->category;
        $workTitle = $task->work?->title;
        $terms = $this->categorySearch->matchTerms(
            $categoryId ? (string) $categoryId : null,
            $workTitle ?: null,
        );

        $candidates = $query->limit(200)->get();

        if ($terms) {
            $matchedByServices = $candidates
                ->filter(fn (User $user) => $this->servicesMatchTerms($user, $terms))
                ->values();

            if ($matchedByServices->isNotEmpty()) {
                $candidates = $matchedByServices;
            }
        }

        return $candidates->filter(function (User $user) use ($settings, $task) {
            if ((int) $user->id === (int) $task->customer_id) {
                return false;
            }

            $rating = $this->specialistRatingSummary($user);
            if ((float) $settings->min_rating > 0 && $rating['rating'] < (float) $settings->min_rating) {
                return false;
            }
            if ((int) $settings->min_reviews > 0 && $rating['reviews_count'] < (int) $settings->min_reviews) {
                return false;
            }

            return true;
        });
    }

    private function servicesMatchTerms(User $user, array $terms): bool
    {
        $services = $user->profile?->proffi_services ?? [];

        if (is_string($services)) {
            $decoded = json_decode($services, true);
            $services = is_array($decoded) ? $decoded : [$services];
        }

        if (!is_array($services)) {
            return false;
        }

        $haystack = array_map(
            fn ($service) => mb_strtolower(trim((string) $service)),
            array_filter($services, fn ($service) => $service !== null && $service !== '')
        );

        foreach ($terms as $term) {
            $needle = mb_strtolower(trim((string) $term));
            if ($needle === '') {
                continue;
            }

            foreach ($haystack as $service) {
                if ($service === $needle || str_contains($service, $needle) || str_contains($needle, $service)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function calculateScore(ProffiTask $task, User $user, TreaboMatchingSetting $settings): float
    {
        $profile = $user->profile;
        $services = $profile?->proffi_services ?? [];
        if (is_string($services)) {
            $services = json_decode($services, true) ?: [];
        }
        if (!is_array($services)) {
            $services = [];
        }

        $categoryId = (string) ($task->category_id ?: $task->category ?: '');
        $categoryMatch = 0.0;
        if ($categoryId !== '') {
            foreach ($services as $service) {
                if ((string) $service === $categoryId) {
                    $categoryMatch = 1.0;
                    break;
                }
            }
            if ($categoryMatch === 0.0) {
                $terms = $this->categorySearch->matchTerms($categoryId, null);
                foreach ($terms as $term) {
                    foreach ($services as $service) {
                        if (stripos((string) $service, (string) $term) !== false) {
                            $categoryMatch = 1.0;
                            break 2;
                        }
                    }
                }
            }
        }

        $workMatch = 0.0;
        $workTitle = mb_strtolower((string) ($task->work?->title ?? ''));
        if ($workTitle !== '') {
            foreach ($services as $service) {
                if (str_contains(mb_strtolower((string) $service), $workTitle)
                    || str_contains($workTitle, mb_strtolower((string) $service))) {
                    $workMatch = 1.0;
                    break;
                }
            }
        }

        $ratingSummary = $this->specialistRatingSummary($user);
        $ratingFactor = min(1.0, max(0.0, ((float) $ratingSummary['rating']) / 5));
        $reviewsFactor = min(1.0, ((int) $ratingSummary['reviews_count']) / 10);
        $onlineFactor = ProffiUserPresence::isUserOnline((int) $user->id) ? 1.0 : 0.0;
        $profileRelevance = $this->profileRelevanceFactor($task, (string) ($profile?->bio ?? ''));

        return
            $categoryMatch * (float) $settings->category_weight +
            $workMatch * (float) $settings->work_weight +
            $ratingFactor * (float) $settings->rating_weight +
            $reviewsFactor * (float) $settings->reviews_weight +
            $onlineFactor * (float) $settings->online_weight +
            $profileRelevance * (float) $settings->profile_relevance_weight;
    }

    private function profileRelevanceFactor(ProffiTask $task, string $bio): float
    {
        $bio = mb_strtolower(trim($bio));
        if ($bio === '') {
            return 0.0;
        }

        $needles = array_filter([
            mb_strtolower((string) $task->title),
            mb_strtolower((string) ($task->work?->title ?? '')),
            mb_strtolower((string) $task->category),
        ]);

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($bio, $needle)) {
                return 1.0;
            }
        }

        $words = preg_split('/\s+/u', mb_strtolower(strip_tags((string) $task->description))) ?: [];
        $words = array_values(array_filter($words, fn ($word) => mb_strlen($word) >= 4));
        if (!$words) {
            return 0.0;
        }

        $hits = 0;
        foreach (array_slice($words, 0, 12) as $word) {
            if (str_contains($bio, $word)) {
                $hits++;
            }
        }

        return min(1.0, $hits / 3);
    }
}
