<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MoldovaLocation;
use Illuminate\Http\Request;

class MoldovaLocationController extends Controller
{
    public function search(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'type' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'locale' => ['nullable', 'in:ru,ro'],
        ]);

        $locale = $data['locale'] ?? 'ro';
        $limit = (int) ($data['limit'] ?? 12);

        $query = MoldovaLocation::query()->active()->ordered();

        if (!empty($data['type'])) {
            $types = array_values(array_filter(array_map('trim', explode(',', $data['type']))));
            if ($types) {
                $query->whereIn('type', $types);
            }
        }

        if (!empty($data['district'])) {
            $district = $data['district'];
            $query->where(function ($inner) use ($district) {
                $inner->where('district_ro', 'like', '%' . $district . '%')
                    ->orWhere('district_ru', 'like', '%' . $district . '%');
            });
        }

        if (!empty($data['q'])) {
            $query->search($data['q']);
        }

        $locations = $query->limit($limit)->get();

        return response()->json([
            'success' => true,
            'data' => $locations->map(fn (MoldovaLocation $location) => $this->formatLocation($location, $locale))->values(),
        ]);
    }

    private function formatLocation(MoldovaLocation $location, string $locale): array
    {
        $districtRo = $location->district_ro;
        $districtRu = $location->district_ru;
        $nameRo = $location->name_ro;
        $nameRu = $location->name_ru ?: $location->name_ro;

        return [
            'id' => $location->id,
            'cuatm_code' => $location->cuatm_code,
            'name' => $locale === 'ru' ? $nameRu : $nameRo,
            'name_ro' => $nameRo,
            'name_ru' => $nameRu,
            'ascii_name' => $location->ascii_name,
            'district' => $locale === 'ru' ? ($districtRu ?: $districtRo) : ($districtRo ?: $districtRu),
            'district_ro' => $districtRo,
            'district_ru' => $districtRu,
            'type' => $location->type,
            'lat' => $location->lat,
            'lng' => $location->lng,
        ];
    }
}
