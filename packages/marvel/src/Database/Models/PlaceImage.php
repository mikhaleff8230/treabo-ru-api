<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PlaceImage extends Model
{
    protected $fillable = [
        'place_id',
        'url',
        'sort_order',
        'is_cover',
        'thumbnail_url',
        'width',
        'height',
        'file_size',
        'mime_type',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_cover' => 'boolean',
        'width' => 'integer',
        'height' => 'integer',
        'file_size' => 'integer',
    ];

    public function place()
    {
        return $this->belongsTo(Place::class);
    }

    /**
     * Получить полный URL изображения
     */
    public function getImageUrlAttribute()
    {
        return $this->buildFullUrl($this->url);
    }

    /**
     * Получить полный URL thumbnail
     */
    public function getThumbnailUrlAttribute($value)
    {
        if ($value) {
            return $this->buildFullUrl($value);
        }
        
        // Fallback к оригинальному изображению если thumbnail нет
        return $this->buildFullUrl($this->url);
    }

    /**
     * Построить полный URL
     */
    private function buildFullUrl($path)
    {
        if (empty($path)) return null;
        
        // Если уже полный URL
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $base = env('ASSETS_BASE_URL');
        
        // Отладка для первого изображения
        if ($this->place_id == 1) {
            \Log::info('PlaceImage::buildFullUrl - отладка', [
                'place_id' => $this->place_id,
                'input_path' => $path,
                'env_assets_base_url' => $base,
                'path_type' => gettype($path),
                'path_length' => is_string($path) ? strlen($path) : 'N/A'
            ]);
        }
        
        if ($base) {
            if (str_starts_with($path, '/storage/')) {
                $path = substr($path, strlen('/storage/'));
            }
            $fullUrl = rtrim($base, '/') . '/' . ltrim($path, '/');
            
            // Отладка для первого изображения
            if ($this->place_id == 1) {
                \Log::info('PlaceImage::buildFullUrl - результат', [
                    'place_id' => $this->place_id,
                    'input_path' => $path,
                    'base' => $base,
                    'full_url' => $fullUrl
                ]);
                }
            
            return $fullUrl;
        }

        // Попробуем построить URL через настроенное хранилище S3
        try {
            if (config('filesystems.disks.s3.bucket')) {
                return Storage::disk('s3')->url(ltrim($path, '/'));
            }
        } catch (\Throwable $exception) {
            // Игнорируем и используем старый fallback
        }

        // Fallback: старое поведение через домен API
        $baseUrl = rtrim(config('app.url'), '/');
        if (str_starts_with($path, '/storage/')) {
            return $baseUrl . $path;
        }
        return $baseUrl . '/storage/' . ltrim($path, '/');
    }
}
