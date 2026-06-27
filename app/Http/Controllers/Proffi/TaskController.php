<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Proffi\Concerns\MapsProffiUsers;
use App\Models\ProffiCategory;
use App\Models\ProffiTask;
use App\Models\TreaboResponseSetting;
use App\Services\Proffi\ProffiCategorySearchService;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    use MapsProffiUsers;

    public function __construct(
        private readonly ProffiCategorySearchService $categorySearch,
    ) {
    }

    public function index(Request $request)
    {
        $query = ProffiTask::with('customer.profile')->where('status', 'open')->latest();

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

        return $query->limit(100)->get()->map(fn (ProffiTask $task) => $this->mapTask($task))->values();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:512'],
            'description' => ['required', 'string'],
            'category' => ['required'],
            'category_id' => ['nullable', 'string', 'exists:proffi_categories,id'],
            'city' => ['required', 'string', 'max:128'],
            'address' => ['nullable', 'string', 'max:512'],
            'budget' => ['nullable', 'integer', 'min:0'],
            'response_price_mdl' => ['nullable', 'integer', 'min:0'],
            'deadline' => ['nullable', 'string', 'max:64'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'photos' => ['nullable', 'array'],
        ]);

        $categoryId = $data['category_id'] ?? null;

        if (!$categoryId) {
            $categoryId = ProffiCategory::where('id', (string) $data['category'])
                ->orWhere('slug', (string) $data['category'])
                ->value('id');
        }

        $settings = TreaboResponseSetting::current();

        $task = ProffiTask::create([
            ...$data,
            'category' => (string) ($categoryId ?: $data['category']),
            'category_id' => $categoryId,
            'response_price_mdl' => $data['response_price_mdl'] ?? $settings->default_response_price_mdl,
            'customer_id' => $request->user()->id,
            'status' => 'open',
        ]);

        return response()->json($this->mapTask($task->load('customer.profile')), 201);
    }

    public function mine(Request $request)
    {
        return ProffiTask::with('customer.profile')
            ->where('customer_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn (ProffiTask $task) => $this->mapTask($task))
            ->values();
    }

    public function show(ProffiTask $task)
    {
        return $this->mapTask($task->load('customer.profile'));
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

    public function mapTask(ProffiTask $task): array
    {
        return [
            'id' => (string) $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'category' => (string) $task->category,
            'category_id' => $task->category_id ? (string) $task->category_id : null,
            'city' => $task->city,
            'address' => $task->address,
            'budget' => $task->budget,
            'response_price_mdl' => (int) ($task->response_price_mdl ?? 15),
            'deadline' => $task->deadline,
            'status' => $task->status,
            'customer_id' => (string) $task->customer_id,
            'customer_name' => $task->customer?->name,
            'accepted_specialist_id' => $task->accepted_specialist_id ? (string) $task->accepted_specialist_id : null,
            'photos' => $task->photos ?: [],
            'lat' => $task->lat,
            'lng' => $task->lng,
            'created_at' => optional($task->created_at)->toIso8601String(),
            'updated_at' => optional($task->updated_at)->toIso8601String(),
        ];
    }
}
