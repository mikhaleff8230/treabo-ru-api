<?php

namespace App\Services\Proffi;

use App\Models\RussiaLocation;

class TaskLocationService
{
    public function normalize(array $data): array
    {
        $location = $this->resolveLocation($data);
        if (!$location) return $data;

        $data['location_id'] = $location->id;
        $data['city'] = $location->name;
        if (!isset($data['lat']) || $data['lat'] === null || $data['lat'] === '') $data['lat'] = $location->lat;
        if (!isset($data['lng']) || $data['lng'] === null || $data['lng'] === '') $data['lng'] = $location->lng;

        return $data;
    }

    private function resolveLocation(array $data): ?RussiaLocation
    {
        if (!empty($data['location_id'])) return RussiaLocation::active()->find($data['location_id']);

        $city = RussiaLocation::normalizeSearchText((string) ($data['city'] ?? ''));
        if ($city === '') return null;

        return RussiaLocation::active()->whereNotNull('lat')->whereNotNull('lng')
            ->where(function ($query) use ($city) {
                $query->whereRaw('LOWER(name) = ?', [$city])->orWhereRaw('LOWER(ascii_name) = ?', [$city]);
            })->orderByDesc('population')->first()
            ?? RussiaLocation::active()->search($city)->whereNotNull('lat')->whereNotNull('lng')
                ->orderByDesc('population')->first();
    }
}
