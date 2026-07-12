<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Proffi\Concerns\MapsProffiUsers;
use App\Models\ProffiCategory;
use App\Models\ProffiApplication;
use App\Models\BalanceDeposit;
use App\Models\SellerBalance;
use App\Models\ProffiChat;
use App\Models\ProffiFilter;
use App\Models\ProffiMessage;
use App\Models\ProffiTask;
use App\Http\Controllers\Proffi\Concerns\MapsProffiBudget;
use App\Models\ProffiIdentityVerification;
use App\Models\ProffiReview;
use App\Models\TreaboMatchingSetting;
use App\Models\TreaboMobileUpdateSetting;
use App\Models\TreaboResponseSetting;
use App\Services\Proffi\TaskLocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\Profile;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Spatie\Permission\Models\Permission as SpatiePermission;

class AdminController extends Controller
{
    use MapsProffiUsers;
    use MapsProffiBudget;

    public function __construct(private readonly TaskLocationService $taskLocation)
    {
    }

    public function stats()
    {
        return [
            'users' => User::count(),
            'categories' => ProffiCategory::count(),
            'filters' => ProffiFilter::count(),
            'tasks' => ProffiTask::count(),
            'applications' => ProffiApplication::count(),
            'chats' => ProffiChat::count(),
            'messages' => ProffiMessage::count(),
            'customers' => User::permission(Permission::CUSTOMER)->count(),
            'specialists' => User::permission(Permission::STORE_OWNER)->count(),
        ];
    }

    public function users()
    {
        return User::with('profile')->limit(500)->get()->map(fn (User $user) => $this->publicUser($user))->values();
    }

    public function customers()
    {
        return User::with('profile')
            ->permission(Permission::CUSTOMER)
            ->latest()
            ->limit(500)
            ->get()
            ->map(fn (User $user) => $this->publicUser($user))
            ->values();
    }

    public function specialists()
    {
        return User::with('profile')
            ->permission(Permission::STORE_OWNER)
            ->latest()
            ->limit(500)
            ->get()
            ->map(function (User $user) {
                $data = $this->publicUser($user);
                $balance = SellerBalance::getOrCreate($user->id);
                $data['balance'] = (float) $balance->balance;
                $data['total_deposited'] = (float) $balance->total_deposited;
                $data['total_spent'] = (float) $balance->total_spent;

                return $data;
            })
            ->values();
    }

    public function virtualBalanceDeposit(Request $request, User $user)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        if (!$user->hasPermissionTo(Permission::STORE_OWNER)) {
            return response()->json(['detail' => 'Пользователь не является мастером'], 400);
        }

        $amount = (float) $data['amount'];
        $balance = SellerBalance::getOrCreate($user->id);
        $oldBalance = (float) $balance->balance;
        $balance->deposit($amount, 'Виртуальное пополнение баланса администратором');

