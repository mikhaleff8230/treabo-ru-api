<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Proffi\Concerns\MapsProffiUsers;
use App\Models\ProffiCategory;
use App\Models\ProffiApplication;
use App\Models\ProffiChat;
use App\Models\ProffiFilter;
use App\Models\ProffiMessage;
use App\Models\ProffiTask;
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
            ->map(fn (User $user) => $this->publicUser($user))
            ->values();
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
        ]);

        $phone = preg_replace('/[^\d+]/', '', trim($data['phone']));
        if (Profile::where('contact', $phone)->exists()) {
            return response()->json(['detail' => 'Phone already registered'], 400);
        }

        $email = $data['email'] ?? ('phone-' . (preg_replace('/\D/', '', $phone) ?: Str::random(8)) . '@proffi.local');
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

        $profileData = ['contact' => $phone, 'bio' => null];
        foreach (['proffi_city' => $data['city'] ?? null, 'proffi_services' => []] as $column => $value) {
            if (Schema::hasColumn('user_profiles', $column)) {
                $profileData[$column] = $value;
            }
        }
        $user->profile()->create($profileData);

        return response()->json($this->publicUser($user->fresh('profile')), 201);
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
            'icon' => ['nullable', 'string', 'max:64'],
            'name_ru' => ['required', 'string'],
            'name_ro' => ['required', 'string'],
        ]);

        $category = ProffiCategory::create([
            'id' => $data['id'],
            'icon' => $data['icon'] ?: 'MoreHorizontal',
            'name_ru' => $data['name_ru'],
            'name_ro' => $data['name_ro'],
        ]);

        return response()->json($category, 201);
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
            'title' => $task->title,
            'description' => $task->description,
            'category' => (string) $task->category,
            'city' => $task->city,
            'address' => $task->address,
            'budget' => $task->budget,
            'deadline' => $task->deadline,
            'status' => $task->status,
            'customer_id' => (string) $task->customer_id,
            'customer_name' => $task->customer?->name,
            'customer_phone' => $task->customer?->profile?->contact,
            'accepted_specialist_id' => $task->accepted_specialist_id ? (string) $task->accepted_specialist_id : null,
            'accepted_specialist_name' => $task->acceptedSpecialist?->name,
            'applications_count' => (int) ($task->applications_count ?? 0),
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
            'task_title' => $application->task?->title,
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
