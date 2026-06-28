<?php

namespace App\Support;

class GeoAddressResponse
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function fromLocationArray(array $data, bool $needsConfirmation = false): array
    {
        $lat = self::toFloat($data['lat'] ?? $data['latitude'] ?? null);
        $lng = self::toFloat($data['lng'] ?? $data['lon'] ?? $data['longitude'] ?? null);
        $source = (string) ($data['source'] ?? 'unknown');
        $provider = self::resolveProvider($source, $data['provider'] ?? null);

        $city = $data['city'] ?? null;
        $region = $data['region'] ?? $data['state_name'] ?? $data['state'] ?? null;
        $country = $data['country'] ?? null;
        $address = $data['address'] ?? null;
        $fullAddress = $data['full_address'] ?? $data['unrestricted_value'] ?? $address;

        if (!$address && $fullAddress && $city) {
            $address = self::stripCityFromAddress((string) $fullAddress, (string) $city);
        }

        return [
            'city' => $city,
            'region' => $region,
            'country' => $country,
            'address' => $address,
            'full_address' => $fullAddress,
            'lat' => $lat,
            'lng' => $lng,
            'fias_id' => $data['fias_id'] ?? $data['city_fias_id'] ?? null,
            'kladr_id' => $data['kladr_id'] ?? $data['city_kladr_id'] ?? null,
            'source' => self::normalizeSource($source),
            'provider' => $provider,
            'accuracy' => isset($data['accuracy']) ? (float) $data['accuracy'] : null,
            'needs_confirmation' => $needsConfirmation,
        ];
    }

    /**
     * @param array<string, mixed> $addressItem
     */
    public static function fromDaDataSuggestion(array $addressItem, string $source = 'dadata'): array
    {
        return self::fromLocationArray([
            'city' => $addressItem['city'] ?? null,
            'region' => $addressItem['region_with_type'] ?? $addressItem['region'] ?? null,
            'country' => $addressItem['country'] ?? 'Россия',
            'address' => $addressItem['value'] ?? $addressItem['address'] ?? null,
            'full_address' => $addressItem['value'] ?? $addressItem['full_name'] ?? null,
            'lat' => $addressItem['lat'] ?? null,
            'lng' => $addressItem['lon'] ?? null,
            'fias_id' => $addressItem['fias_id'] ?? null,
            'kladr_id' => $addressItem['kladr_id'] ?? null,
            'source' => $source,
            'provider' => 'dadata',
        ], true);
    }

    /**
     * IP-based hint — approximate city/region only.
     *
     * @param array<string, mixed> $location
     */
    public static function ipHint(array $location): array
    {
        $normalized = self::fromLocationArray($location, true);
        $normalized['address'] = null;
        $normalized['full_address'] = null;
        $normalized['needs_confirmation'] = true;

        return $normalized;
    }

    private static function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $float = (float) $value;

        return is_finite($float) ? $float : null;
    }

    private static function normalizeSource(string $source): string
    {
        return match (true) {
            str_contains($source, 'browser'),
            str_contains($source, 'html5'),
            str_contains($source, 'gps') => 'browser',
            str_contains($source, 'dadata') => 'dadata',
            str_contains($source, 'maxmind') => 'maxmind',
            str_contains($source, 'yandex') => 'yandex',
            str_contains($source, 'user_selected'),
            str_contains($source, 'manual') => 'manual',
            str_contains($source, 'cache') => 'cache',
            default => $source,
        };
    }

    private static function resolveProvider(string $source, mixed $explicit): ?string
    {
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $normalized = self::normalizeSource($source);

        return in_array($normalized, ['dadata', 'maxmind', 'yandex', 'browser'], true)
            ? $normalized
            : null;
    }

    private static function stripCityFromAddress(string $fullAddress, string $city): string
    {
        $parts = array_map('trim', explode(',', $fullAddress));
        $filtered = array_values(array_filter(
            $parts,
            static fn (string $part) => mb_strtolower($part) !== mb_strtolower($city)
        ));

        return $filtered ? implode(', ', $filtered) : $fullAddress;
    }
}
