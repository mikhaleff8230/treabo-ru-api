<?php

namespace App\Console\Commands;

use App\Models\RussiaLocation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

class ImportRussiaLocations extends Command
{
    protected $signature = 'locations:import-russia
        {source? : Local RU.txt/RU.zip path or URL}
        {--alternate-source= : Local or remote GeoNames alternate-names RU.txt/RU.zip}
        {--min-population=500 : Minimum population for ordinary settlements}
        {--all : Import every populated-place row from GeoNames}
        {--truncate : Empty the table before importing}';

    protected $description = 'Import Russian cities and settlements from the open GeoNames RU dump';

    private const DEFAULT_SOURCE = 'https://download.geonames.org/export/dump/RU.zip';
    private const DEFAULT_ALTERNATE_SOURCE = 'https://download.geonames.org/export/dump/alternatenames/RU.zip';
    private const ALLOWED_FEATURE_CODES = [
        'PPL', 'PPLA', 'PPLA2', 'PPLA3', 'PPLA4', 'PPLA5', 'PPLC', 'PPLG', 'PPLL', 'PPLS',
    ];

    public function handle(): int
    {
        if (!Schema::hasTable('russia_locations')) {
            $this->error('Table russia_locations does not exist. Run php artisan migrate first.');
            return self::FAILURE;
        }

        $source = (string) ($this->argument('source') ?: self::DEFAULT_SOURCE);
        $alternateSource = (string) ($this->option('alternate-source') ?: '');
        if ($alternateSource === '' && filter_var($source, FILTER_VALIDATE_URL)) {
            $alternateSource = self::DEFAULT_ALTERNATE_SOURCE;
        }
        $minPopulation = max(0, (int) $this->option('min-population'));

        try {
            [$dataPath, $temporaryDirectory] = $this->prepareSource($source);
            $temporaryDirectories = array_filter([$temporaryDirectory]);
            $russianNames = [];

            if ($alternateSource !== '') {
                [$alternatePath, $alternateTemporaryDirectory] = $this->prepareSource($alternateSource);
                $temporaryDirectories[] = $alternateTemporaryDirectory;
                $russianNames = $this->readRussianNames($alternatePath);
                $this->line('Loaded ' . count($russianNames) . ' Russian names from GeoNames alternates.');
            } else {
                $this->warn('No alternate-names source supplied; some names may use GeoNames transliteration.');
            }

            $regions = $this->readRegions($dataPath, $russianNames);

            if ($this->option('truncate')) {
                DB::table('russia_locations')->truncate();
            }

            $imported = $this->importRows($dataPath, $regions, $russianNames, $minPopulation, (bool) $this->option('all'));
            $this->info("Imported {$imported} Russian locations from GeoNames.");
            $this->line('Source: ' . $source);
            $this->line('License: GeoNames data is CC BY 4.0 (https://www.geonames.org/).');

            foreach (array_unique(array_filter($temporaryDirectories)) as $directory) {
                $this->deleteDirectory($directory);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function prepareSource(string $source): array
    {
        $temporaryDirectory = null;
        $path = $source;

        if (filter_var($source, FILTER_VALIDATE_URL)) {
            $temporaryDirectory = storage_path('app/tmp/russia-locations-' . uniqid());
            if (!is_dir($temporaryDirectory) && !mkdir($temporaryDirectory, 0775, true) && !is_dir($temporaryDirectory)) {
                throw new RuntimeException('Cannot create temporary import directory.');
            }

            $path = $temporaryDirectory . DIRECTORY_SEPARATOR . basename(parse_url($source, PHP_URL_PATH) ?: 'RU.zip');
            $this->info('Downloading ' . $source . ' ...');
            $response = Http::timeout(180)->withOptions(['sink' => $path])->get($source);
            if (!$response->successful()) {
                throw new RuntimeException('GeoNames download failed with HTTP ' . $response->status());
            }
        } elseif (!is_file($path)) {
            throw new RuntimeException('Import source not found: ' . $path);
        }

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'zip') {
            return [$path, $temporaryDirectory];
        }

        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required to import RU.zip.');
        }

        $extractDirectory = $temporaryDirectory ?: storage_path('app/tmp/russia-locations-' . uniqid());
        if (!is_dir($extractDirectory) && !mkdir($extractDirectory, 0775, true) && !is_dir($extractDirectory)) {
            throw new RuntimeException('Cannot create archive extraction directory.');
        }
        $temporaryDirectory = $extractDirectory;

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Cannot open GeoNames ZIP archive.');
        }
        $zip->extractTo($extractDirectory);
        $zip->close();

        $dataPath = $extractDirectory . DIRECTORY_SEPARATOR . 'RU.txt';
        if (!is_file($dataPath)) {
            throw new RuntimeException('RU.txt is missing from the GeoNames archive.');
        }

        return [$dataPath, $temporaryDirectory];
    }

    private function readRussianNames(string $path): array
    {
        $names = [];
        $preferred = [];
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('Cannot read GeoNames alternate-names file.');
        }