        $deposit = BalanceDeposit::create([
            'seller_id' => $user->id,
            'amount' => $amount,
            'payment_id' => 'virtual_' . time() . '_' . $user->id . '_' . uniqid(),
            'status' => 'succeeded',
            'paid_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Баланс успешно пополнен',
            'data' => [
                'seller_id' => (string) $user->id,
                'amount' => $amount,
                'old_balance' => $oldBalance,
                'new_balance' => (float) $balance->balance,
                'deposit_id' => $deposit->id,
            ],
        ]);
    }

    public function createUser(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string', 'min:4'],
            'name' => ['required', 'string'],
            'role' => ['required', 'in:customer,specialist,admin'],
            'city' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'services' => ['nullable', 'array'],
            'services.*' => ['string'],
            'avatar' => ['nullable', 'string', 'max:2048'],
            'portfolio' => ['nullable', 'array', 'max:10'],
            'portfolio.*' => ['string', 'max:2048'],
        ]);

        $phone = preg_replace('/[^\d+]/', '', trim($data['phone']));
        if (Profile::where('contact', $phone)->exists()) {
            return response()->json(['detail' => 'Phone already registered'], 400);
        }

        $email = trim((string) ($data['email'] ?? ''));
        $email = $email !== '' ? $email : ('phone-' . (preg_replace('/\D/', '', $phone) ?: Str::random(8)) . '@treabo.local');
        if (User::where('email', $email)->exists()) {
            return response()->json(['detail' => 'Email already registered'], 400);
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $email,
            'password' => Hash::make($data['password']),
            'is_active' => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->assignRole($user, $data['role']);

        $profileData = [
            'contact' => $phone,
            'bio' => null,
            'avatar' => $this->avatarPayload($data['avatar'] ?? null),
            'socials' => $this->socialsPayload([], $data['portfolio'] ?? []),
        ];
        foreach (['proffi_city' => $data['city'] ?? null, 'proffi_services' => $data['services'] ?? []] as $column => $value) {
            if (Schema::hasColumn('user_profiles', $column)) {
                $profileData[$column] = $value;
            }
        }
        $user->profile()->create($profileData);

        return response()->json($this->publicUser($user->fresh('profile')), 201);
    }

    public function updateUser(Request $request, User $user)
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['nullable', 'string', 'min:4'],
            'name' => ['required', 'string'],
            'city' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'services' => ['nullable', 'array'],
            'services.*' => ['string'],
            'avatar' => ['nullable', 'string', 'max:2048'],
            'portfolio' => ['nullable', 'array', 'max:10'],
            'portfolio.*' => ['string', 'max:2048'],
        ]);

        $phone = preg_replace('/[^\d+]/', '', trim($data['phone']));
        $email = trim((string) ($data['email'] ?? ''));

        if ($email !== '' && User::where('email', $email)->whereKeyNot($user->id)->exists()) {
            return response()->json(['detail' => 'Email already registered'], 400);
        }

        if (Profile::where('contact', $phone)->where('customer_id', '!=', $user->id)->exists()) {
            return response()->json(['detail' => 'Phone already registered'], 400);
        }

        $payload = [
            'name' => $data['name'],
            'email' => $email !== '' ? $email : $user->email,
        ];

        if (!empty($data['password'])) {
            $payload['password'] = Hash::make($data['password']);
        }

        $user->update($payload);

        $profile = $user->profile;
        $profileData = ['contact' => $phone];
        foreach ([
            'proffi_city' => $data['city'] ?? null,
            'proffi_services' => $data['services'] ?? [],
        ] as $column => $value) {
            if (Schema::hasColumn('user_profiles', $column)) {
                $profileData[$column] = $value;
            }
        }

        if (array_key_exists('avatar', $data)) {
            $profileData['avatar'] = $this->avatarPayload($data['avatar']);
        }

        if (array_key_exists('portfolio', $data)) {
            $profileData['socials'] = $this->socialsPayload($profile?->socials ?? [], $data['portfolio'] ?? []);
        }

        Profile::updateOrCreate(['customer_id' => $user->id], $profileData);

        return response()->json($this->publicUser($user->fresh('profile')));
    }

    public function deleteUser(User $user)
    {
        $user->delete();
        return ['ok' => true];
    }

    public function categories()
    {
        return ProffiCategory::orderBy('name_ru')->get();
    }

    public function createCategory(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'max:64'],
            'parent_id' => ['nullable', 'string', 'max:64'],
            'icon' => ['nullable', 'string', 'max:64'],
            'image' => ['nullable', 'string', 'max:2048'],
            'name_ru' => ['required', 'string'],
            'name_ro' => ['nullable', 'string'],
            'slug' => ['nullable', 'string', 'max:128'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $category = ProffiCategory::create([
            'id' => $data['id'],
            'parent_id' => $data['parent_id'] ?? null,
            'icon' => $data['icon'] ?: 'MoreHorizontal',
            'image' => $data['image'] ?? null,
            'name_ru' => $data['name_ru'],
            'name_ro' => $data['name_ro'] ?? $data['name_ru'],
            'slug' => $data['slug'] ?? $data['id'],
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return response()->json($category, 201);
    }

    public function updateCategory(Request $request, string $id)
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'string', 'max:64'],
            'icon' => ['nullable', 'string', 'max:64'],
            'image' => ['nullable', 'string', 'max:2048'],
            'name_ru' => ['required', 'string'],
            'name_ro' => ['nullable', 'string'],
            'slug' => ['nullable', 'string', 'max:128'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $category = ProffiCategory::findOrFail($id);
        $category->update([
            'parent_id' => $data['parent_id'] ?? null,
            'icon' => $data['icon'] ?: 'MoreHorizontal',
            'image' => $data['image'] ?? null,
            'name_ru' => $data['name_ru'],
            'name_ro' => $data['name_ro'] ?? $data['name_ru'],
            'slug' => $data['slug'] ?? $category->slug,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return $category;
    }

    public function deleteCategory(string $id)
    {
        ProffiCategory::whereKey($id)->delete();
        return ['ok' => true];
    }

    public function filters()
    {
        return ProffiFilter::orderBy('name')->get();
    }

    public function responseSettings()
    {
        return TreaboResponseSetting::current();
    }

    public function updateResponseSettings(Request $request)
    {
        $data = $request->validate([
            'free_daily_limit' => ['required', 'integer', 'min:0', 'max:1000'],
            'free_per_task_limit' => ['required', 'integer', 'min:0', 'max:1000'],
            'default_response_price_mdl' => ['required', 'integer', 'min:0', 'max:1000000'],
            'manual_deposit_amount_mdl' => ['required', 'integer', 'min:1', 'max:1000000'],
            'manual_deposit_url' => ['nullable', 'url', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $settings = TreaboResponseSetting::current();
        $settings->update([
            'free_daily_limit' => $data['free_daily_limit'],
            'free_per_task_limit' => $data['free_per_task_limit'],
            'default_response_price_mdl' => $data['default_response_price_mdl'],
            'manual_deposit_amount_mdl' => $data['manual_deposit_amount_mdl'],
            'manual_deposit_url' => $data['manual_deposit_url'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $settings->fresh();
    }

    public function matchingSettings()
    {
        return TreaboMatchingSetting::current();
    }

    public function updateMatchingSettings(Request $request)
    {
        $data = $request->validate([
            'category_weight' => ['required', 'integer', 'min:0', 'max:1000'],
            'work_weight' => ['required', 'integer', 'min:0', 'max:1000'],
            'rating_weight' => ['required', 'integer', 'min:0', 'max:1000'],
            'reviews_weight' => ['required', 'integer', 'min:0', 'max:1000'],
            'online_weight' => ['required', 'integer', 'min:0', 'max:1000'],
            'profile_relevance_weight' => ['required', 'integer', 'min:0', 'max:1000'],
            'min_rating' => ['required', 'numeric', 'min:0', 'max:5'],
            'min_reviews' => ['required', 'integer', 'min:0', 'max:100000'],
            'max_recommended' => ['required', 'integer', 'min:1', 'max:5'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $settings = TreaboMatchingSetting::current();
        $settings->update([
            'category_weight' => $data['category_weight'],
            'work_weight' => $data['work_weight'],
            'rating_weight' => $data['rating_weight'],
            'reviews_weight' => $data['reviews_weight'],
            'online_weight' => $data['online_weight'],
            'profile_relevance_weight' => $data['profile_relevance_weight'],
            'min_rating' => $data['min_rating'],
            'min_reviews' => $data['min_reviews'],
            'max_recommended' => $data['max_recommended'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $settings->fresh();
    }

    public function mobileUpdateSettings()
    {
        return TreaboMobileUpdateSetting::current();
    }

    public function updateMobileUpdateSettings(Request $request)
    {
        $data = $request->validate([
            'latest_version' => ['required', 'string', 'max:50'],
            'latest_build' => ['required', 'integer', 'min:1', 'max:1000000'],
            'min_supported_build' => ['required', 'integer', 'min:1', 'max:1000000'],
            'force_update' => ['nullable', 'boolean'],
            'android_url' => ['nullable', 'url', 'max:2048'],
            'ios_url' => ['nullable', 'url', 'max:2048'],
            'release_notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $settings = TreaboMobileUpdateSetting::current();
        $settings->update([
            'latest_version' => $data['latest_version'],
            'latest_build' => $data['latest_build'],
            'min_supported_build' => min($data['min_supported_build'], $data['latest_build']),
            'force_update' => $data['force_update'] ?? false,
            'android_url' => $data['android_url'] ?? null,
            'ios_url' => $data['ios_url'] ?? null,
            'release_notes' => $data['release_notes'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $settings->fresh();
    }

    public function balanceDeposits()
    {
        return BalanceDeposit::with('seller.profile')
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (BalanceDeposit $deposit) => [
                'id' => (string) $deposit->id,
                'seller_id' => (string) $deposit->seller_id,
                'seller_name' => $deposit->seller?->name,
                'seller_phone' => $deposit->seller?->profile?->contact,
                'amount' => (float) $deposit->amount,
                'status' => $deposit->status,
                'payment_id' => $deposit->payment_id,
                'reported_at' => optional($deposit->reported_at)->toIso8601String(),
                'paid_at' => optional($deposit->paid_at)->toIso8601String(),
                'created_at' => optional($deposit->created_at)->toIso8601String(),
            ])
            ->values();
    }

    public function createFilter(Request $request)
    {
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string'],
            'key' => ['required', 'string'],
            'value' => ['required', 'string'],
        ]);

        $filter = ProffiFilter::create([
            'id' => $data['id'] ?? Str::slug($data['name'] . '-' . Str::random(4)),
            'name' => $data['name'],
            'key' => $data['key'],
            'value' => $data['value'],
        ]);

        return response()->json($filter, 201);
    }

    public function updateFilter(Request $request, string $id)
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'key' => ['required', 'string'],
            'value' => ['required', 'string'],
        ]);

        $filter = ProffiFilter::findOrFail($id);
        $filter->update($data);
        return $filter;
    }

    public function deleteFilter(string $id)
    {
        ProffiFilter::whereKey($id)->delete();
        return ['ok' => true];
    }

    public function tasks()
    {
        return ProffiTask::with(['customer.profile', 'acceptedSpecialist.profile'])
            ->withCount(['applications'])
            ->latest()
            ->limit(500)
            ->get()
            ->map(fn (ProffiTask $task) => $this->mapTask($task))
            ->values();
    }

    public function createTask(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:512'],
            'description' => ['required', 'string'],
            'category' => ['required', 'string', 'max:64'],
            'city' => ['required', 'string', 'max:128'],
            'location_id' => ['nullable', 'integer', 'exists:russia_locations,id'],
            'address' => ['nullable', 'string', 'max:512'],
            'budget' => ['nullable', 'integer', 'min:0'],
            'budget_type' => ['nullable', 'in:fixed,range'],
            'budget_min' => ['nullable', 'integer', 'min:0'],
            'budget_max' => ['nullable', 'integer', 'min:0'],
            'response_price_mdl' => ['nullable', 'integer', 'min:0'],
            'deadline' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'in:open,in_progress,done,cancelled'],
            'customer_id' => ['required', 'integer', 'exists:users,id'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['string', 'max:2048'],
        ]);

        $data = $this->taskLocation->normalize($data);
        $settings = TreaboResponseSetting::current();
        $budgetFields = $this->normalizeBudgetInput($data);

        $task = ProffiTask::create([
            'title' => $data['title'],
            'description' => $data['description'],
            'category' => $data['category'],
            'category_id' => $data['category'],
            'city' => $data['city'],
            'location_id' => $data['location_id'] ?? null,
            'address' => $data['address'] ?? null,
            ...$budgetFields,
            'response_price_mdl' => $data['response_price_mdl'] ?? $settings->default_response_price_mdl,
            'deadline' => $data['deadline'] ?? null,
            'status' => $data['status'] ?? 'open',
            'customer_id' => $data['customer_id'],
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            'photos' => $this->mediaList($data['photos'] ?? []),
        ]);

        return response()->json($this->mapTask($task->load(['customer.profile', 'acceptedSpecialist.profile'])), 201);
    }

    public function updateTask(Request $request, ProffiTask $task)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:512'],
            'description' => ['required', 'string'],
            'category' => ['required', 'string', 'max:64'],
            'city' => ['required', 'string', 'max:128'],
            'location_id' => ['nullable', 'integer', 'exists:russia_locations,id'],
            'address' => ['nullable', 'string', 'max:512'],
            'budget' => ['nullable', 'integer', 'min:0'],
            'budget_type' => ['nullable', 'in:fixed,range'],
            'budget_min' => ['nullable', 'integer', 'min:0'],
            'budget_max' => ['nullable', 'integer', 'min:0'],
            'response_price_mdl' => ['nullable', 'integer', 'min:0'],
            'deadline' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'in:open,in_progress,done,cancelled'],
            'customer_id' => ['required', 'integer', 'exists:users,id'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['string', 'max:2048'],
        ]);

        $data = $this->taskLocation->normalize($data);
        $settings = TreaboResponseSetting::current();
        $budgetFields = $this->normalizeBudgetInput($data);

        $task->update([
            'title' => $data['title'],
            'description' => $data['description'],
            'category' => $data['category'],
            'category_id' => $data['category'],
            'city' => $data['city'],
            'location_id' => $data['location_id'] ?? null,
            'address' => $data['address'] ?? null,
            ...$budgetFields,
            'response_price_mdl' => $data['response_price_mdl'] ?? $settings->default_response_price_mdl,
            'deadline' => $data['deadline'] ?? null,
            'status' => $data['status'] ?? 'open',
            'customer_id' => $data['customer_id'],
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            'photos' => $this->mediaList($data['photos'] ?? []),
        ]);

        return response()->json($this->mapTask($task->fresh(['customer.profile', 'acceptedSpecialist.profile'])));
    }

    public function deleteTask(ProffiTask $task)
    {
        $task->delete();
        return ['ok' => true];
    }

    public function applications()
    {
        return ProffiApplication::with(['task', 'specialist.profile'])
            ->latest()
            ->limit(500)
            ->get()
            ->map(fn (ProffiApplication $application) => $this->mapApplication($application))
            ->values();
    }

    public function chats()
    {
        return ProffiChat::with(['task', 'customer.profile', 'specialist.profile'])
            ->withCount('messages')
            ->latest('updated_at')
            ->limit(500)
            ->get()
            ->map(fn (ProffiChat $chat) => $this->mapChat($chat))
            ->values();
    }

    public function chatMessages(ProffiChat $chat)
    {
        $chat->load(['task', 'customer.profile', 'specialist.profile']);

        return [
            'chat' => $this->mapChat($chat),
            'messages' => $chat->messages()
                ->with('sender.profile')
                ->oldest()
                ->limit(1000)
                ->get()
                ->map(fn (ProffiMessage $message) => $this->mapMessage($message))
                ->values(),
        ];
    }

    private function mapTask(ProffiTask $task): array
    {
        return [
            'id' => (string) $task->id,
            'title' => $task->displayTitle(),
            'description' => $task->description,
            'category' => (string) $task->category,
            'category_id' => $task->category_id ? (string) $task->category_id : null,
            'city' => $task->city,
            'location_id' => $task->location_id ? (int) $task->location_id : null,
            'address' => $task->address,
            ...$this->budgetFields($task),
            'response_price_mdl' => $task->response_price_mdl,
            'deadline' => $task->deadline,
            'status' => $task->status,
            'customer_id' => (string) $task->customer_id,
            'customer_name' => $task->customer?->name,
            'customer_phone' => $task->customer?->profile?->contact,
            'accepted_specialist_id' => $task->accepted_specialist_id ? (string) $task->accepted_specialist_id : null,
            'accepted_specialist_name' => $task->acceptedSpecialist?->name,
            'applications_count' => (int) ($task->applications_count ?? 0),
            'photos' => $this->mediaList($task->photos ?: []),
            'photos_count' => count($task->photos ?: []),
            'lat' => $task->lat !== null ? (float) $task->lat : null,
            'lng' => $task->lng !== null ? (float) $task->lng : null,
            'created_at' => optional($task->created_at)->toIso8601String(),
            'updated_at' => optional($task->updated_at)->toIso8601String(),
        ];
    }

    private function mapApplication(ProffiApplication $application): array
    {
        $chat = ProffiChat::where('application_id', $application->id)->first();

        return [
            'id' => (string) $application->id,
            'task_id' => (string) $application->task_id,
            'task_title' => $application->task?->displayTitle(),
            'specialist_id' => (string) $application->specialist_id,
            'specialist_name' => $application->specialist?->name,
            'specialist_phone' => $application->specialist?->profile?->contact,
            'message' => $application->message,
            'price' => $application->price,
            'status' => $application->status,
            'chat_id' => $chat ? (string) $chat->id : null,
            'created_at' => optional($application->created_at)->toIso8601String(),
            'updated_at' => optional($application->updated_at)->toIso8601String(),
        ];
    }

    private function avatarPayload(?string $url): ?array
    {
        $url = trim((string) $url);

        return $url !== ''
            ? ['original' => $url, 'thumbnail' => $url]
            : null;
    }

    private function socialsPayload($existing, array $portfolio): array
    {
        if (is_string($existing)) {
            $existing = json_decode($existing, true) ?: [];
        }
        if (!is_array($existing)) {
            $existing = [];
        }

        $existing['treabo_portfolio'] = $this->mediaList($portfolio);

        return $existing;
    }

    private function mediaList($value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true) ?: [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_unique(array_map(
            fn ($item) => is_string($item) ? trim($item) : '',
            $value
        ))));
    }

    private function mapChat(ProffiChat $chat): array
    {
        return [
            'id' => (string) $chat->id,
            'task_id' => (string) $chat->task_id,
            'task_title' => $chat->task?->title,
            'application_id' => $chat->application_id ? (string) $chat->application_id : null,
            'customer_id' => (string) $chat->customer_id,
            'customer_name' => $chat->customer?->name,
            'customer_phone' => $chat->customer?->profile?->contact,
            'specialist_id' => (string) $chat->specialist_id,
            'specialist_name' => $chat->specialist?->name,
            'specialist_phone' => $chat->specialist?->profile?->contact,
            'messages_count' => (int) ($chat->messages_count ?? $chat->messages()->count()),
            'last_message' => $chat->last_message,
            'last_message_at' => optional($chat->last_message_at)->toIso8601String(),
            'created_at' => optional($chat->created_at)->toIso8601String(),
            'updated_at' => optional($chat->updated_at)->toIso8601String(),
        ];
    }

    private function mapMessage(ProffiMessage $message): array
    {
        return [
            'id' => (string) $message->id,
            'chat_id' => (string) $message->chat_id,
            'sender_id' => (string) $message->sender_id,
            'sender_name' => $message->sender?->name,
            'sender_phone' => $message->sender?->profile?->contact,
            'text' => $message->text,
            'created_at' => optional($message->created_at)->toIso8601String(),
            'updated_at' => optional($message->updated_at)->toIso8601String(),
        ];
    }

    public function reviews()
    {
        return ProffiReview::with(['customer.profile', 'specialist.profile', 'task'])
            ->latest()
            ->limit(500)
            ->get()
            ->map(fn (ProffiReview $review) => $this->mapAdminReview($review))
            ->values();
    }

    public function createReview(Request $request)
    {
        $data = $request->validate([
            'task_id' => ['nullable', 'integer', 'exists:proffi_tasks,id'],
            'specialist_id' => ['required', 'integer', 'exists:users,id'],
            'customer_id' => ['required', 'integer', 'exists:users,id'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['string', 'max:2048'],
        ]);

        $review = ProffiReview::create([
            ...$data,
            'photos' => $this->mediaList($data['photos'] ?? []),
        ]);

        return response()->json($this->mapAdminReview($review->load(['customer.profile', 'specialist.profile', 'task'])), 201);
    }

    public function updateReview(Request $request, ProffiReview $review)
    {
        $data = $request->validate([
            'task_id' => ['nullable', 'integer', 'exists:proffi_tasks,id'],
            'specialist_id' => ['required', 'integer', 'exists:users,id'],
            'customer_id' => ['required', 'integer', 'exists:users,id'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['string', 'max:2048'],
        ]);

        $review->update([
            ...$data,
            'photos' => $this->mediaList($data['photos'] ?? []),
        ]);

        return $this->mapAdminReview($review->fresh(['customer.profile', 'specialist.profile', 'task']));
    }

    public function deleteReview(ProffiReview $review)
    {
        $review->delete();

        return ['ok' => true];
    }

    public function verifications()
    {
        return ProffiIdentityVerification::with(['user.profile'])
            ->whereIn('status', [
                ProffiIdentityVerification::STATUS_PENDING,
                ProffiIdentityVerification::STATUS_APPROVED,
                ProffiIdentityVerification::STATUS_REJECTED,
            ])
            ->latest()
            ->limit(500)
            ->get()
            ->map(fn (ProffiIdentityVerification $item) => [
                'id' => (string) $item->id,
                'user_id' => (string) $item->user_id,
                'user_name' => $item->user?->name,
                'user_phone' => $item->user?->profile?->contact,
                'status' => $item->status,
                'passport_main_photo' => $item->passport_main_photo,
                'passport_registration_photo' => $item->passport_registration_photo,
                'passport_selfie_photo' => $item->passport_selfie_photo,
                'moderator_comment' => $item->moderator_comment,
                'created_at' => optional($item->created_at)->toIso8601String(),
                'updated_at' => optional($item->updated_at)->toIso8601String(),
            ])
            ->values();
    }

    public function approveVerification(Request $request, ProffiIdentityVerification $verification)
    {
        $verification->update([
            'status' => ProffiIdentityVerification::STATUS_APPROVED,
            'moderator_comment' => null,
            'reviewed_by' => null,
        ]);

        return ['ok' => true, 'status' => $verification->status];
    }

    public function rejectVerification(Request $request, ProffiIdentityVerification $verification)
    {
        $data = $request->validate([
            'moderator_comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $verification->update([
            'status' => ProffiIdentityVerification::STATUS_REJECTED,
            'moderator_comment' => $data['moderator_comment'] ?? null,
            'reviewed_by' => null,
        ]);

        return ['ok' => true, 'status' => $verification->status];
    }

    private function mapAdminReview(ProffiReview $review): array
    {
        return [
            'id' => (string) $review->id,
            'task_id' => $review->task_id ? (string) $review->task_id : null,
            'task_title' => $review->task?->title,
            'specialist_id' => (string) $review->specialist_id,
            'specialist_name' => $review->specialist?->name,
            'customer_id' => (string) $review->customer_id,
            'customer_name' => $review->customer?->name,
            'rating' => (int) $review->rating,
            'comment' => $review->comment,
            'photos' => $this->mediaList($review->photos ?: []),
            'created_at' => optional($review->created_at)->toIso8601String(),
        ];
    }

    private function assignRole(User $user, string $role): void
    {
        $permission = match ($role) {
            'admin' => Permission::SUPER_ADMIN,
            'specialist' => Permission::STORE_OWNER,
            default => Permission::CUSTOMER,
        };
        foreach (array_unique([$permission, Permission::CUSTOMER]) as $name) {
            SpatiePermission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }
        $user->givePermissionTo($permission);
        if ($permission === Permission::STORE_OWNER) {
            $user->givePermissionTo(Permission::CUSTOMER);
        }
    }
}
