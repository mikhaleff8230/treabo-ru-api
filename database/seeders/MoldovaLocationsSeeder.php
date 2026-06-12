<?php

namespace Database\Seeders;

use App\Models\MoldovaLocation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class MoldovaLocationsSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/moldova_locations.json');

        if (!File::exists($path)) {
            $this->command?->error('Missing seed file: ' . $path);
            return;
        }

        $rows = json_decode(File::get($path), true);
        if (!is_array($rows)) {
            $this->command?->error('Invalid JSON in moldova_locations.json');
            return;
        }

        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['cuatm_code'])) {
                continue;
            }

            $aliases = $row['aliases'] ?? [];
            if (!is_array($aliases)) {
                $aliases = [];
            }

            $payload = [
                'parent_cuatm_code' => $row['parent_cuatm_code'] ?? null,
                'name_ro' => $row['name_ro'],
                'name_ru' => $row['name_ru'] ?? null,
                'name_en' => $row['name_en'] ?? ($row['ascii_name'] ?? null),
                'ascii_name' => $row['ascii_name'] ?? null,
                'district_ro' => $row['district_ro'] ?? null,
                'district_ru' => $row['district_ru'] ?? null,
                'region_ro' => $row['region_ro'] ?? null,
                'region_ru' => $row['region_ru'] ?? null,
                'type' => $row['type'] ?? 'city',
                'level' => $row['level'] ?? null,
                'lat' => $row['lat'] ?? null,
                'lng' => $row['lng'] ?? null,
                'aliases' => $aliases,
                'is_active' => true,
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];

            $payload['search_text'] = MoldovaLocation::buildSearchText([
                $payload['name_ro'],
                $payload['name_ru'],
                $payload['name_en'],
                $payload['ascii_name'],
                $payload['district_ro'],
                $payload['district_ru'],
                $payload['region_ro'],
                $payload['region_ru'],
                $aliases,
            ]);

            MoldovaLocation::query()->updateOrCreate(
                ['cuatm_code' => $row['cuatm_code']],
                $payload
            );
        }
    }
}
