<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RussiaLocation extends Model
{
    protected $fillable = [
        'geoname_id',
        'name',
        'ascii_name',
        'alternate_names',
        'region',
        'admin1_code',
        'feature_code',
        'type',
        'population',
        'lat',
        'lng',
        'timezone',
        'search_text',
        'is_active',
        'sort_order',
        'source_updated_at',
    ];

    protected $casts = [
        'geoname_id' => 'integer',
        'population' => 'integer',
        'lat' => 'float',
        'lng' => 'float',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'source_updated_at' => 'date',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = self::normalizeSearchText($term ?? '');
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($term) {
            $inner
                ->where('name', 'like', $term . '%')
                ->orWhere('ascii_name', 'like', $term . '%')
                ->orWhere('region', 'like', $term . '%')
                ->orWhere('search_text', 'like', '%' . $term . '%');
        });
    }

    public static function normalizeSearchText(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = str_replace('ё', 'е', $value);
        $value = preg_replace('/[^a-z0-9\x{0400}-\x{04ff}\s-]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    public static function buildSearchText(array $parts): string
    {
        return collect($parts)
            ->flatten()
            ->filter(fn ($part) => is_string($part) && trim($part) !== '')
            ->map(fn (string $part) => self::normalizeSearchText($part))
            ->filter()
            ->unique()
            ->implode(' ');
    }
}
