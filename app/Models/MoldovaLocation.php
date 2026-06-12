<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MoldovaLocation extends Model
{
    protected $fillable = [
        'cuatm_code',
        'parent_cuatm_code',
        'name_ro',
        'name_ru',
        'name_en',
        'ascii_name',
        'district_ro',
        'district_ru',
        'region_ro',
        'region_ru',
        'type',
        'level',
        'lat',
        'lng',
        'aliases',
        'search_text',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'aliases' => 'array',
        'is_active' => 'boolean',
        'lat' => 'float',
        'lng' => 'float',
        'level' => 'integer',
        'sort_order' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name_ro');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim($term ?? '');
        if ($term === '') {
            return $query;
        }

        $normalized = self::normalizeSearchText($term);
        if ($normalized === '') {
            return $query;
        }

        return $query->where('search_text', 'like', '%' . $normalized . '%');
    }

    public static function normalizeSearchText(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        if ($value === '') {
            return '';
        }

        $value = str_replace(
            ['ș', 'ş', 'ț', 'ţ', 'ă', 'â', 'î'],
            ['s', 's', 't', 't', 'a', 'i', 'i'],
            $value
        );

        if (!preg_match('/[\x{0400}-\x{04FF}]/u', $value)) {
            $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($transliterated) && trim($transliterated) !== '') {
                $value = $transliterated;
            }
        }

        $value = preg_replace('/[^a-z0-9\x{0400}-\x{04ff}\s-]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    public static function buildSearchText(array $parts): string
    {
        $normalized = collect($parts)
            ->flatten()
            ->filter(fn ($part) => is_string($part) && trim($part) !== '')
            ->map(fn (string $part) => self::normalizeSearchText($part))
            ->filter()
            ->unique()
            ->implode(' ');

        return trim($normalized);
    }
}
