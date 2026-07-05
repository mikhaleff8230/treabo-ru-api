<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Proffi\Concerns\MapsProffiBudget;
use App\Http\Controllers\Proffi\Concerns\MapsProffiUsers;
use App\Models\ProffiApplication;
use App\Models\ProffiCategory;
use App\Models\ProffiFavorite;
use App\Models\ProffiTask;
use App\Models\ProffiChat;
use App\Models\ProffiTaskRecommendedSpecialist;
use App\Models\TreaboResponseSetting;
use App\Services\Proffi\MasterMatchingService;
use App\Services\Proffi\ProffiCategorySearchService;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    use MapsProffiUsers;
    use MapsProffiBudget;

    public function __construct(
        private readonly ProffiCategorySearchService $categorySearch,
        private readonly MasterMatchingService $matchingService,
    ) {
    }

    public function index(Request $request)
    {
        $query = ProffiTask::with(['customer.profile', 'work'])->where('status', 'open');

        $categoryIds = $this->categorySearch->resolveCategoryIds(
            $request->query('category_id') ?: ($request->filled('category') ? (string) $request->query('category') : null),
            $request->filled('category_id') ? null : ($request->filled('q') ? (string) $request->query('q') : null),
        );

        if ($categoryIds) {
            $query->where(function ($inner) use ($categoryIds) {
                $inner->whereIn('category_id', $categoryIds)->orWhereIn('category', $categoryIds);
            });
        } elseif ($request->filled('q')) {
            $q = (string) $request->query('q');
            $query->where(function ($inner) use ($q) {
                $inner->where('title', 'like', "%$q%")->orWhere('description', 'like', "%$q%");
            });
        }

        if ($request->filled('city')) {
            $query->where('city', 'like', '%' . $request->query('city') . '%');
        }

        if ($request->filled('budget_min')) {
            $min = (int) $request->query('budget_min');
            $query->where(function ($inner) use ($min) {
                $inner->where('budget', '>=', $min)
                    ->orWhere('budget_min', '>=', $min)
                    ->orWhere('budget_max', '>=', $min);
            });
        }

        if ($request->filled('budget_max')) {
            $max = (int) $request->query('budget_max');
            $query->where(function ($inner) use ($max) {
                $inner->where('budget', '<=', $max)
                    ->orWhere('budget_max', '<=', $max)
                    ->orWhere('budget_min', '<=', $max);
            });
        }

        if ($request->boolean('favorites')) {
            $userId = $this->resolveOptionalUserId($request);
            if (!$userId) {
                return response()->json(['detail' => 'Authorization required for favorites filter'], 401);
            }
            $favoriteIds = ProffiFavorite::where('user_id', $userId)->pluck('task_id');
            $query->whereIn('id', $favoriteIds);
        }

        $hasBbox = $request->filled('sw_lat')
            && $request->filled('sw_lng')
            && $request->filled('ne_lat')
            && $request->filled('ne_lng');

        if ($hasBbox) {
            $swLat = (float) $request->query('sw_lat');
            $swLng = (float) $request->query('sw_lng');
            $neLat = (float) $request->query('ne_lat');
            $neLng = (float) $request->query('ne_lng');

            $query->whereNotNull('lat')
                ->whereNotNull('lng')
                ->whereBetween('lat', [min($swLat, $neLat), max($swLat, $neLat)])
                ->whereBetween('lng', [min($swLng, $neLng), max($swLng, $neLng)]);
        }

        $userLat = $request->filled('lat') ? (float) $request->query('lat') : null;
        $userLng = $request->filled('lng') ? (float) $request->query('lng') : null;
        $sort = (string) $request->query('sort', '');

        $tasks = $query->latest()->limit($hasBbox ? 200 : 100)->get();

        $userId = $this->resolveOptionalUserId($request);
        $appliedTaskIds = $userId
            ? ProffiApplication::where('specialist_id', $userId)->pluck('task_id')->all()
            : [];
        $favoriteTaskIds = $userId
            ? ProffiFavorite::where('user_id', $userId)->pluck('task_id')->all()
            : [];

        $results = $tasks
            ->map(fn (ProffiTask $task) => $this->mapTask(
                $task,
                $userLat,
                $userLng,
                in_array($task->id, $appliedTaskIds, true),
                in_array($task->id, $favoriteTaskIds, true),
            ))
            ->values();

        if ($userLat !== null && $userLng !== null && ($sort === 'distance' || $sort === '')) {
            $results = $results
                ->sortBy(fn (array $task) => $task['distance_km'] ?? PHP_FLOAT_MAX)
                ->values();
        }

        return $results;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:512'],
            'description' => ['required', 'string'],
            'category' => ['required'],
            'category_id' => ['nullable', 'string', 'exists:proffi_categories,id'],
            'work_id' => ['nullable', 'integer', 'exists:proffi_works,id'],
            'city' => ['required', 'string', 'max:128'],
            'address' => ['nullable', 'string', 'max:512'],
            'budget' => ['nullable', 'integer', 'min:0'],
            'budget_type' => ['nullable', 'in:fixed,range'],
            'budget_min' => ['nullable', 'integer', 'min:0'],
            'budget_max' => ['nullable', 'integer', 'min:0'],
            'response_price_mdl' => ['nullable', 'integer', 'min:0'],
            'deadline' => ['nullable', 'string', 'max:64'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'photos' => ['nullable', 'array'],
            'ai_details' => ['nullable', 'array'],
        ]);

        if (!empty($data['address']) && (empty($data['lat']) || empty($data['lng']))) {
            return response()->json(['detail' => 'Укажите точку на карте для выбранного адреса'], 422);
        }

        $budgetFields = $this->normalizeBudgetInput($data);

        $categoryId = $data['category_id'] ?? null;

        if (!$categoryId) {
            $categoryId = ProffiCategory::where('id', (string) $data['category'])
                ->orWhere('slug', (string) $data['category'])
                ->value('id');
        }

        $settings = TreaboResponseSetting::current();

        $task = ProffiTask::create([
            ...$data,
            ...$budgetFields,
            'category' => (string) ($categoryId ?: $data['category']),
            'category_id' => $categoryId,
            'response_price_mdl' => $data['response_price_mdl'] ?? $settings->default_response_price_mdl,
            'customer_id' => $request->user()->id,
            'status' => 'open',
        ]);

        $task->load(['customer.profile', 'work']);
        $this->matchingService->assignRecommendedSpecialists($task);

        return response()->json($this->mapTask($task), 201);
    }

    public function mine(Request $request)
    {
        return ProffiTask::with(['customer.profile', 'work'])
            ->where('customer_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn (ProffiTask $task) => $this->mapTask($task))
            ->values();
    }

    public function show(Request $request, ProffiTask $task)
    {
        $userLat = $request->filled('lat') ? (float) $request->query('lat') : null;
        $userLng = $request->filled('lng') ? (float) $request->query('lng') : null;
        $hasApplied = false;
        $isFavorite = false;

        if ($request->user()) {
            $hasApplied = ProffiApplication::where('task_id', $task->id)
                ->where('specialist_id', $request->user()->id)
                ->exists();
            $isFavorite = ProffiFavorite::where('task_id', $task->id)
                ->where('user_id', $request->user()->id)
                ->exists();
        }

        return $this->mapTask($task->load(['customer.profile', 'work']), $userLat, $userLng, $hasApplied, $isFavorite);
    }

    public function updateBudget(Request $request, ProffiTask $task)
    {
        if ((int) $task->customer_id !== (int) $request->user()->id) {
            return response()->json(['detail' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'budget' => ['nullable', 'integer', 'min:0'],
            'budget_type' => ['nullable', 'in:fixed,range'],
            'budget_min' => ['nullable', 'integer', 'min:0'],
            'budget_max' => ['nullable', 'integer', 'min:0'],
        ]);

        $budgetFields = $this->normalizeBudgetInput($data);
        $task->update($budgetFields);

        return $this->mapTask($task->fresh(['customer.profile', 'work']));
    }

    public function close(Request $request, ProffiTask $task)
    {
        if ((int) $task->customer_id !== (int) $request->user()->id) {
            return response()->json(['detail' => 'Forbidden'], 403);
        }

        $task->update(['status' => 'cancelled']);

        return $this->mapTask($task->fresh(['customer.profile', 'work']));
    }

    public function destroy(Request $request, ProffiTask $task)
    {
        if ((int) $task->customer_id !== (int) $request->user()->id) {
            return response()->json(['detail' => 'Forbidden'], 403);
        }
        $task->delete();
        return ['ok' => true];
    }

    public function applications(Request $request, ProffiTask $task)
    {
        if ((int) $task->customer_id !== (int) $request->user()->id) {
            return response()->json(['detail' => 'Forbidden'], 403);
        }

        return $task->applications()
            ->with('specialist.profile')
            ->latest()
            ->get()
            ->map(function ($application) {
                $chat = \App\Models\ProffiChat::where('application_id', $application->id)->first();
                return [
                    'id' => (string) $application->id,
                    'task_id' => (string) $application->task_id,
                    'specialist_id' => (string) $application->specialist_id,
                    'specialist_name' => $application->specialist?->name ?? '',
                    'specialist_city' => $application->specialist?->profile?->proffi_city,
                    'message' => $application->message,
                    'price' => $application->price,
                    'response_fee_mdl' => (int) ($application->response_fee_mdl ?? 15),
                    'status' => $application->status,
                    'chat_id' => $chat ? (string) $chat->id : null,
                    'created_at' => optional($application->created_at)->toIso8601String(),
                ];
            })
            ->values();
    }

    public function recommendedSpecialists(ProffiTask $task)
    {
        $rows = ProffiTaskRecommendedSpecialist::with(['specialist.profile'])
            ->where('task_id', $task->id)
            ->orderBy('rank')
            ->limit(5)
            ->get();

        return $rows->map(function (ProffiTaskRecommendedSpecialist $row) {
            $user = $row->specialist;
            if (!$user) {
                return null;
            }

            $mapped = $this->publicUser($user);

            return [
                ...$mapped,
                'score' => (float) $row->score,
                'rank' => (int) $row->rank,
            ];
        })->filter()->values();
    }

    public function contactSpecialist(Request $request, ProffiTask $task, \Marvel\Database\Models\User $specialist)
    {
        $user = $request->user();
        $isCustomer = (int) $task->customer_id === (int) $user->id;
        $isSpecialist = (int) $specialist->id === (int) $user->id;

        if (!$isCustomer && !$isSpecialist) {
            return response()->json(['detail' => 'Forbidden'], 403);
        }

        if (!$specialist->getPermissionNames()->contains(\Marvel\Enums\Permission::STORE_OWNER)) {
            return response()->json(['detail' => 'Specialist not found'], 404);
        }

        $chat = ProffiChat::updateOrCreate(
            ['task_id' => $task->id, 'specialist_id' => $specialist->id],
            ['customer_id' => $task->customer_id]
        );

        return [
            'chat_id' => (string) $chat->id,
            'task_id' => (string) $task->id,
            'specialist_id' => (string) $specialist->id,
        ];
    }

    public function specialistInfo(Request $request, ProffiTask $task)
    {
        $user = $request->user();
        $rank = $task->applications()->where('created_at', '<=', now())->count() + 1;
        $application = $task->applications()->where('specialist_id', $user->id)->first();
        $chat = $application
            ? \App\Models\ProffiChat::where('task_id', $task->id)->where('specialist_id', $user->id)->first()
            : null;

        return [
            'has_applied' => (bool) $application,
            'application_status' => $application?->status,
            'chat_id' => $chat ? (string) $chat->id : null,
            'rank' => $application ? $task->applications()->where('created_at', '<=', $application->created_at)->count() : $rank,
            'customer' => [
                'id' => (string) $task->customer_id,
                'name' => $task->customer?->name ?? '',
                'last_seen' => optional($task->customer?->updated_at)->toIso8601String(),
            ],
        ];
    }

    public function mapTask(
        ProffiTask $task,
        ?float $userLat = null,
        ?float $userLng = null,
        bool $hasApplied = false,
        bool $isFavorite = false,
    ): array {
        $distance = null;
        if ($userLat !== null && $userLng !== null && $task->lat !== null && $task->lng !== null) {
            $distance = round($this->haversineKm($userLat, $userLng, (float) $task->lat, (float) $task->lng), 1);
        }

        $isClosed = in_array($task->status, ['cancelled', 'closed', 'done', 'completed'], true);

        return [
            'id' => (string) $task->id,
            'title' => $task->displayTitle(),
            'description' => strip_tags((string) $task->description),
            'category' => (string) $task->category,
            'category_id' => $task->category_id ? (string) $task->category_id : null,
            'work_id' => $task->work_id ? (int) $task->work_id : null,
            'work_title' => $task->work?->title,
            'work' => $task->work ? [
                'id' => (int) $task->work->id,
                'title' => $task->work->title,
                'description' => $task->work->description,
            ] : null,
            'city' => $task->city,
            'address' => $task->address,
            ...$this->budgetFields($task),
            'response_price_mdl' => (int) ($task->response_price_mdl ?? 15),
            'deadline' => $task->deadline,
            'status' => $task->status,
            'is_closed' => $isClosed,
            'has_applied' => $hasApplied,
            'is_favorite' => $isFavorite,
            'customer_id' => (string) $task->customer_id,
            'customer_name' => $task->customer?->name,
            'accepted_specialist_id' => $task->accepted_specialist_id ? (string) $task->accepted_specialist_id : null,
            'photos' => $task->photos ?: [],
            'details' => $task->ai_details ?: null,
            'ai_details' => $task->ai_details ?: null,
            'lat' => $task->lat,
            'lng' => $task->lng,
            'distance_km' => $distance,
            'created_at' => optional($task->created_at)->toIso8601String(),
            'updated_at' => optional($task->updated_at)->toIso8601String(),
        ];
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function resolveOptionalUserId(Request $request): ?int
    {
        $user = $request->user();
        if ($user) {
            return (int) $user->id;
        }

        if (!$request->bearerToken()) {
            return null;
        }

        try {
            $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken());

            return $token?->tokenable?->id ? (int) $token->tokenable->id : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
