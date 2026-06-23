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
        ]);

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
                $inner->where('district_ru', 'like', '%' . $district . '%');
            });
        }

        if (!empty($data['q'])) {
            $query->search($data['q']);
        }

        $locations = $query->limit($limit)->get();

        return response()->json([
            'success' => true,
            'data' => $locations->map(fn (MoldovaLocation $location) => $this->formatLocation($location))->values(),
        ]);
    }

    private function formatLocation(MoldovaLocation $location): array
    {
        $districtRu = $location->district_ru;
        $nameRu = $location->name_ru;

        return [
            'id' => $location->id,
            'cuatm_code' => $location->cuatm_code,
            'name' => $nameRu,
            'name_ru' => $nameRu,
            'ascii_name' => $location->ascii_name,
            'district' => $districtRu,
            'district_ru' => $districtRu,
            'type' => $location->type,
            'lat' => $location->lat,
            'lng' => $location->lng,
        ];
    }
}
