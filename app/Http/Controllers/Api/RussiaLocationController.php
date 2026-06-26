<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RussiaLocation;
use App\Services\GeoLocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RussiaLocationController extends Controller
{
    public function search(Request $request, GeoLocationService $geoLocationService)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'region' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:100'],
        ]);

        $queryText = trim((string) ($data['q'] ?? ''));
        $limit = (int) ($data['limit'] ?? 12);
        $locations = collect();

        try {
            if (Schema::hasTable('russia_locations')) {
                $query = RussiaLocation::query()->active()->ordered();

                if (!empty($data['region'])) {
                    $query->where('region', 'like', '%' . $data['region'] . '%');
                }

                if (!empty($data['type'])) {
                    $types = array_values(array_filter(array_map('trim', explode(',', $data['type']))));
                    if ($types) {
                        $query->whereIn('type', $types);
                    }
                }

                if ($queryText !== '') {
                    $query->search($queryText);
                }

                $locations = $query->limit($limit)->get()->map(
                    fn (RussiaLocation $location) => $this->formatLocation($location)
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Local Russia locations search failed', ['message' => $e->getMessage()]);
        }

        $source = 'local';
        if ($locations->isEmpty() && mb_strlen($queryText) >= 2 && config('services.dadata.city_fallback_enabled', true)) {
            $source = 'dadata';
            $cacheKey = 'russia-location-search:' . sha1(mb_strtolower($queryText) . ':' . $limit);
            $locations = collect(Cache::remember($cacheKey, now()->addDay(), function () use ($geoLocationService, $queryText, $limit) {
                $result = $geoLocationService->searchCities($queryText);

                return collect($result['cities'] ?? [])
                    ->take($limit)
                    ->map(fn (array $city) => [
                        'id' => ($city['fias_id'] ?? null) ?: (($city['kladr_id'] ?? null) ?: $city['name']),
                        'geoname_id' => null,
                        'name' => $city['name'],
                        'name_ru' => $city['name'],
                        'ascii_name' => null,
                        'region' => $city['region'] ?? null,
                        'region_ru' => $city['region'] ?? null,
                        'type' => 'city',
                        'population' => null,
                        'lat' => $city['lat'] ?? null,
                        'lng' => $city['lon'] ?? null,
                        'fias_id' => $city['fias_id'] ?? null,
                        'source' => 'dadata',
                    ])
                    ->values()
                    ->all();
            }));
        }

        return response()->json([
            'success' => true,
            'source' => $source,
            'data' => $locations->values(),
        ]);
    }

    private function formatLocation(RussiaLocation $location): array
    {
        return [
            'id' => $location->id,
            'geoname_id' => $location->geoname_id,
            'name' => $location->name,
            'name_ru' => $location->name,
            'ascii_name' => $location->ascii_name,
            'region' => $location->region,
            'region_ru' => $location->region,
            'type' => $location->type,
            'population' => $location->population,
            'lat' => $location->lat,
            'lng' => $location->lng,
            'source' => 'geonames',
        ];
    }
}
