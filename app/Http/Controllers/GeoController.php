<?php

namespace App\Http\Controllers;

use App\Services\GeoLocationService;
use App\Support\GeoAddressResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\UserAddress;

class GeoController extends Controller
{
    public function __construct(
        private readonly GeoLocationService $geoService,
    ) {
    }

    public function detectByIp(Request $request)
    {
        try {
            $ip = $request->ip();
            if ($ip === '127.0.0.1' || $ip === '::1') {
                $ip = '95.24.18.3';
            }

            $coordinates = $request->input('coordinates');
            $wifi = $request->input('wifi', []);
            $cell = $request->input('cell', []);

            $location = $this->geoService->getLocationByIp($ip, $wifi, $cell, $coordinates);

            if (!$location || empty($location)) {
                return response()->json(GeoAddressResponse::ipHint([
                    'city' => null,
                    'region' => null,
                    'country' => 'Россия',
                    'source' => 'default',
                ]));
            }

            return response()->json(GeoAddressResponse::ipHint($location));
        } catch (\Throwable $e) {
            Log::error('GeoController::detectByIp', ['message' => $e->getMessage()]);

            return response()->json(GeoAddressResponse::ipHint([
                'city' => null,
                'region' => null,
                'country' => 'Россия',
                'source' => 'error',
            ]));
        }
    }

    public function reverseGeocode(Request $request)
    {
        $lat = $request->input('lat');
        $lng = $request->input('lng', $request->input('lon'));

        if ($lat === null || $lng === null) {
            return response()->json([
                'error' => 'Latitude and longitude are required',
                'message' => 'Параметры lat и lng обязательны',
            ], 400);
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return response()->json(['error' => 'Invalid coordinates'], 422);
        }

        $accuracy = $request->has('accuracy') ? (float) $request->input('accuracy') : null;

        $result = $this->geoService->reverseGeocode($lat, $lng);

        if (!$result) {
            return response()->json([
                'city' => null,
                'region' => null,
                'country' => null,
                'address' => null,
                'full_address' => null,
                'lat' => $lat,
                'lng' => $lng,
                'source' => 'browser',
                'provider' => 'browser',
                'accuracy' => $accuracy,
                'needs_confirmation' => true,
            ]);
        }

        if ($accuracy !== null) {
            $result['accuracy'] = $accuracy;
        }
        $result['source'] = 'browser';
        $result['provider'] = $result['provider'] ?? 'dadata';

        return response()->json(GeoAddressResponse::fromLocationArray($result, true));
    }

    public function searchAddresses(Request $request)
    {
        $query = trim((string) $request->query('query', $request->query('q', '')));
        $city = trim((string) $request->query('city', ''));
        $count = min((int) $request->query('count', 10), 20);

        if (mb_strlen($query) < 2) {
            return response()->json([
                'addresses' => [],
                'total' => 0,
                'message' => 'Запрос должен содержать минимум 2 символа',
            ]);
        }

        $searchQuery = $city && !str_contains(mb_strtolower($query), mb_strtolower($city))
            ? "{$city}, {$query}"
            : $query;

        $result = $this->geoService->searchAddresses($searchQuery, $count, $city ?: null);

        if (isset($result['error'])) {
            Log::warning('GeoController::searchAddresses', [
                'query' => $searchQuery,
                'error' => $result['error'],
            ]);

            return response()->json([
                'error' => $result['error'],
                'addresses' => [],
                'total' => 0,
            ], 500);
        }

        $addresses = array_map(
            static fn (array $item) => GeoAddressResponse::fromDaDataSuggestion($item),
            $result['addresses'] ?? []
        );

        return response()->json([
            'addresses' => $addresses,
            'total' => count($addresses),
            'query' => $query,
        ]);
    }

