<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Proffi\Concerns\MapsProffiUsers;
use App\Http\Controllers\Proffi\Concerns\UsesTreaboPhoneOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Marvel\Database\Models\Profile;
use Marvel\Database\Models\Provider;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Spatie\Permission\Models\Permission as SpatiePermission;

class AuthController extends Controller
{
    use MapsProffiUsers;
    use UsesTreaboPhoneOtp;

    private const OTP_MAX_ATTEMPTS = 5;

    public function checkPhone(Request $request)
    {
        $data = $request->validate(['phone' => ['required', 'string']]);
        $phone = $this->normalizePhone($data['phone']);
        return ['registered' => Profile::where('contact', $phone)->exists()];
    }

    public function registerPhone(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string', 'min:4'],
            'name' => ['required', 'string'],
            'role' => ['required', 'in:customer,specialist'],
            'city' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
        ]);

        $phone = $this->normalizePhone($data['phone']);
        if (Profile::where('contact', $phone)->exists()) {
            return response()->json(['detail' => 'Phone already registered'], 400);
        }

        $email = $data['email'] ?? $this->emailFromPhone($phone);
        if (User::where('email', $email)->exists()) {
            return response()->json(['detail' => 'Email already registered'], 400);
        }

        if ($this->treaboPhoneOtpEnabled()) {
            return $this->startRegisterPhoneOtp($phone, $data);
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $email,
            'password' => Hash::make($data['password']),
            'is_active' => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->assignRole($user, $data['role']);
        $user->profile()->create($this->profilePayload([
            'contact' => $phone,
            'bio' => null,
            'proffi_city' => $data['city'] ?? null,
            'proffi_services' => [],
            'seller_id' => strtoupper(Str::random(8)),
            'phone_verified' => true,
            'phone_verified_at' => now(),
        ]));

        return $this->authResponse($user->fresh('profile'));
    }

    public function sendPhoneOtp(Request $request)
    {
        if (!$this->treaboPhoneOtpEnabled()) {
            return response()->json(['detail' => 'Phone OTP is disabled'], 404);
        }

        $data = $request->validate([
            'phone' => ['required', 'string'],
            'purpose' => ['required', 'in:login,register'],
            'password' => ['nullable', 'string'],
            'name' => ['nullable', 'string'],
            'role' => ['nullable', 'in:customer,specialist'],
            'email' => ['nullable', 'email'],
            'city' => ['nullable', 'string'],
        ]);

        $phone = $this->normalizePhone($data['phone']);

        if ($data['purpose'] === 'register') {
            $registerData = $request->validate([
                'password' => ['required', 'string', 'min:4'],
                'name' => ['required', 'string'],
                'role' => ['required', 'in:customer,specialist'],
            ]);

            return $this->startRegisterPhoneOtp($phone, array_merge($data, $registerData));
        }

        $loginData = $request->validate([
            'password' => ['required', 'string'],
        ]);

        return $this->startLoginPhoneOtp($phone, $loginData['password']);
    }

    public function verifyPhoneOtp(Request $request)
    {
        if (!$this->treaboPhoneOtpEnabled()) {
            return response()->json(['detail' => 'Phone OTP is disabled'], 404);
        }

        $data = $request->validate([
            'phone' => ['required', 'string'],
            'otp_id' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $phone = $this->normalizePhone($data['phone']);
        $otpId = $data['otp_id'];
        $context = $this->getTreaboOtpContext($otpId);

        if (!$context || ($context['phone'] ?? null) !== $phone) {
            return response()->json(['detail' => 'Invalid or expired verification session'], 400);
        }

        $attempts = (int) ($context['attempts'] ?? 0);
        if ($attempts >= self::OTP_MAX_ATTEMPTS) {
            $this->forgetTreaboOtpContext($otpId);

            return response()->json(['detail' => 'Too many attempts'], 429);
        }

        if (!$this->verifyTreaboPhoneOtpCode($otpId, $data['code'], $phone)) {
            $context['attempts'] = $attempts + 1;
            $this->cacheTreaboOtpContext($otpId, $context);

            return response()->json(['detail' => 'Invalid code'], 400);
        }

        $this->forgetTreaboOtpContext($otpId);

        if (($context['purpose'] ?? null) === 'register') {
            return $this->completeRegisterPhoneOtp($phone, $context['registration'] ?? []);
        }

        return $this->completeLoginPhoneOtp($phone, (int) ($context['user_id'] ?? 0));
    }

    public function login(Request $request)
    {
        if ($request->filled('email') && !$request->filled('phone')) {
            $email = Str::lower(trim($request->input('email')));
            $user = User::where('email', $email)->first();
            if (!$user) {
                return response()->json(['detail' => 'Invalid email or user not found'], 401);
            }
            if ($user->email_verified_at) {
                return $this->authResponse($user->load('profile'));
            }
            return $this->sendOtp($user);
        }

        $data = $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);
        $phone = $this->normalizePhone($data['phone']);
        $profile = Profile::where('contact', $phone)->first();
        $user = $profile ? User::find($profile->customer_id) : null;
        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['detail' => 'Invalid phone or password'], 401);
        }

        if ($this->treaboPhoneOtpEnabled() && !$this->isPhoneVerified($profile)) {
            return $this->startLoginPhoneOtp($phone, $data['password'], $user);
        }

        return $this->authResponse($user->load('profile'));
    }

    public function oauthRedirect(Request $request, string $provider)
    {
        $this->validateOAuthProvider($provider);
        $data = $request->validate([
            'role' => ['nullable', 'in:customer,specialist'],
            'return_url' => ['nullable', 'string', 'max:500'],
        ]);

        $state = base64_encode(json_encode([
            'role' => $data['role'] ?? 'customer',
            'return_url' => $data['return_url'] ?? null,
        ]));

        $driver = Socialite::driver($provider)->stateless()->with(['state' => $state]);
        if ($provider === 'yandex') {
            $driver->scopes(['login:email']);
        } elseif ($provider === 'google') {
            $driver->scopes(['openid', 'profile', 'email']);
        }

        return $driver->redirect();
    }

    public function oauthCallback(Request $request, string $provider)
    {
        $this->validateOAuthProvider($provider);
        $state = $this->decodeOAuthState($request->input('state'));
        $returnUrl = $state['return_url'] ?? null;

        try {
            if ($request->filled('error')) {
                throw new \RuntimeException((string) $request->input('error'));
            }

            $socialUser = Socialite::driver($provider)->stateless()->user();
            $email = Str::lower(trim((string) $socialUser->getEmail()));
            if (!$email) {
                throw new \RuntimeException('Provider did not return email');
            }

            $user = User::where('email', $email)->first();
            if (!$user) {
                $user = User::create([
                    'name' => $socialUser->getName() ?: $socialUser->getNickname() ?: 'User',
                    'email' => $email,
                    'password' => Hash::make(Str::random(40)),
                    'is_active' => true,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            } elseif (!$user->email_verified_at) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            Provider::updateOrCreate(
                ['provider' => $provider, 'provider_user_id' => (string) $socialUser->getId()],
                ['user_id' => $user->id]
            );

            $role = in_array(($state['role'] ?? 'customer'), ['customer', 'specialist'], true)
                ? $state['role']
                : 'customer';
            $this->assignRole($user, $role);
            $user->profile()->firstOrCreate(
                ['customer_id' => $user->id],
                $this->profilePayload([
                    'contact' => null,
                    'proffi_services' => [],
                    'seller_id' => strtoupper(Str::random(8)),
                ])
            );

            $token = $user->createToken('proffi-oauth')->plainTextToken;
            if ($returnUrl && $this->isAllowedOAuthReturnUrl($returnUrl)) {
                $separator = str_contains($returnUrl, '?') ? '&' : '?';
                return redirect($returnUrl . $separator . 'token=' . urlencode($token));
            }

            return response()->json(['token' => $token, 'user' => $this->publicUser($user->fresh('profile'))]);
        } catch (\Throwable $e) {
            if ($returnUrl && $this->isAllowedOAuthReturnUrl($returnUrl)) {
                $separator = str_contains($returnUrl, '?') ? '&' : '?';
                return redirect($returnUrl . $separator . 'auth_error=' . urlencode($e->getMessage()));
            }
            return response()->json(['detail' => $e->getMessage()], 400);
        }
    }

    public function registerEmail(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', 'in:customer,specialist'],
            'name' => ['nullable', 'string'],
        ]);
        $email = Str::lower(trim($data['email']));
        $user = User::where('email', $email)->first();
        if (!$user) {
            $user = User::create([
                'name' => $data['name'] ?: 'User',
                'email' => $email,
                'password' => Hash::make(Str::random(32)),
                'is_active' => true,
            ]);
            $this->assignRole($user, $data['role']);
            $user->profile()->create($this->profilePayload(['contact' => null, 'proffi_services' => []]));
        } elseif ($user->email_verified_at) {
            return response()->json(['detail' => 'Email already registered'], 400);
        }
        return $this->sendOtp($user);
    }

    public function verify(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp_code' => ['required', 'string'],
        ]);
        $email = Str::lower(trim($data['email']));
        $expected = Cache::get("proffi_otp:$email");
        if (!$expected || trim($data['otp_code']) !== $expected) {
            return response()->json(['detail' => 'Invalid email or code'], 400);
        }
        $user = User::where('email', $email)->first();
        if (!$user) {
            return response()->json(['detail' => 'Invalid email or code'], 400);
        }
        $user->forceFill(['email_verified_at' => now()])->save();
        Cache::forget("proffi_otp:$email");
        return $this->authResponse($user->load('profile'));
    }

    public function me(Request $request)
    {
        return $this->publicUser($request->user()->load('profile'));
    }

    public function stats(Request $request)
    {
        $user = $request->user();
        if ($this->proffiRole($user) === 'specialist') {
            $rating = $this->specialistRatingSummary($user);

            return [
                'role' => 'specialist',
                'applied' => \App\Models\ProffiApplication::where('specialist_id', $user->id)->count(),
                'accepted' => \App\Models\ProffiApplication::where('specialist_id', $user->id)->where('status', 'accepted')->count(),
                'completed' => \App\Models\ProffiApplication::where('specialist_id', $user->id)->whereIn('status', ['completed', 'accepted'])->count(),
                'active_chats' => \App\Models\ProffiChat::where('specialist_id', $user->id)->count(),
                'rating' => $rating['rating'],
                'reviews_count' => $rating['reviews_count'],
            ];
        }
        return [
            'role' => 'customer',
            'posted' => \App\Models\ProffiTask::where('customer_id', $user->id)->count(),
            'open' => \App\Models\ProffiTask::where('customer_id', $user->id)->where('status', 'open')->count(),
            'open_tasks' => \App\Models\ProffiTask::where('customer_id', $user->id)->where('status', 'open')->count(),
            'in_progress' => \App\Models\ProffiTask::where('customer_id', $user->id)->where('status', 'in_progress')->count(),
            'completed' => \App\Models\ProffiTask::where('customer_id', $user->id)->where('status', 'completed')->count(),
            'active_chats' => \App\Models\ProffiChat::where('customer_id', $user->id)->count(),
        ];
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'bio' => ['nullable', 'string'],
            'services' => ['nullable', 'array'],
            'avatar' => ['nullable', 'string'],
            'portfolio' => ['nullable', 'array', 'max:10'],
            'portfolio.*' => ['string'],
            'city' => ['nullable', 'string'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
        ]);
        $profile = $request->user()->profile()->firstOrCreate(['customer_id' => $request->user()->id]);
        $patch = [];
        if (array_key_exists('bio', $data)) $patch['bio'] = $data['bio'];
        if (array_key_exists('services', $data)) $patch['proffi_services'] = $data['services'];
        if (array_key_exists('avatar', $data)) $patch['avatar'] = ['original' => $data['avatar'], 'thumbnail' => $data['avatar']];
        if (array_key_exists('city', $data)) $patch['proffi_city'] = $data['city'];
        if (array_key_exists('lat', $data)) $patch['proffi_lat'] = $data['lat'];
        if (array_key_exists('lng', $data)) $patch['proffi_lng'] = $data['lng'];
        if (array_key_exists('portfolio', $data)) {
            $socials = $profile->socials;
            if (is_string($socials)) {
                $socials = json_decode($socials, true) ?: [];
            }
            if (!is_array($socials)) {
                $socials = [];
            }
            $socials['treabo_portfolio'] = array_values(array_slice($data['portfolio'] ?? [], 0, 10));
            $patch['socials'] = $socials;
        }
        $profile->update($patch);
        return $this->publicUser($request->user()->fresh('profile'));
    }

    private function startRegisterPhoneOtp(string $phone, array $data)
    {
        if (Profile::where('contact', $phone)->exists()) {
            return response()->json(['detail' => 'Phone already registered'], 400);
        }

        $email = $data['email'] ?? $this->emailFromPhone($phone);
        if (User::where('email', $email)->exists()) {
            return response()->json(['detail' => 'Email already registered'], 400);
        }

        $sent = $this->dispatchTreaboPhoneOtp($phone);
        if (!$sent['ok']) {
            return response()->json(['detail' => $sent['detail'] ?? 'SMS send failed'], 502);
        }

        $this->cacheTreaboOtpContext($sent['otp_id'], [
            'purpose' => 'register',
            'phone' => $phone,
            'attempts' => 0,
            'registration' => [
                'name' => $data['name'],
                'password' => $data['password'],
                'role' => $data['role'],
                'email' => $data['email'] ?? null,
                'city' => $data['city'] ?? null,
            ],
        ]);

        return response()->json($this->treaboOtpSentPayload($phone, $sent['otp_id']));
    }

    private function startLoginPhoneOtp(string $phone, string $password, ?User $user = null)
    {
        if (!$user) {
            $profile = Profile::where('contact', $phone)->first();
            $user = $profile ? User::find($profile->customer_id) : null;
        }

        if (!$user || !Hash::check($password, $user->password)) {
            return response()->json(['detail' => 'Invalid phone or password'], 401);
        }

        if ($this->isPhoneVerified($user->profile)) {
            return $this->authResponse($user->load('profile'));
        }

        $sent = $this->dispatchTreaboPhoneOtp($phone);
        if (!$sent['ok']) {
            return response()->json(['detail' => $sent['detail'] ?? 'SMS send failed'], 502);
        }

        $this->cacheTreaboOtpContext($sent['otp_id'], [
            'purpose' => 'login',
            'phone' => $phone,
            'user_id' => $user->id,
            'attempts' => 0,
        ]);

        return response()->json($this->treaboOtpSentPayload($phone, $sent['otp_id']));
    }

    private function completeRegisterPhoneOtp(string $phone, array $registration)
    {
        if (Profile::where('contact', $phone)->exists()) {
            return response()->json(['detail' => 'Phone already registered'], 400);
        }

        $email = $registration['email'] ?? $this->emailFromPhone($phone);
        if (User::where('email', $email)->exists()) {
            return response()->json(['detail' => 'Email already registered'], 400);
        }

        $user = User::create([
            'name' => $registration['name'],
            'email' => $email,
            'password' => Hash::make($registration['password']),
            'is_active' => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->assignRole($user, $registration['role']);
        $user->profile()->create($this->profilePayload([
            'contact' => $phone,
            'bio' => null,
            'proffi_city' => $registration['city'] ?? null,
            'proffi_services' => [],
            'seller_id' => strtoupper(Str::random(8)),
            'phone_verified' => true,
            'phone_verified_at' => now(),
        ]));

        return $this->authResponse($user->fresh('profile'));
    }

    private function completeLoginPhoneOtp(string $phone, int $userId)
    {
        $user = User::find($userId);
        $profile = Profile::where('contact', $phone)->first();

        if (!$user || !$profile || (int) $profile->customer_id !== (int) $user->id) {
            return response()->json(['detail' => 'Invalid phone or code'], 400);
        }

        if (!$this->isPhoneVerified($profile)) {
            $profile->update([
                'phone_verified' => true,
                'phone_verified_at' => now(),
            ]);
        }

        return $this->authResponse($user->fresh('profile'));
    }

    private function isPhoneVerified(?Profile $profile): bool
    {
        return (bool) ($profile?->phone_verified ?? false);
    }

    private function sendOtp(User $user)
    {
        $code = (string) random_int(100000, 999999);
        Cache::put("proffi_otp:" . Str::lower($user->email), $code, now()->addMinutes(10));
        $payload = ['status' => 'otp_sent', 'email' => $user->email];
        if (app()->environment('local')) {
            $payload['dev_otp'] = $code;
        }
        return $payload;
    }

    private function authResponse(User $user)
    {
        return ['token' => $user->createToken('proffi')->plainTextToken, 'user' => $this->publicUser($user)];
    }

    private function profilePayload(array $values): array
    {
        return collect($values)
            ->filter(fn ($value, string $column) => in_array($column, ['contact', 'bio'], true) || Schema::hasColumn('user_profiles', $column))
            ->all();
    }

    private function assignRole(User $user, string $role): void
    {
        $permission = $role === 'specialist' ? Permission::STORE_OWNER : Permission::CUSTOMER;
        SpatiePermission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        $user->givePermissionTo($permission);
        if ($permission === Permission::STORE_OWNER) {
            SpatiePermission::firstOrCreate(['name' => Permission::CUSTOMER, 'guard_name' => 'api']);
            $user->givePermissionTo(Permission::CUSTOMER);
        }
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', trim($phone));
        if (strlen($digits) === 11 && str_starts_with($digits, '373')) {
            return '+' . $digits;
        }
        if (strlen($digits) === 8) {
            return '+373' . $digits;
        }
        if (strlen($digits) === 11 && (str_starts_with($digits, '8') || str_starts_with($digits, '7'))) {
            return '+7' . substr($digits, 1);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '+7' . $digits;
        }
        return preg_replace('/[^\d+]/', '', trim($phone));
    }

    private function validateOAuthProvider(string $provider): void
    {
        if (!in_array($provider, ['yandex', 'google'], true)) {
            abort(404);
        }
    }

    private function decodeOAuthState(?string $state): array
    {
        if (!$state) return [];
        $decoded = json_decode(base64_decode($state, true) ?: '', true);
        return is_array($decoded) ? $decoded : [];
    }

    private function isAllowedOAuthReturnUrl(string $url): bool
    {
        if (str_starts_with($url, 'proffi://')) return true;
        $host = parse_url($url, PHP_URL_HOST);
        return in_array($host, [
            '127.0.0.1',
            'localhost',
            'treabo.md',
            'www.treabo.md',
            'seller.treabo.md',
            'api.treabo.md',
            'treabo.ru',
            'www.treabo.ru',
            'seller.treabo.ru',
            'api.treabo.ru',
        ], true);
    }

    private function emailFromPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?: Str::random(8);
        return "phone-$digits@proffi.local";
    }
}
