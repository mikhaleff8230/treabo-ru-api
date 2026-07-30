<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Models\ProffiPushLoginRequest;
use App\Models\ProffiPushToken;
use App\Services\Proffi\ExpoPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\Profile;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;

class PushLoginController extends Controller
{
    public function __construct(private readonly ExpoPushService $push) {}

    public function request(Request $request)
    {
        $phone = '+' . preg_replace('/\D+/', '', (string) $request->validate(['phone' => ['required', 'string']])['phone']);
        $profile = Profile::where('contact', $phone)->first();
        $user = $profile ? User::find($profile->customer_id) : null;
        if (!$user || !$user->getPermissionNames()->contains(Permission::STORE_OWNER)) {
            return response()->json(['detail' => 'Specialist account not found'], 404);
        }
        if (!ProffiPushToken::where('user_id', $user->id)->exists()) {
            return response()->json(['detail' => 'No registered application device'], 409);
        }

        [$login, $created] = DB::transaction(function () use ($user) {
            User::whereKey($user->id)->lockForUpdate()->first();

            $pending = ProffiPushLoginRequest::where('user_id', $user->id)
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->latest('created_at')
                ->first();

            if ($pending) {
                return [$pending, false];
            }

            return [
                ProffiPushLoginRequest::create([
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'status' => 'pending',
                    'expires_at' => now()->addMinutes(5),
                ]),
                true,
            ];
        });

        if ($created) {
            $this->push->sendToUser(
                (int) $user->id,
                'Вход в Treabo',
                'Подтвердите вход мастера на сайте',
                [
                    'type' => 'login_confirmation',
                    'request_id' => $login->id,
                    'url' => 'treabo://login-confirm/'.$login->id,
                ]
            );
        }

        return response()->json([
            'request_id' => $login->id,
            'status' => $login->status,
            'expires_in' => max(1, now()->diffInSeconds($login->expires_at, false)),
            'reused' => !$created,
        ]);
    }

    public function status(string $id)
    {
        $login = ProffiPushLoginRequest::findOrFail($id);
        if ($login->expires_at->isPast() && $login->status === 'pending') $login->update(['status' => 'expired']);
        if ($login->status !== 'approved' || !$login->web_token) return ['status' => $login->status];
        $token = Crypt::decryptString($login->web_token);
        $user = User::with('profile')->findOrFail($login->user_id);
        $login->delete();
        return ['status' => 'approved', 'token' => $token, 'user' => ['id' => (string) $user->id, 'name' => $user->name, 'role' => 'specialist', 'phone' => $user->profile?->contact]];
    }

    public function approve(Request $request, ProffiPushLoginRequest $login)
    {
        if ((int) $login->user_id !== (int) $request->user()->id || $login->status !== 'pending' || $login->expires_at->isPast()) {
            return response()->json(['detail' => 'Login request expired or forbidden'], 403);
        }
        $token = $request->user()->createToken('proffi-web-push-login')->plainTextToken;
        $login->update(['status' => 'approved', 'approved_at' => now(), 'web_token' => Crypt::encryptString($token)]);
        return ['approved' => true];
    }

    public function reject(Request $request, ProffiPushLoginRequest $login)
    {
        if ((int) $login->user_id !== (int) $request->user()->id) return response()->json(['detail' => 'Forbidden'], 403);
        $login->update(['status' => 'rejected']);
        return ['rejected' => true];
    }
}