    public function saveAddress(Request $request)
    {
        $data = $request->validate([
            'city' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:512'],
            'full_address' => ['nullable', 'string', 'max:512'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'lon' => ['nullable', 'numeric', 'between:-180,180'],
            'fias_id' => ['nullable', 'string', 'max:64'],
            'kladr_id' => ['nullable', 'string', 'max:64'],
            'source' => ['nullable', 'string', 'max:64'],
        ]);

        $lng = $data['lng'] ?? $data['lon'] ?? null;
        $fullAddress = ($data['full_address'] ?? null) ?: ($data['address'] ?? null);

        if (empty($data['city']) && !$fullAddress) {
            return response()->json(['error' => 'Адрес не указан'], 400);
        }

        $normalized = GeoAddressResponse::fromLocationArray([
            'city' => $data['city'] ?? null,
            'region' => $data['region'] ?? null,
            'country' => $data['country'] ?? 'Россия',
            'address' => $data['address'] ?? $fullAddress,
            'full_address' => $fullAddress,
            'lat' => $data['lat'] ?? null,
            'lng' => $lng,
            'fias_id' => $data['fias_id'] ?? null,
            'kladr_id' => $data['kladr_id'] ?? null,
            'source' => $data['source'] ?? 'manual',
            'provider' => 'dadata',
        ], false);
        $normalized['needs_confirmation'] = false;
        $normalized['source'] = 'manual';

        $user = $this->resolveUser($request);

        if (!$user) {
            session(['user_address' => array_merge($normalized, ['saved_at' => now()->toIso8601String()])]);

            return response()->json([
                'success' => true,
                'address' => $normalized,
            ]);
        }

        try {
            UserAddress::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'type' => 'user_selected',
                    'source' => 'user_selected',
                ],
                [
                    'title' => 'Мой адрес',
                    'city' => $normalized['city'],
                    'region' => $normalized['region'],
                    'country' => $normalized['country'] ?? 'Россия',
                    'address' => $normalized['address'] ?: $normalized['city'],
                    'full_address' => $normalized['full_address'],
                    'latitude' => $normalized['lat'],
                    'longitude' => $normalized['lng'],
                    'kladr_id' => $normalized['kladr_id'],
                    'fias_id' => $normalized['fias_id'],
                    'is_default' => true,
                    'is_active' => true,
                ]
            );

            Cache::put("user_address_{$user->id}", $normalized, 86400 * 30);
        } catch (\Throwable $e) {
            Log::warning('GeoController::saveAddress db error', ['message' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'address' => $normalized,
        ]);
    }

    public function getSavedAddress(Request $request)
    {
        $user = $this->resolveUser($request);

        if ($user) {
            $userAddress = UserAddress::where('user_id', $user->id)
                ->where('type', 'user_selected')
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderByDesc('updated_at')
                ->first();

            if ($userAddress) {
                $normalized = GeoAddressResponse::fromLocationArray([
                    'city' => $userAddress->city,
                    'region' => $userAddress->region,
                    'country' => $userAddress->country,
                    'address' => $userAddress->address,
                    'full_address' => $userAddress->full_address ?: $userAddress->address,
                    'lat' => $userAddress->latitude,
                    'lng' => $userAddress->longitude,
                    'fias_id' => $userAddress->fias_id,
                    'kladr_id' => $userAddress->kladr_id,
                    'source' => 'manual',
                ], false);

                return response()->json(['address' => $normalized]);
            }
        }

        $sessionAddress = session('user_address');
        if (is_array($sessionAddress)) {
            return response()->json(['address' => $sessionAddress]);
        }

        return response()->json(['address' => null]);
    }

    private function resolveUser(Request $request)
    {
        foreach (['sanctum', 'api', 'web'] as $guard) {
            try {
                $user = auth($guard)->user();
                if ($user) {
                    return $user;
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        if ($request->bearerToken()) {
            try {
                $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken());

                return $token?->tokenable;
            } catch (\Throwable) {
                // ignore
            }
        }

        return $request->user();
    }
}