        while (($line = fgets($handle)) !== false) {
            $columns = explode("\t", rtrim($line, "\r\n"));
            if (($columns[2] ?? '') !== 'ru' || empty($columns[1]) || empty($columns[3])) {
                continue;
            }

            $geonameId = (int) $columns[1];
            $isPreferred = ($columns[4] ?? '') === '1';
            $isHistoric = ($columns[7] ?? '') === '1';
            if ($isHistoric || (isset($preferred[$geonameId]) && !$isPreferred)) {
                continue;
            }

            if (!isset($names[$geonameId]) || $isPreferred) {
                $names[$geonameId] = trim($columns[3]);
                $preferred[$geonameId] = $isPreferred;
            }
        }
        fclose($handle);

        return $names;
    }

    private function readRegions(string $path, array $russianNames): array
    {
        $regions = [];
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('Cannot read GeoNames data file.');
        }

        while (($line = fgets($handle)) !== false) {
            $columns = explode("\t", rtrim($line, "\r\n"));
            if (($columns[6] ?? null) === 'A' && ($columns[7] ?? null) === 'ADM1' && !empty($columns[10])) {
                $regions[$columns[10]] = $russianNames[(int) $columns[0]] ?? $this->bestRussianName(
                    $columns[1] ?: ($columns[2] ?? ''), $columns[2] ?? '', $columns[3] ?? ''
                );
            }
        }
        fclose($handle);

        return $regions;
    }

    private function importRows(string $path, array $regions, array $russianNames, int $minPopulation, bool $all): int
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('Cannot read GeoNames data file.');
        }

        $now = now();
        $batch = [];
        $imported = 0;

        while (($line = fgets($handle)) !== false) {
            $columns = explode("\t", rtrim($line, "\r\n"));
            if (($columns[6] ?? null) !== 'P') {
                continue;
            }

            $featureCode = $columns[7] ?? '';
            $population = (int) ($columns[14] ?? 0);
            $isAdminCentre = str_starts_with($featureCode, 'PPLA') || $featureCode === 'PPLC';

            if (!$all && (!in_array($featureCode, self::ALLOWED_FEATURE_CODES, true) || ($population < $minPopulation && !$isAdminCentre))) {
                continue;
            }

            $alternateNames = mb_strcut($columns[3] ?? '', 0, 60000, 'UTF-8');
            $region = $regions[$columns[10] ?? ''] ?? null;
            $name = $russianNames[(int) $columns[0]] ?? $this->bestRussianName(
                trim($columns[1] ?? ''), $columns[2] ?? '', $alternateNames
            );
            if ($name === '') {
                continue;
            }

            $batch[] = [
                'geoname_id' => (int) $columns[0],
                'name' => $name,
                'ascii_name' => $columns[2] ?: null,
                'alternate_names' => $alternateNames ?: null,
                'region' => $region,
                'admin1_code' => $columns[10] ?: null,
                'feature_code' => $featureCode ?: null,
                'type' => $this->locationType($featureCode, $population),
                'population' => max(0, $population),
                'lat' => is_numeric($columns[4] ?? null) ? (float) $columns[4] : null,
                'lng' => is_numeric($columns[5] ?? null) ? (float) $columns[5] : null,
                'timezone' => $columns[17] ?: null,
                'search_text' => mb_strcut(
                    RussiaLocation::buildSearchText([$name, $columns[1] ?? '', $columns[2] ?? '', $alternateNames, $region]),
                    0,
                    60000,
                    'UTF-8'
                ),
                'is_active' => true,
                'sort_order' => -min(max(0, $population), 2000000000),
                'source_updated_at' => $columns[18] ?: null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 500) {
                $this->upsert($batch);
                $imported += count($batch);
                $batch = [];
            }
        }
        fclose($handle);

        if ($batch) {
            $this->upsert($batch);
            $imported += count($batch);
        }

        return $imported;
    }

    private function upsert(array $rows): void
    {
        RussiaLocation::query()->upsert(
            $rows,
            ['geoname_id'],
            [
                'name', 'ascii_name', 'alternate_names', 'region', 'admin1_code', 'feature_code',
                'type', 'population', 'lat', 'lng', 'timezone', 'search_text', 'is_active',
                'sort_order', 'source_updated_at', 'updated_at',
            ]
        );
    }

    private function locationType(string $featureCode, int $population): string
    {
        if ($featureCode === 'PPLC') {
            return 'capital';
        }
        if (str_starts_with($featureCode, 'PPLA')) {
            return 'administrative_centre';
        }
        if ($population >= 12000) {
            return 'city';
        }
        if ($population >= 3000) {
            return 'town';
        }

        return 'settlement';
    }

    private function bestRussianName(string $fallback, string $asciiName, string $alternateNames): string
    {
        $candidates = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $alternateNames)),
            fn (string $name) => $name !== ''
                && mb_strlen($name, 'UTF-8') <= 120
                && preg_match('/^[А-Яа-яЁё\s-]+$/u', $name)
        )));

        if (!$candidates) {
            return $fallback;
        }

        $target = $this->normalizeLatin($asciiName ?: $fallback);
        usort($candidates, function (string $left, string $right) use ($target) {
            return $this->russianNameScore($left, $target) <=> $this->russianNameScore($right, $target);
        });

        return $candidates[0] ?? $fallback;
    }

    private function russianNameScore(string $name, string $target): int
    {
        $latin = $this->transliterateRussian($name);
        return levenshtein($latin, $target) + abs(strlen($latin) - strlen($target));
    }

    private function transliterateRussian(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = strtr($value, [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
            'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
            'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
            'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ]);

        return $this->normalizeLatin($value);
    }

    private function normalizeLatin(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? $value);
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $item) {
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
